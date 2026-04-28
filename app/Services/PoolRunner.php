<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Services\PeriodFreezeService;

/**
 * Integration glue between PoolCalculator (pure math) and the DB.
 *
 * Responsible for:
 *   - gathering monthly inputs (revenue, nominal head-counts, participants)
 *   - running PoolCalculator::distribute()
 *   - writing rows to `poolLog` when applyWrite=true
 *
 * Preview / write split mirrors the spec ✅Пул.md Part 2 — the operator
 * clicks «Рассчитать пул» after moderating the «Участвует» toggles;
 * the backend runs the same numbers in preview and then writes when
 * confirmed.
 */
class PoolRunner
{
    public function __construct(
        private readonly PoolCalculator $calculator,
        private readonly PeriodFreezeService $periodFreeze,
    ) {}

    /**
     * Compute (and optionally persist) the leader pool for a month.
     *
     * @return array{
     *   year:int, month:int, revenue:float, fund:float,
     *   shareValues:array<int,float>,
     *   participants:list<array{id:int,level:int,participates:bool,payoutRub:float,levelName:string,personName:string}>,
     *   totalPaid:float, totalForfeited:float,
     *   written:int, frozen?:bool
     * }
     */
    public function run(int $year, int $month, bool $applyWrite = false): array
    {
        // Заморозка: если период закрыт, запись в poolLog запрещена (spec ✅Пул.md
        // + «Закрытые периоды заморожены» в commission-spec). Preview-прогон
        // (applyWrite=false) разрешён — оператор может посмотреть на числа,
        // но не переписать уже утверждённое.
        if ($applyWrite && $this->periodFreeze->isFrozen($year, $month)) {
            return [
                'year' => $year, 'month' => $month,
                'revenue' => 0.0, 'fund' => 0.0,
                'shareValues' => [], 'participants' => [],
                'totalPaid' => 0.0, 'totalForfeited' => 0.0,
                'written' => 0, 'frozen' => true,
                'message' => sprintf('Период %02d.%d закрыт — пул не переписывается', $month, $year),
            ];
        }

        $revenue = $this->monthlyVatExclusiveRevenue($year, $month);

        // Leader levels 6..10 only.
        $leaderLevelIds = DB::table('status_levels')
            ->whereBetween('level', [PoolCalculator::LEADER_LEVEL_MIN, PoolCalculator::LEADER_LEVEL_MAX])
            ->pluck('id', 'level')
            ->toArray();
        if (empty($leaderLevelIds)) {
            return $this->emptyResult($year, $month, $revenue);
        }

        // Nominal head-counts per level: every consultant currently pinned
        // to this level, regardless of qualifying status.
        $nominalCounts = [];
        foreach ($leaderLevelIds as $level => $levelId) {
            $nominalCounts[$level] = DB::table('consultant')
                ->where('status_and_lvl', $levelId)
                ->whereNull('dateDeleted')
                ->count();
        }

        $shares = $this->calculator->shareValues($revenue, $nominalCounts);

        // Each candidate partner and their «Участвует» flag (default true
        // unless the operator un-checked them in pool_moderation for this month).
        $rows = DB::table('consultant as c')
            ->leftJoin('status_levels as sl', 'sl.id', '=', 'c.status_and_lvl')
            ->leftJoin('pool_moderation as pm', function ($j) use ($year, $month) {
                $j->on('pm.consultant', '=', 'c.id')
                  ->where('pm.year', $year)
                  ->where('pm.month', $month);
            })
            ->whereIn('sl.level', array_keys($leaderLevelIds))
            ->whereNull('c.dateDeleted')
            ->select([
                'c.id',
                'sl.level',
                'sl.title',
                'c.personName',
                'pm.participates',
            ])
            ->get();

        $partnerInputs = [];
        foreach ($rows as $r) {
            $partnerInputs[] = [
                'id' => (int) $r->id,
                'level' => (int) $r->level,
                'participates' => $r->participates === null ? true : (bool) $r->participates,
            ];
        }

        $distribution = $this->calculator->distribute($revenue, $nominalCounts, $partnerInputs);

        // Merge meta (name, level title) back in for the response.
        $metaById = $rows->keyBy('id');
        $participants = array_map(function ($p) use ($metaById) {
            $meta = $metaById[$p['id']] ?? null;
            return [
                'id' => $p['id'],
                'level' => $p['level'],
                'levelName' => $meta?->title ?? '',
                'personName' => $meta?->personName ?? '',
                'participates' => $metaById[$p['id']]?->participates === null
                    ? true
                    : (bool) $metaById[$p['id']]?->participates,
                'payoutRub' => $p['payoutRub'],
            ];
        }, $distribution);

        $totalPaid = array_sum(array_column($participants, 'payoutRub'));
        $fund = $revenue * PoolCalculator::POOL_PERCENT;
        $totalForfeited = max(0.0, ($fund * count($leaderLevelIds)) - $totalPaid);

        $written = 0;
        if ($applyWrite) {
            $written = $this->persist($year, $month, $participants);
        }

        return [
            'year' => $year,
            'month' => $month,
            'revenue' => $revenue,
            'fund' => $fund,
            'shareValues' => $shares,
            'participants' => $participants,
            'totalPaid' => $totalPaid,
            'totalForfeited' => $totalForfeited,
            'written' => $written,
        ];
    }

