<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Monthly penalty runner — applies §5 (detachment, OP shortfall, combo)
 * to the commission table for a given year/month.
 *
 * Flow for each consultant with level ≥ 3:
 *   1. Load all their group-commissions for the month (chainOrder ≥ 2).
 *   2. Group commissions by "first-line branch" — walk up inviter chain
 *      from the original seller until we hit a consultant whose inviter
 *      is this person; that child is the branch key.
 *   3. Run detachment multipliers on branch volumes (only if
 *      status_levels.otrif > 0, i.e. level ≥ 6).
 *   4. Run OP multiplier on total group volume vs status_levels.mandatoryGP
 *      (only if mandatoryGP > 0, i.e. level ≥ 3).
 *   5. Write per-commission reductions and a qualificationLog summary row.
 *
 * All writes are gated behind `applyWrite=true`. Preview mode returns the
 * same diff as a JSON-serialisable array without touching the DB.
 *
 * The frozen-period guard refuses to run (preview or write) on a month
 * already closed via PeriodFreezeService — spec ✅Комиссии §1.
 */
class MonthlyPenaltyRunner
{
    public function __construct(
        private readonly MonthlyFinaliser $finaliser,
        private readonly PeriodFreezeService $periodFreeze,
        private readonly CommissionCalculator $calculator,
    ) {}

