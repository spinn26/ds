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

        // Если pool для этого месяца уже был зафиксирован (poolLog имеет
        // записи) — отдаём ИХ как источник правды, а не пересчитываем.
        // Это критично для исторических периодов: пересчёт по текущему
        // qualificationLog даёт другую картину, чем то, что было реально
        // выплачено в момент закрытия периода.
        if (! $applyWrite) {
            $logged = $this->participantsFromPoolLog($year, $month, $leaderLevelIds, $revenue);
            if ($logged !== null) {
                return $logged;
            }
        }

        // ИСТОЧНИК ПРАВДЫ ДЛЯ УРОВНЯ — qualificationLog за расчётный месяц.
        // Раньше брали current consultant.status_and_lvl, что давало неверные
        // данные для прошлых периодов (партнёр сейчас L3, а в феврале был L8 —
        // его пропускали из расчёта). Теперь резолвим level на каждого
        // консультанта через его последний qualificationLog в окне (start..end).
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = date('Y-m-t', strtotime($start));

        $perPartnerLevel = DB::table('qualificationLog as ql')
            ->join('status_levels as sl', function ($j) {
                // Берём наибольший из nominalLevel/calculationLevel —
                // как делает CalculatorController при resolveQual().
                $j->on('sl.id', '=', DB::raw('GREATEST(ql."nominalLevel", ql."calculationLevel")'));
            })
            ->whereBetween('ql.date', [$start, $end])
            ->whereNull('ql.dateDeleted')
            ->select([
                'ql.consultant',
                'sl.level',
                'sl.title',
                'sl.id as level_id',
            ])
            ->orderByDesc('ql.date')
            ->get()
            ->groupBy('consultant')
            // У одного консультанта может быть несколько qualificationLog
            // в месяц — берём максимальный уровень.
            ->map(fn ($g) => $g->sortByDesc('level')->first());

        // Активный? — глобальный флаг (терминированные исключаются всегда,
        // даже если у них есть qualificationLog за период).
        $consIds = $perPartnerLevel->keys()->all();
        $activeIds = $consIds ? DB::table('consultant')
            ->whereIn('id', $consIds)
            ->where('activity', 1)
            ->whereNull('dateDeleted')
            ->pluck('id')->flip() : collect();

        // Для UI участников используем фильтрованный набор. Также готовим
        // имена.
        $consultantMeta = $consIds ? DB::table('consultant')
            ->whereIn('id', $consIds)
            ->pluck('personName', 'id') : collect();

        // Pool moderation overrides ([year, month, consultant, participates]).
        $modByConsId = DB::table('pool_moderation')
            ->where('year', $year)->where('month', $month)
            ->whereIn('consultant', $consIds ?: [-1])
            ->pluck('participates', 'consultant');

        // Considered = active + level ≥ 6 + has qualificationLog за период
        $considered = [];
        $nominalCounts = array_fill_keys(array_keys($leaderLevelIds), 0);
        $rowsForUi = [];
        foreach ($perPartnerLevel as $consultantId => $row) {
            $level = (int) $row->level;
            if ($level < PoolCalculator::LEADER_LEVEL_MIN) continue;
            if (! $activeIds->has($consultantId)) continue;

            $nominalCounts[$level] = ($nominalCounts[$level] ?? 0) + 1;

            $considered[] = (int) $consultantId;
            $rowsForUi[] = (object) [
                'id' => (int) $consultantId,
                'level' => $level,
                'title' => $row->title,
                'personName' => $consultantMeta[$consultantId] ?? '—',
                'participates' => $modByConsId[$consultantId] ?? null,
            ];
        }

        $shares = $this->calculator->shareValues($revenue, $nominalCounts);

        $rows = collect($rowsForUi);

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

    /**
     * Снимок «как было» из poolLog. Возвращает null, если за период
     * нет ни одной записи — тогда вызывающий код пересчитывает заново.
     *
     * Для исторических периодов это единственный достоверный источник:
     * текущий qualificationLog мог быть пересчитан, current
     * consultant.status_and_lvl мог измениться. Реальный выплаченный
     * пул лежит только в poolLog.
     */
    private function participantsFromPoolLog(int $year, int $month, array $leaderLevelIds, float $revenue): ?array
    {
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = date('Y-m-t', strtotime($start));

        $rows = DB::table('poolLog as p')
            ->leftJoin('consultant as c', 'c.id', '=', 'p.consultant')
            ->leftJoin('status_levels as sl', 'sl.id', '=', 'c.status_and_lvl')
            ->whereBetween('p.date', [$start, $end])
            ->select([
                'p.consultant',
                'c.personName',
                'sl.level',
                'sl.title',
                'p.poolBonus',
                'p.networkGroupBonus',
            ])
            ->orderBy('sl.level')
            ->orderBy('c.personName')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $participants = $rows->map(fn ($r) => [
            'id' => (int) $r->consultant,
            'level' => (int) ($r->level ?? 0),
            'levelName' => $r->title ?? '',
            'personName' => $r->personName ?? '—',
            'participates' => true,
            'payoutRub' => round((float) ($r->poolBonus ?? 0), 2),
            'groupBonusRub' => round((float) ($r->networkGroupBonus ?? 0), 2),
        ])->all();

        $totalPaid = array_sum(array_column($participants, 'payoutRub'));

        // shareValues: средняя выплата на уровень (для отображения «Итого фонд × #голов»).
        $byLevel = [];
        foreach ($participants as $p) {
            $byLevel[$p['level']][] = $p['payoutRub'];
        }
        $shareValues = [];
        foreach ($leaderLevelIds as $lvl => $_id) {
            $shareValues[$lvl] = !empty($byLevel[$lvl])
                ? round(max($byLevel[$lvl]), 2)
                : 0;
        }

        $fund = $revenue * PoolCalculator::POOL_PERCENT;

        return [
            'year' => $year,
            'month' => $month,
            'revenue' => $revenue,
            'fund' => $fund,
            'shareValues' => $shareValues,
            'participants' => $participants,
            'totalPaid' => $totalPaid,
            'totalForfeited' => 0.0,
            'written' => 0,
            'fromPoolLog' => true,  // флаг для UI: данные из реального лога
        ];
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