    /**
     * Ежемесячная выручка ДС без НДС.
     *
     * В transaction поле netRevenueRUB уже содержит чистую выручку ДС.
     * Однако legacy-импортированные строки часто хранят netRevenueRUB=NULL,
     * поэтому используем COALESCE: либо записанное значение, либо
     * расчёт «на лету» = amountRUB × dsCommissionPercentage / 105.
     * Для совсем grace-fallback (нет ни netRevenue, ни %DS) считаем
     * amountRUB / 1.05 (предполагая дефолтный VAT 5%).
     */
    private function monthlyVatExclusiveRevenue(int $year, int $month): float
    {
        $from = Carbon::create($year, $month, 1)->startOfMonth();
        $to = $from->copy()->endOfMonth();

        $sum = DB::selectOne(
            'SELECT COALESCE(
               SUM(COALESCE("netRevenueRUB",
                           CASE WHEN "dsCommissionPercentage" > 0
                                THEN "amountRUB" * "dsCommissionPercentage" / 105
                                ELSE "amountRUB" / 1.05
                           END)),
               0) AS revenue
               FROM transaction
              WHERE date >= ? AND date <= ?
                AND "deletedAt" IS NULL',
            [$from, $to]
        );

        return (float) ($sum->revenue ?? 0);
    }

    private function emptyResult(int $year, int $month, float $revenue): array
    {
        return [
            'year' => $year,
            'month' => $month,
            'revenue' => $revenue,
            'fund' => 0,
            'shareValues' => [],
            'participants' => [],
            'totalPaid' => 0,
            'totalForfeited' => 0,
            'written' => 0,
        ];
    }

    /**
     * Idempotent write: DELETE-then-INSERT for the target month so a
     * recalculation after moderator flip produces a clean state.
     */
    private function persist(int $year, int $month, array $participants): int
    {
        $from = Carbon::create($year, $month, 1)->startOfMonth();
        $to = $from->copy()->endOfMonth();

        DB::transaction(function () use ($participants, $from, $to) {
            // Wipe the previous calc for the same month.
            DB::table('poolLog')
                ->whereBetween('date', [$from, $to])
                ->delete();

            $rows = [];
            foreach ($participants as $p) {
                if ($p['payoutRub'] <= 0) continue;
                $rows[] = [
                    'consultant' => $p['id'],
                    'poolBonus' => $p['payoutRub'],
                    'networkGroupBonus' => null,
                    'date' => $from->toDateTimeString(),
                    'createdAt' => now(),
                ];
            }
            if ($rows) {
                DB::table('poolLog')->insert($rows);
            }
        });

        return count(array_filter($participants, fn ($p) => $p['payoutRub'] > 0));
    }
}