    /**
     * @return array{
     *   year:int, month:int, applyWrite:bool,
     *   frozen:bool,
     *   processed:int, affected:int,
     *   consultants: list<array<string,mixed>>,
     * }
     */
    public function run(int $year, int $month, bool $applyWrite = false): array
    {
        // Исторические данные (< HISTORICAL_CUTOFF) неизменны — не пишем штрафы.
        // Read-режим (applyWrite=false, превью) разрешён.
        if ($applyWrite && CommissionCalculator::isHistorical(sprintf('%04d-%02d', $year, $month))) {
            return [
                'year' => $year,
                'month' => $month,
                'applyWrite' => $applyWrite,
                'frozen' => true,
                'processed' => 0,
                'affected' => 0,
                'consultants' => [],
                'error' => "Период {$month}.{$year} — исторический (< " . CommissionCalculator::HISTORICAL_CUTOFF . '), финализация не применяется',
            ];
        }

        if ($this->periodFreeze->isFrozen($year, $month)) {
            return [
                'year' => $year,
                'month' => $month,
                'applyWrite' => $applyWrite,
                'frozen' => true,
                'processed' => 0,
                'affected' => 0,
                'consultants' => [],
                'error' => "Период {$month}.{$year} закрыт — финализация не применяется",
            ];
        }

        // Pre-load the entire inviter map once (1-2k rows) instead of
        // N recursive lookups per consultant.
        $inviterMap = DB::table('consultant')
            ->whereNull('dateDeleted')
            ->pluck('inviter', 'id')
            ->map(fn ($v) => $v === null ? null : (int) $v)
            ->toArray();

        // All consultants that can be subject to penalties: have a
        // qualification level with mandatoryGP > 0 (i.e. level ≥ 3).
        $candidates = DB::table('consultant as c')
            ->join('status_levels as sl', 'sl.id', '=', 'c.status_and_lvl')
            ->where('sl.level', '>=', 3)
            ->whereNull('c.dateDeleted')
            ->select([
                'c.id',
                'c.personName',
                'c.status_and_lvl',
                'sl.level',
                'sl.percent',
                'sl.mandatoryGP',
                'sl.otrif',
            ])
            ->get();

        // Legacy format: dateMonth = "YYYY-MM" (not just "MM"), dateYear = "YYYY".
        $dateMonth = sprintf('%04d-%02d', $year, $month);
        $dateYear = (string) $year;
        $monthEnd = Carbon::create($year, $month, 1)->endOfMonth();

        // Уровень за РАСЧЁТНЫЙ МЕСЯЦ из qualificationLog (та же логика, что в
        // PoolRunner): берём запись(и) месяца, максимальный уровень по
        // COALESCE(calculationLevel, nominalLevel). Иначе штрафы за прошлый
        // месяц считаются по СЕГОДНЯШНЕМУ consultant.status_and_lvl — партнёр,
        // сменивший уровень, штрафуется по неверному percent/mandatoryGP/otrif.
        // Переопределяем поля кандидата на уровень месяца, если он есть.
        // Набор кандидатов не расширяем (текущий level ≥ 3) — партнёр с текущим
        // уровнем < 3 в penalty-набор не попадает, как и раньше.
        $monthLevel = DB::table('qualificationLog as ql')
            ->leftJoin('status_levels as sl_calc', 'sl_calc.id', '=', 'ql.calculationLevel')
            ->leftJoin('status_levels as sl_nom', 'sl_nom.id', '=', 'ql.nominalLevel')
            ->whereBetween('ql.date', [sprintf('%04d-%02d-01', $year, $month), $monthEnd->toDateTimeString()])
            ->whereNull('ql.dateDeleted')
            ->select([
                'ql.consultant',
                DB::raw('COALESCE(sl_calc.id, sl_nom.id) AS level_id'),
                DB::raw('COALESCE(sl_calc.level, sl_nom.level) AS level'),
                DB::raw('COALESCE(sl_calc.percent, sl_nom.percent) AS percent'),
                DB::raw('COALESCE(sl_calc."mandatoryGP", sl_nom."mandatoryGP") AS "mandatoryGP"'),
                DB::raw('COALESCE(sl_calc.otrif, sl_nom.otrif) AS otrif'),
            ])
            ->orderByDesc('ql.date')
            ->get()
            ->groupBy('consultant')
            ->map(fn ($g) => $g->sortByDesc('level')->first());

        $candidates = $candidates->map(function ($c) use ($monthLevel) {
            $ml = $monthLevel->get($c->id);
            if ($ml && $ml->level_id) {
                $c->level = (int) $ml->level;
                $c->percent = $ml->percent;
                $c->mandatoryGP = $ml->mandatoryGP;
                $c->otrif = $ml->otrif;
                // status_and_lvl уходит в qualificationLog при применении штрафа —
                // пишем уровень месяца, а не текущий.
                $c->status_and_lvl = (int) $ml->level_id;
            }

            return $c;
        });

        // Pre-fetch ЛП партнёров одним запросом — иначе processConsultant
        // делает sum(personalVolume) для каждого из ~1030 партнёров,
        // что даёт ~1030 round-trip-ов на каждом ночном прогоне.
        $personalVolumes = DB::table('commission')
            ->where('chainOrder', 1)
            ->where('dateYear', $dateYear)
            ->where('dateMonth', $dateMonth)
            ->whereNull('deletedAt')
            ->whereIn('consultant', $candidates->pluck('id'))
            ->selectRaw('consultant, SUM("personalVolume") AS pv')
            ->groupBy('consultant')
            ->pluck('pv', 'consultant')
            ->map(fn ($v) => (float) $v)
            ->toArray();

        // Ручные баллы из «Прочих» (спека §3) прибавляются к ЛП — иначе ОП/отрыв
        // считаются без них, и партнёр, которому баллы начислили ради статуса,
        // всё равно штрафуется.
        $manualPoints = \App\Support\ManualPoints::byMonth($candidates->pluck('id')->all(), $dateMonth);
        foreach ($manualPoints as $cid => $pts) {
            $personalVolumes[$cid] = ($personalVolumes[$cid] ?? 0) + $pts;
        }

        $stats = [];
        $affectedTotal = 0;

        // ID партнёров, у которых реально применили штраф — после цикла
        // пересчитаем им consultantBalance, иначе snapshot останется
        // со старыми (до-штрафа) суммами amountRUB, и Реестр выплат
        // покажет неправильные «к выплате».
        $affectedConsultantIds = [];

        foreach ($candidates as $cons) {
            // Per-consultant atomarity: при applyWrite все записи одного
            // партнёра (UPDATE commission + delete/insert qualificationLog)
            // должны быть all-or-nothing, иначе сбой в середине оставляет
            // ledger полу-оштрафованным с выставленным флагом reduction.
            // В preview-режиме транзакция не нужна (записей нет).
            $run = fn () => $this->processConsultant(
                consultant: $cons,
                year: $year,
                month: $month,
                dateYear: $dateYear,
                dateMonth: $dateMonth,
                monthEnd: $monthEnd,
                inviters: $inviterMap,
                applyWrite: $applyWrite,
                personalVolume: (float) ($personalVolumes[$cons->id] ?? 0),
            );
            $result = $applyWrite ? DB::transaction($run) : $run();
            if ($result['affectedCommissions'] > 0) {
                $stats[] = $result;
                $affectedTotal += $result['affectedCommissions'];
                if ($applyWrite) {
                    $affectedConsultantIds[] = (int) $cons->id;
                }
            }
        }

        // Месячный снимок для партнёров ВНЕ набора штрафов (уровень < 3).
        // Штрафы к ним неприменимы, но снимок квалификации нужен: без него их
        // НГП не растёт, а отчёт «Квалификации» показывает пустые ЛП/ГП/НГП.
        // Уровень < 3 => в «Расчёт пула» (лидеры 6+) эти строки не попадают,
        // делитель пула не меняется.
        if ($applyWrite) {
            $this->writeBaselineSnapshots(
                $year, $month, $dateYear, $dateMonth, $monthEnd,
                $candidates->pluck('id')->map(fn ($v) => (int) $v)->all(),
            );
        }

        // Пересчёт consultantBalance для затронутых партнёров —
        // commission.amountRUB снижены штрафами, но snapshot в
        // consultantBalance.accruedTransactional остаётся старым,
        // если его не пересчитать. Иначе Реестр выплат покажет
        // «к выплате» БЕЗ учёта штрафов до следующего ручного rebuild'а.
        // Делаем после всех processConsultant — батчем по уникальным id.
        if ($applyWrite && $affectedConsultantIds) {
            foreach (array_unique($affectedConsultantIds) as $cid) {
                try {
                    $this->calculator->rebuildBalanceFor($cid, $dateMonth, $dateYear);
                } catch (\Throwable $e) {
                    \Log::warning('rebuildBalance after penalty failed', [
                        'consultant' => $cid,
                        'month' => $dateMonth,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // Denorm-поля транзакций (profitRUB/netRevenueRUB) отстают после
        // штрафов — пересобираем их за месяц из актуальных commission-строк,
        // чтобы отчёты (они читают denorm) были верны. История не трогается.
        if ($applyWrite && ! CommissionCalculator::isHistorical($dateMonth)) {
            $this->recomputeTransactionDerivedFields($dateMonth);
        }

        // НГП накопительный — производный от ГП: пересчёт ГП за этот месяц сдвигает
        // НГП и во ВСЕХ последующих. Строка самого месяца уже записана верно выше,
        // поэтому докатываем цепочку вперёд, до текущего месяца включительно.
        // Иначе после повторной финализации июня июль остался бы со старой базой.
        $ngpRebuilt = [];
        if ($applyWrite) {
            $ngpRebuilt = $this->cascadeNgpForward($year, $month);
        }

        return [
            'year' => $year,
            'month' => $month,
            'applyWrite' => $applyWrite,
            'frozen' => false,
            'processed' => $candidates->count(),
            'affected' => $affectedTotal,
            'consultants' => $stats,
            'ngpRebuilt' => $ngpRebuilt,
        ];
    }

    /**
     * Пересобрать НГП за месяцы ПОСЛЕ расчётного, по возрастанию — каждый берёт
     * базу из уже исправленного предыдущего. Комиссии/штрафы/балансы не трогаем.
     *
     * @return list<string> пересобранные месяцы, YYYY-MM
     */
    private function cascadeNgpForward(int $year, int $month): array
    {
        $done = [];
        $cursor = Carbon::create($year, $month, 1)->startOfMonth()->addMonth();
        $last = Carbon::now()->startOfMonth();

        while ($cursor->lte($last)) {
            $ym = $cursor->format('Y-m');
            try {
                $this->rebuildMonthlySnapshots($cursor->year, $cursor->month);
                $done[] = $ym;
            } catch (\Throwable $e) {
                // НГП — производная величина; её сбой не должен ронять уже
                // применённые штрафы. Логируем и идём дальше.
                \Log::warning('НГП cascade failed', ['month' => $ym, 'error' => $e->getMessage()]);
            }
            $cursor->addMonth();
        }

        return $done;
    }

    /**
     * Пересобрать месячные снимки qualificationLog за один месяц БЕЗ пересчёта
     * комиссий и штрафов (см. partners:rebuild-ngp). Нужно для бэкфилла: с июня
     * 2026 НГП замер, потому что penalty-строка переносила cumulative без
     * прибавки ГП, а партнёрам уровня < 3 снимок вообще не писался.
     *
     *  1) существующим строкам на конец месяца чиним ТОЛЬКО groupVolumeCumulative
     *     (gap/result/withheld* — итоги штрафов — не трогаем);
     *  2) партнёрам вне penalty-набора (уровень < 3) пишем недостающий снимок.
     *
     * Месяцы обязаны обрабатываться по возрастанию — каждый следующий берёт
     * базу из уже исправленного предыдущего.
     *
     * @return array{updated:int, inserted:int}
     */
    public function rebuildMonthlySnapshots(int $year, int $month): array
    {
        $monthEnd = Carbon::create($year, $month, 1)->endOfMonth();
        $monthStart = Carbon::create($year, $month, 1)->startOfMonth()->toDateTimeString();

        // 1) Существующие строки на конец месяца: НГП = база до месяца + ГП строки.
        // Подзапрос читает snapshot до начала UPDATE, но он и так смотрит только
        // на строки date < monthStart, которые этот UPDATE не трогает.
        $updated = DB::update(<<<'SQL'
            UPDATE "qualificationLog" q
            SET "groupVolumeCumulative" = COALESCE((
                    SELECT p."groupVolumeCumulative"
                    FROM "qualificationLog" p
                    WHERE p.consultant = q.consultant
                      AND p."dateDeleted" IS NULL
                      AND p."groupVolumeCumulative" IS NOT NULL
                      AND p.date < ?
                    ORDER BY p.date DESC
                    LIMIT 1
                ), 0) + COALESCE(q."groupVolume", 0),
                "changedAt" = NOW()
            WHERE q.date = ?
              AND q."dateDeleted" IS NULL
            SQL, [$monthStart, $monthEnd->toDateTimeString()]);

        // 2) Недостающие снимки. Пропускаем тех, у кого строка уже есть (п.1), и
        // тех, кто относится к penalty-набору (уровень ≥ 3): пустая строка у
        // лидера 6+ добавила бы его в делитель пула — это уже деньги.
        $skip = DB::table('qualificationLog')
            ->where('date', $monthEnd->toDateTimeString())
            ->whereNull('dateDeleted')
            ->pluck('consultant')
            ->merge(
                DB::table('consultant as c')
                    ->join('status_levels as sl', 'sl.id', '=', 'c.status_and_lvl')
                    ->where('sl.level', '>=', 3)
                    ->pluck('c.id')
            )
            ->filter()
            ->map(fn ($v) => (int) $v)
            ->unique()
            ->values()
            ->all();

        $before = DB::table('qualificationLog')
            ->where('date', $monthEnd->toDateTimeString())
            ->count();

        $this->writeBaselineSnapshots(
            $year, $month, (string) $year, sprintf('%04d-%02d', $year, $month), $monthEnd, $skip,
        );

        $after = DB::table('qualificationLog')
            ->where('date', $monthEnd->toDateTimeString())
            ->count();

        return ['updated' => $updated, 'inserted' => max(0, $after - $before)];
    }

    /**
     * Месячный снимок qualificationLog для партнёров, которых не обрабатывает
     * penalty-проход (уровень < 3 — им нечего штрафовать). Пишем те же поля,
     * что и penalty-строка: уровень месяца, ЛП, ГП и накопительный НГП.
     *
     * НГП = последний НЕ-NULL cumulative СТРОГО до начала месяца + ГП месяца
     * (та же формула, что в processConsultant).
     *
     * Идемпотентно: строки на monthEnd для этих партнёров удаляются и пишутся
     * заново, как в penalty-проходе.
     *
     * @param  list<int>  $candidateIds  партнёры, которым строку уже написал penalty-проход
     */
    private function writeBaselineSnapshots(
        int $year,
        int $month,
        string $dateYear,
        string $dateMonth,
        Carbon $monthEnd,
        array $candidateIds,
    ): void {
        $monthStart = Carbon::create($year, $month, 1)->startOfMonth()->toDateTimeString();

        $consultants = DB::table('consultant')
            ->whereNull('dateDeleted')
            ->when($candidateIds, fn ($q) => $q->whereNotIn('id', $candidateIds))
            ->get(['id', 'personName', 'status_and_lvl']);

        if ($consultants->isEmpty()) {
            return;
        }

        $ids = $consultants->pluck('id')->map(fn ($v) => (int) $v)->all();

        // ЛП = Σ personalVolume по chainOrder=1; ГП = ЛП + Σ groupVolume по
        // chainOrder ≥ 2 (per spec ✅Бизнес-логика §1 — та же формула, что в
        // processConsultant). Оба — одним групповым запросом на всех.
        $personal = DB::table('commission')
            ->where('chainOrder', 1)
            ->where('dateYear', $dateYear)
            ->where('dateMonth', $dateMonth)
            ->whereNull('deletedAt')
            ->whereIn('consultant', $ids)
            ->selectRaw('consultant, SUM("personalVolume") AS v')
            ->groupBy('consultant')
            ->pluck('v', 'consultant');

        $downline = DB::table('commission')
            ->where('chainOrder', '>=', 2)
            ->where('dateYear', $dateYear)
            ->where('dateMonth', $dateMonth)
            ->whereNull('deletedAt')
            ->whereIn('consultant', $ids)
            ->selectRaw('consultant, SUM("groupVolume") AS v')
            ->groupBy('consultant')
            ->pluck('v', 'consultant');

        // Последний НЕ-NULL НГП строго до начала месяца — по всем сразу.
        $carry = DB::table('qualificationLog')
            ->selectRaw('DISTINCT ON (consultant) consultant, "groupVolumeCumulative" AS cum')
            ->whereIn('consultant', $ids)
            ->whereNull('dateDeleted')
            ->whereNotNull('groupVolumeCumulative')
            ->where('date', '<', $monthStart)
            ->orderBy('consultant')
            ->orderByDesc('date')
            ->pluck('cum', 'consultant');

        // Ручные баллы из «Прочих» (спека §3) — часть ЛП, а значит и ГП/НГП.
        $manualPoints = \App\Support\ManualPoints::byMonth($ids, $dateMonth);

        $now = now();
        $rows = [];
        foreach ($consultants as $c) {
            $lp = (float) ($personal[$c->id] ?? 0) + (float) ($manualPoints[$c->id] ?? 0);
            $gp = $lp + (float) ($downline[$c->id] ?? 0);

            $rows[] = [
                'consultant' => (int) $c->id,
                'date' => $monthEnd->toDateTimeString(),
                'savingDate' => $now,
                'gap' => false,
                'result' => 'newEntry',
                'calculationLevel' => $c->status_and_lvl,
                'nominalLevel' => $c->status_and_lvl,
                'personalVolume' => $lp,
                'groupVolume' => $gp,
                'groupVolumeCumulative' => (float) ($carry[$c->id] ?? 0) + $gp,
                'consultantPersonName' => $c->personName,
                'createdAt' => $now,
                'changedAt' => $now,
            ];
        }

        DB::table('qualificationLog')
            ->whereIn('consultant', $ids)
            ->where('date', $monthEnd->toDateTimeString())
            ->delete();

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('qualificationLog')->insert($chunk);
        }
    }

    /**
     * Пересобрать denorm-поля транзакций (profitRUB/netRevenueRUB/netRevenueUSD)
     * за месяц из АКТУАЛЬНЫХ commission-строк. Нужно после применения штрафов:
     * штраф отрыва/ОП уменьшает commission.amountRUB, но эти поля пишутся один
     * раз при расчёте (CommissionCalculator) и иначе «отстают». Раздел
     * транзакций считает их live и не зависит от этого, но отчёты читают
     * именно denorm. Формула — как в калькуляторе:
     *   profitRUB     = commissionsAmountRUB (Доход ДС без НДС) − Σ комиссий;
     *   netRevenueRUB = amountNoVat − Σ комиссий (amountNoVat = Доход ДС × 100/%ДС);
     *   netRevenueUSD — пропорционально (сохраняем подразумеваемый курс).
     * Дедуп цепочки по (transaction, consultant, chainOrder) — как в разделе.
     * Выплаты/commission НЕ трогаем — только производные поля.
     */
    private function recomputeTransactionDerivedFields(string $dateMonth): void
    {
        DB::statement(<<<'SQL'
            WITH chain AS (
                SELECT transaction AS tx, SUM(a) AS chain_sum FROM (
                    SELECT DISTINCT ON (cm.transaction, cm.consultant, cm."chainOrder")
                           cm.transaction, cm."amountRUB" AS a
                    FROM commission cm
                    WHERE cm."deletedAt" IS NULL
                      AND cm.transaction IN (
                          SELECT id FROM transaction WHERE "dateMonth" = ? AND "deletedAt" IS NULL
                      )
                    ORDER BY cm.transaction, cm.consultant, cm."chainOrder", cm.id DESC
                ) d GROUP BY transaction
            )
            UPDATE transaction t SET
                "profitRUB" = round(t."commissionsAmountRUB" - ch.chain_sum, 2),
                "netRevenueRUB" = CASE WHEN t."dsCommissionPercentage" > 0
                    THEN round(t."commissionsAmountRUB" * 100.0 / t."dsCommissionPercentage" - ch.chain_sum, 2)
                    ELSE t."netRevenueRUB" END,
                "netRevenueUSD" = CASE WHEN t."netRevenueRUB" <> 0 AND t."dsCommissionPercentage" > 0
                    THEN round((t."commissionsAmountRUB" * 100.0 / t."dsCommissionPercentage" - ch.chain_sum)
                               * t."netRevenueUSD" / t."netRevenueRUB", 2)
                    ELSE t."netRevenueUSD" END
            FROM chain ch
            WHERE t.id = ch.tx AND t."dateMonth" = ? AND t."deletedAt" IS NULL
        SQL, [$dateMonth, $dateMonth]);
    }

    /**
     * @param  array<int,?int>  $inviters  consultantId → inviterId
     */
    private function processConsultant(
        object $consultant,
        int $year,
        int $month,
        string $dateYear,
        string $dateMonth,
        Carbon $monthEnd,
        array $inviters,
        bool $applyWrite,
        float $personalVolume = 0.0,
    ): array {
        // Group commissions for this mentor in the target month.
        $commissions = DB::table('commission')
            ->where('consultant', $consultant->id)
            ->where('chainOrder', '>=', 2)
            ->where('dateYear', $dateYear)
            ->where('dateMonth', $dateMonth)
            ->whereNull('deletedAt')
            ->get();

        // $personalVolume пробрасывается из run() — pre-fetched одним
        // запросом для всех кандидатов. Per spec ✅Бизнес-логика §1:
        // ГП = ЛП + downline. ЛП не участвует в per-branch отрыве
        // (у себя нет «ветки»), но обязан попасть в totalGroupVolume.

        if ($commissions->isEmpty() && $personalVolume <= 0) {
            return $this->emptyResult($consultant);
        }

        // Bucket commissions by first-line branch under this mentor.
        // Seller id can live in `commissionFromOtherConsultant` (new) or
        // `consultantsChain` (legacy) — try both; otherwise the row ends up
        // as "unassigned" and only participates in the OP calc.
        $byBranch = [];
        $branchVolumes = [];
        $unassigned = [];
        foreach ($commissions as $c) {
            $sellerId = (int) ($c->commissionFromOtherConsultant ?? 0)
                ?: (int) ($c->consultantsChain ?? 0);
            $branchKey = $sellerId > 0
                ? $this->firstLineBranchUnder($sellerId, (int) $consultant->id, $inviters)
                : null;
            if ($branchKey === null) {
                $unassigned[] = $c;

                continue;
            }
            $byBranch[$branchKey][] = $c;
            $branchVolumes[$branchKey] = ($branchVolumes[$branchKey] ?? 0.0)
                + (float) $c->groupVolume;
        }

        // Полный ГП партнёра (ЛП + баллы + downline + unassigned) — база и для
        // ОП, и для ОТРЫВА. Считаем ДО множителей отрыва, чтобы доля ветки
        // считалась от полного ГП, а не от суммы одних веток.
        // Per spec ✅Бизнес-логика §1: ГП = ЛП + объёмы downline.
        $mandatoryGp = (float) ($consultant->mandatoryGP ?? 0);
        $totalGroupVolume = $personalVolume + array_sum($branchVolumes);
        foreach ($unassigned as $u) {
            $totalGroupVolume += (float) $u->groupVolume;
        }

        // Detachment: only level ≥ 6 (otrif > 0). База доли — полный ГП.
        $otrif = (float) ($consultant->otrif ?? 0);
        $detachMults = ($otrif > 0 && ! empty($branchVolumes))
            ? $this->finaliser->detachmentMultipliers($branchVolumes, $totalGroupVolume)
            : array_fill_keys(array_keys($branchVolumes), 1.0);

        $opMult = $mandatoryGp > 0
            ? $this->finaliser->opMultiplier($totalGroupVolume, $mandatoryGp)
            : 1.0;

        // ВАЖНО: даже когда штрафа нет (opMult=1, нет отрыва), мы НЕ выходим
        // раньше — ниже всё равно пишем строку qualificationLog за месяц.
        // Это месячный снапшот квалификации (groupVolume + уровень + ОП-итог),
        // из которого «Расчёт пула» определяет, выполнил ли партнёр ОП.
        // Раньше строка писалась только при штрафе → партнёр, ВЫПОЛНИВШИЙ ОП,
        // не попадал в qualificationLog, и пул всегда показывал ему «ОП не
        // выполнен» (жалоба по июню: Денис с ЛП 1млн не определился).

        // Apply per commission.
        $affected = 0;
        $withheldTotal = 0.0;
        $updates = [];

        $applyToRow = function ($c, float $dm) use (&$affected, &$withheldTotal, &$updates, $opMult, $applyWrite) {
            $totalMult = $dm * $opMult;
            if ($totalMult >= 1.0) {
                return;
            }
            if ((bool) ($c->reduction ?? false)) {
                return;
            } // idempotent

            $originalRub = (float) $c->groupBonusRub;
            $newRub = $originalRub * $totalMult;
            $withheldTotal += $originalRub - $newRub;
            $affected++;

            $updates[] = [
                'id' => (int) $c->id,
                'originalRub' => $originalRub,
                'newRub' => $newRub,
                'detachMult' => $dm,
                'opMult' => $opMult,
            ];

            if ($applyWrite) {
                DB::table('commission')
                    ->where('id', $c->id)
                    ->update([
                        'reduction' => true,
                        'groupBonusRubBeforeGapReduction' => $originalRub,
                        'withheldPercent' => (1.0 - $totalMult) * 100.0,
                        'withheldForGap' => $dm < 1 ? ($originalRub * (1.0 - $dm)) : 0,
                        'withheldForCommission' => $opMult < 1
                            ? (($originalRub * $dm) * (1.0 - $opMult))
                            : 0,
                        'amountRUB' => $newRub,
                        'groupBonusRub' => $newRub,
                    ]);
            }
        };

        // Branched rows — detachment multiplier applies per branch.
        foreach ($byBranch as $branchKey => $rows) {
            $dm = $detachMults[$branchKey] ?? 1.0;
            foreach ($rows as $c) {
                $applyToRow($c, $dm);
            }
        }
        // Unassigned rows — no branch detach, just OP.
        foreach ($unassigned as $c) {
            $applyToRow($c, 1.0);
        }

        $gapBranchKey = array_search(0.5, $detachMults, true);
        if ($applyWrite) {
            // Идемпотентность: повторный прогон финализа за тот же месяц не
            // должен плодить дубли penalty-строк. Penalty-строка живёт на
            // конце месяца (monthEnd 23:59:59), а штатные снапшоты — на начале
            // месяца, поэтому удаление по date=monthEnd безопасно. Это также
            // самовосстанавливает старые дубли при следующем прогоне.
            DB::table('qualificationLog')
                ->where('consultant', $consultant->id)
                ->where('date', $monthEnd)
                ->delete();

            // Пишем строку qualificationLog ВСЕГДА (не только при штрафе) — это
            // месячный снапшот квалификации, по которому «Расчёт пула» видит
            // выполнение ОП. Раньше писалось лишь при штрафе → партнёр,
            // ВЫПОЛНИВШИЙ ОП, в qualificationLog не попадал, и пул показывал
            // ему «ОП не выполнен».
            //
            // НГП (накопительный ГП) за месяц = НГП на конец ПРЕДЫДУЩЕГО месяца
            // + ГП текущего месяца. Раньше строка просто переносила последний
            // cumulative без прибавки ГП — это работало, пока месячный снимок
            // с приростом писал Directual. Заливок больше нет (см. memory
            // project-directual-imports-stopped), поэтому НГП замер с июня 2026
            // у всех партнёров. База берётся строго ДО начала месяца: внутри
            // месяца может лежать легаси-строка Directual (1-е число), которая
            // уже содержит частичный ГП этого же месяца — иначе двойной счёт.

            $monthStart = Carbon::create($year, $month, 1)->startOfMonth();

            $carryCumulative = DB::table('qualificationLog')
                ->where('consultant', $consultant->id)
                ->whereNull('dateDeleted')
                ->whereNotNull('groupVolumeCumulative')
                ->where('date', '<', $monthStart->toDateTimeString())
                ->orderByDesc('date')
                ->value('groupVolumeCumulative');

            $cumulative = (float) ($carryCumulative ?? 0) + $totalGroupVolume;

            DB::table('qualificationLog')->insert([
                'consultant' => $consultant->id,
                'date' => $monthEnd,
                'savingDate' => now(),
                'gap' => $gapBranchKey !== false,
                // Показанный % отрыва — от той же базы, что и РЕШЕНИЕ об отрыве
                // (detachmentMultipliers): доля = branchVolume / ПОЛНЫЙ ГП (ЛП +
                // баллы + downline). Так добавленные партнёру баллы (в ЛП)
                // корректно снижают долю и «уводят» отрыв. Раньше делили на Σ
                // веток (без ЛП) → плашка показывала завышенный % и баллы не
                // помогали.
                'gapValuePercentage' => $gapBranchKey !== false
                    ? round($branchVolumes[$gapBranchKey] / max($totalGroupVolume, 0.0001) * 100, 2)
                    : null,
                'gapValue' => $gapBranchKey !== false ? $branchVolumes[$gapBranchKey] : null,
                'branchWithGap' => $gapBranchKey !== false ? $gapBranchKey : null,
                'result' => $this->buildResultLabel($opMult, $gapBranchKey),
                'calculationLevel' => $consultant->status_and_lvl,
                'nominalLevel' => $consultant->status_and_lvl,
                // Месячный ЛП партнёра (SUM commission chainOrder=1 за месяц,
                // пробрасывается из run()). Раньше не писался → снимок
                // qualificationLog имел ЛП=NULL, и раздел «Квалификации»
                // показывал 0 (инцидент «июнь нули»). Теперь снимок полный.
                'personalVolume' => $personalVolume,
                'groupVolume' => $totalGroupVolume,
                'groupVolumeCumulative' => $cumulative,
                'consultantPersonName' => $consultant->personName,
                'commissionsToReduceCounter' => $affected,
                'commissionsToReduceAmount' => (int) round($withheldTotal),
                'createdAt' => now(),
                'changedAt' => now(),
            ]);

        }

        return [
            'id' => (int) $consultant->id,
            'personName' => $consultant->personName,
            'level' => (int) $consultant->level,
            'mandatoryGp' => $mandatoryGp,
            'otrif' => $otrif,
            'totalGroupVolume' => $totalGroupVolume,
            'branchVolumes' => $branchVolumes,
            'detachmentMultipliers' => $detachMults,
            'opMultiplier' => $opMult,
            'affectedCommissions' => $affected,
            'withheldTotalRub' => round($withheldTotal, 2),
            'unassignedCommissions' => count($unassigned),
            'updates' => $applyWrite ? [] : $updates, // preview payload
        ];
    }

    /**
     * From a seller, walk up the inviter chain until we hit a consultant
     * whose inviter == mentorId; that consultant is the branch key
     * under the mentor. Returns null if the seller is not in the mentor's
     * subtree (shouldn't happen for legit commission rows, but we guard
     * against broken inviter chains).
     *
     * @param  array<int,?int>  $inviters
     */
    private function firstLineBranchUnder(?int $sellerId, int $mentorId, array $inviters): ?int
    {
        if (! $sellerId) {
            return null;
        }
        if ($sellerId === $mentorId) {
            return null;
        }

        $current = $sellerId;
        $visited = [];
        while ($current !== null && ! isset($visited[$current])) {
            $visited[$current] = true;
            $parent = $inviters[$current] ?? null;
            if ($parent === $mentorId) {
                return $current;
            }
            $current = $parent;
        }

        return null;
    }

    private function buildResultLabel(float $opMult, int|false $gapBranchKey): string
    {
        $parts = [];
        if ($gapBranchKey !== false) {
            $parts[] = 'Отрыв >70%';
        }
        if ($opMult < 1.0) {
            $parts[] = 'Недобор ОП';
        }

        return empty($parts) ? 'OK' : implode(' + ', $parts);
    }

    private function emptyResult(object $consultant, array $extra = []): array
    {
        return array_merge([
            'id' => (int) $consultant->id,
            'personName' => $consultant->personName,
            'level' => (int) $consultant->level,
            'affectedCommissions' => 0,
        ], $extra);
    }
}
