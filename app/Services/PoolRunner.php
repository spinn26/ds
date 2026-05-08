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
    /**
     * До этой границы (включительно) пул выводится как историческая
     * выгрузка — snapshot из poolLog (БД), либо fallback на CSV
     * `Db/Pool/poolLog.csv`. Live-расчёт по новой формуле начинается
     * с месяца LIVE_CALC_FROM (включительно).
     *
     * Февраль 2026 — последний месяц зафиксированной истории.
     * Март 2026 граница (если ещё не закрыт) — оператор сам решает
     * переходить на live или нет, для этого freeze-логика остаётся.
     */
    public const HISTORICAL_BEFORE = ['year' => 2026, 'month' => 4]; // < апрель 2026

    public function run(int $year, int $month, bool $applyWrite = false): array
    {
        $isHistorical = $this->isHistoricalMonth($year, $month);

        // Исторические периоды read-only: только snapshot, никакого
        // applyWrite. Защита от случайного перезатирания эталонных
        // данных Directual-импорта или CSV.
        if ($applyWrite && $isHistorical) {
            return [
                'year' => $year, 'month' => $month,
                'revenue' => 0.0, 'fund' => 0.0,
                'shareValues' => [], 'participants' => [],
                'totalPaid' => 0.0, 'totalForfeited' => 0.0,
                'written' => 0, 'frozen' => true,
                'message' => sprintf(
                    'Период %02d.%d — исторический (до апреля 2026). Расчёт пула '.
                    'и фиксация для таких периодов запрещены.',
                    $month, $year,
                ),
            ];
        }

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

        // Исторический период (до апреля 2026): всегда snapshot.
        // Сначала пытаемся БД (poolLog), если пусто — читаем CSV
        // (Db/Pool/poolLog.csv). Это точная выгрузка из старой Directual.
        if ($isHistorical) {
            $logged = $this->participantsFromPoolLog($year, $month, $revenue);
            if ($logged !== null) return $logged;
            $fromCsv = $this->participantsFromCsv($year, $month, $revenue);
            if ($fromCsv !== null) return $fromCsv;
            // Ни в БД, ни в CSV нет данных — отдаём пустой результат.
            return $this->emptyResult($year, $month, $revenue);
        }

        // Snapshot из poolLog для заморожённого периода (после фиксации).
        // Live-расчёт обходим только для frozen — иначе оператор должен
        // видеть актуальные числа.
        if (! $applyWrite && $this->periodFreeze->isFrozen($year, $month)) {
            $logged = $this->participantsFromPoolLog($year, $month, $revenue);
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

        // Берём calc и nom отдельно: основная ветка распределения работает
        // по calculationLevel (фактический уровень после ОП-проверки),
        // но если calc < 6 при nom >= 6 — партнёр всё равно показывается
        // в верхней таблице как «потенциальный получатель, ОП не выполнен».
        $perPartnerLevel = DB::table('qualificationLog as ql')
            ->leftJoin('status_levels as sl_calc', 'sl_calc.id', '=', 'ql.calculationLevel')
            ->leftJoin('status_levels as sl_nom', 'sl_nom.id', '=', 'ql.nominalLevel')
            ->whereBetween('ql.date', [$start, $end])
            ->whereNull('ql.dateDeleted')
            ->select([
                'ql.consultant',
                DB::raw('COALESCE(sl_calc.level, sl_nom.level) AS level'),
                DB::raw('COALESCE(sl_calc.title, sl_nom.title) AS title'),
                DB::raw('COALESCE(sl_calc.id, sl_nom.id) AS level_id'),
                DB::raw('COALESCE(sl_calc."mandatoryGP", sl_nom."mandatoryGP") AS "mandatoryGP"'),
                DB::raw('sl_nom.level AS nominal_level'),
                DB::raw('sl_nom.title AS nominal_title'),
                DB::raw('sl_nom.id AS nominal_level_id'),
                'ql.groupVolume',
                'ql.gapValuePercentage',
            ])
            ->orderByDesc('ql.date')
            ->get()
            ->groupBy('consultant')
            // У одного консультанта может быть несколько qualificationLog
            // в месяц — берём максимальный уровень.
            ->map(fn ($g) => $g->sortByDesc('level')->first());

        // ДОБОР: партнёры, у которых нет qualificationLog за этот месяц,
        // но был раньше с уровнем 6+. В эталоне старой платформы такие
        // видны в верхней таблице как «партнёр уровня 6+, не участвует»
        // — без галочки и без выплаты.
        //
        // Источник уровня: ПОСЛЕДНИЙ qualificationLog партнёра (даже за
        // прошлые месяцы) — даёт точную картину «когда последний раз
        // партнёр квалифицировался на 6+». Раньше пытались брать из
        // consultant.status_and_lvl, но это поле обновляется только при
        // finalisation и в новых открытых месяцах (где finalisation ещё
        // не запускалась) даёт ноль партнёров.
        //
        // Дополнительный backup: текущий consultant.status_and_lvl >= 6.
        // Берётся UNION-ом с qualificationLog-источником.
        $alreadyHaveQl = $perPartnerLevel->keys()->map(fn ($k) => (int) $k)->all();

        $extraConsultants = DB::select(
            "
            WITH last_ql AS (
                SELECT DISTINCT ON (ql.consultant)
                    ql.consultant,
                    COALESCE(ql.\"calculationLevel\", ql.\"nominalLevel\") AS lvl_id
                FROM \"qualificationLog\" ql
                WHERE ql.date <= ?
                  AND ql.\"dateDeleted\" IS NULL
                ORDER BY ql.consultant, ql.date DESC
            )
            SELECT
                c.id          AS consultant,
                sl.level      AS level,
                sl.title      AS title,
                sl.id         AS level_id,
                sl.\"mandatoryGP\" AS \"mandatoryGP\"
            FROM consultant c
            JOIN status_levels sl ON sl.id = COALESCE(
                (SELECT lvl_id FROM last_ql WHERE consultant = c.id),
                c.status_and_lvl
            )
            WHERE c.activity = 1
              AND c.\"dateDeleted\" IS NULL
              AND sl.level >= ?
              AND sl.level <= ?
              " . (! empty($alreadyHaveQl)
                    ? 'AND c.id NOT IN (' . implode(',', array_map('intval', $alreadyHaveQl)) . ')'
                    : '') . "
            ",
            [
                $end,
                PoolCalculator::LEADER_LEVEL_MIN,
                PoolCalculator::LEADER_LEVEL_MAX,
            ],
        );
        $extraConsultants = collect($extraConsultants);

        // Конвертируем в формат perPartnerLevel и доливаем. У этих
        // консультантов нет ОП-данных за период, поэтому groupVolume=0,
        // gapValuePercentage=0 → они автоматически попадут в "ОП не выполнен".
        // Помечаем флагом isExtra=true: в эталоне старой платформы такие
        // партнёры видны в верхней таблице, но НЕ участвуют в счётчике
        // долей (фонд делится только на тех, у кого есть qualificationLog).
        foreach ($extraConsultants as $row) {
            $perPartnerLevel[$row->consultant] = (object) [
                'consultant' => $row->consultant,
                'level' => $row->level,
                'title' => $row->title,
                'level_id' => $row->level_id,
                'mandatoryGP' => $row->mandatoryGP,
                'groupVolume' => 0,
                'gapValuePercentage' => 0,
                'isExtra' => true,
            ];
        }

        // Активный? — глобальный флаг (терминированные исключаются всегда,
        // даже если у них есть qualificationLog за период). Фильтруем
        // null/пустые id (legacy-данные могут содержать orphan-строки).
        $consIds = array_values(array_filter(
            array_map('intval', $perPartnerLevel->keys()->all())
        ));
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

        // Considered = active + level ≥ 6 + has qualificationLog за период.
        // Per spec ✅Расчет пула §6.4 + правка от 2026-05-06:
        // пул делится на участников с УЧЁТОМ модерации:
        //   • Дисквалификация ОП/отрыв → доля forfeited (остаётся в компании,
        //     делитель не уменьшается).
        //   • Снята галочка «Участвует» вручную → ПОЛНОСТЬЮ исключаем
        //     из делителя; доля перераспределяется между остальными.
        //   Раньше делитель был общим — оператор снимал галку и пул
        //   уменьшался для всех (а ожидание было — увеличивался).
        $considered = [];
        $nominalCounts = array_fill_keys(array_keys($leaderLevelIds), 0);
        $rowsForUi = [];

        // Сумма групповых бонусов в РУБЛЯХ за период по каждому консультанту.
        //
        // ВАЖНО: считаем через `groupBonus × 100`, не через `groupBonusRub`.
        // В legacy Directual-данных колонка `groupBonusRub` для части
        // записей содержит фактически БАЛЛЫ (не рубли — несмотря на имя):
        // импорт из CSV не сделал умножение на 100. Это видно в проде:
        // у партнёров уровня TOP FC за февраль выходило ~23 в этой колонке,
        // что было бы 23 рубля — нереально мало.
        //
        // `groupBonus` (баллы) консистентен между legacy и Laravel-генерируемыми
        // commission, поэтому `× 100` даёт корректные рубли всегда.
        $monthKey = sprintf('%04d-%02d', $year, $month);
        $groupBonusByCons = $consIds
            ? DB::table('commission')
                ->whereIn('consultant', $consIds)
                ->where('dateMonth', $monthKey)
                ->whereNull('deletedAt')
                ->select('consultant', DB::raw('SUM("groupBonus") * 100 as total'))
                ->groupBy('consultant')
                ->pluck('total', 'consultant')
            : collect();

        foreach ($perPartnerLevel as $consultantId => $row) {
            $level = (int) $row->level;
            $nominalLevel = (int) ($row->nominal_level ?? 0);

            // Партнёр считается участником пула если ИЛИ
            // - calc-level >= 6 (заработал по ОП), либо
            // - nom-level >= 6 (был на лидерском уровне, но ОП не выполнен).
            // Во втором случае он помечается как ОП-не-выполнен и не идёт
            // в счётчик долей, но виден в верхней таблице UI.
            $isLeaderByCalc = $level >= PoolCalculator::LEADER_LEVEL_MIN;
            $isLeaderByNom = $nominalLevel >= PoolCalculator::LEADER_LEVEL_MIN;
            if (! $isLeaderByCalc && ! $isLeaderByNom) continue;
            if (! $activeIds->has($consultantId)) continue;

            // Если по calc партнёр выпал из лидерского пула — показываем
            // его на nominal-уровне с пометкой «ОП не выполнен», как extra.
            $isOpFailExtra = ! $isLeaderByCalc && $isLeaderByNom;
            if ($isOpFailExtra) {
                $level = $nominalLevel;
                $row->title = $row->nominal_title;
                $row->level_id = $row->nominal_level_id;
            }

            // Extras (нет qualificationLog за период ИЛИ есть но ОП не
            // выполнен с понижением calc < 6) НЕ участвуют в счётчике
            // фонда — эталон старой платформы делит только на тех, у кого
            // фактически calc >= 6 в этом месяце. Сняли галочку вручную —
            // остаются в счётчике (доля не выплачивается, но делитель
            // не уменьшается).
            $isExtra = (bool) ($row->isExtra ?? false) || $isOpFailExtra;
            $modParticipates = $modByConsId[$consultantId] ?? null;
            $modExcluded = $modParticipates === false; // галка явно снята
            // Снятая вручную галка ПОЛНОСТЬЮ исключает из делителя
            // (доля перераспределяется). Дисквалификация по ОП/отрыву —
            // delitель остаётся, доля forfeited.
            if (! $isExtra && ! $modExcluded) {
                $nominalCounts[$level] = ($nominalCounts[$level] ?? 0) + 1;
            }

            $mandatoryGp = (float) ($row->mandatoryGP ?? 0);
            $groupVolume = (float) ($row->groupVolume ?? 0);
            $gapPct = (float) ($row->gapValuePercentage ?? 0);

            $opOk = $mandatoryGp <= 0 || $groupVolume >= $mandatoryGp;
            // Per spec ✅Расчет пула §6.4: дисквалификация при отрыве > 90%
            // (строго больше; если ровно 90% — пул выплачивается).
            $gapOk = $gapPct <= 90.0;
            $modOk = ! $modExcluded;

            $disqualifyReason = null;
            if ($isOpFailExtra ?? false) {
                // ОП не выполнен → calc понижен ниже 6, виден на nominal-уровне.
                $disqualifyReason = 'ОП не выполнен';
                $opOk = false;
            } elseif ((bool) ($row->isExtra ?? false)) {
                $disqualifyReason = 'Не подтвердил квалификацию';
                $opOk = false;
            } elseif (! $opOk) {
                $disqualifyReason = 'ОП не выполнен';
            } elseif (! $gapOk) {
                $disqualifyReason = sprintf('Отрыв %.0f%% > 90%%', $gapPct);
            } elseif (! $modOk) {
                $disqualifyReason = 'Снята галочка «Участвует»';
            }

            $considered[] = (int) $consultantId;
            $rowsForUi[] = (object) [
                'id' => (int) $consultantId,
                'level' => $level,
                'title' => $row->title,
                'personName' => $consultantMeta[$consultantId] ?? '—',
                'participates' => $modParticipates,
                'eligible' => $opOk && $gapOk && $modOk,
                'opOk' => $opOk,
                'gapOk' => $gapOk,
                'mandatoryGP' => $mandatoryGp,
                'groupVolume' => $groupVolume,
                'gapValuePercentage' => $gapPct,
                'disqualifyReason' => $disqualifyReason,
                'groupBonusRub' => round((float) ($groupBonusByCons[$consultantId] ?? 0), 2),
            ];
        }

        $shares = $this->calculator->shareValues($revenue, $nominalCounts);

        $rows = collect($rowsForUi);

        $partnerInputs = [];
        foreach ($rows as $r) {
            $partnerInputs[] = [
                'id' => (int) $r->id,
                'level' => (int) $r->level,
                // PoolCalculator::distribute смотрит только на participates →
                // прокидываем итоговую eligibility (модерация × ОП × отрыв).
                'participates' => (bool) $r->eligible,
            ];
        }

        $distribution = $this->calculator->distribute($revenue, $nominalCounts, $partnerInputs);

        // Merge meta (name, level title, причина исключения) для ответа UI.
        $metaById = $rows->keyBy('id');
        $participants = array_map(function ($p) use ($metaById) {
            $meta = $metaById[$p['id']] ?? null;
            return [
                'id' => $p['id'],
                'level' => $p['level'],
                'levelName' => $meta?->title ?? '',
                'personName' => $meta?->personName ?? '',
                'participates' => $meta?->participates === null
                    ? true
                    : (bool) $meta->participates,
                'eligible' => (bool) ($meta?->eligible ?? false),
                'opOk' => (bool) ($meta?->opOk ?? false),
                'gapOk' => (bool) ($meta?->gapOk ?? false),
                'mandatoryGP' => $meta?->mandatoryGP ?? 0,
                'groupVolume' => $meta?->groupVolume ?? 0,
                'gapValuePercentage' => $meta?->gapValuePercentage ?? 0,
                'disqualifyReason' => $meta?->disqualifyReason,
                'payoutRub' => $p['payoutRub'],
                'groupBonusRub' => $meta?->groupBonusRub ?? 0,
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
    private function participantsFromPoolLog(int $year, int $month, float $revenue): ?array
    {
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = date('Y-m-t', strtotime($start));

        // ВАЖНО: уровень должен быть за РАСЧЁТНЫЙ месяц, а не текущий
        // c.status_and_lvl. Иначе у партнёра, который в феврале был Топ ФК
        // и сегодня уже Эксперт, в исторической карточке пула 2026-02
        // отображается «Эксперт» — выглядит как сломанные данные.
        // Берём GREATEST(nominalLevel, calculationLevel) из qualificationLog
        // за тот месяц, как и в основной ветке расчёта.
        $rows = DB::select(
            'SELECT
                p.consultant,
                c."personName",
                sl.level,
                sl.title,
                p."poolBonus",
                p."networkGroupBonus"
             FROM "poolLog" p
             LEFT JOIN consultant c ON c.id = p.consultant
             LEFT JOIN LATERAL (
                 SELECT COALESCE(ql."calculationLevel", ql."nominalLevel") AS lvl_id
                 FROM "qualificationLog" ql
                 WHERE ql.consultant = p.consultant
                   AND ql.date BETWEEN ? AND ?
                   AND ql."dateDeleted" IS NULL
                 ORDER BY ql.date DESC LIMIT 1
             ) ql_period ON TRUE
             LEFT JOIN status_levels sl ON sl.id = ql_period.lvl_id
             WHERE p.date BETWEEN ? AND ?
             ORDER BY sl.level NULLS LAST, c."personName"',
            [$start, $end, $start, $end]
        );
        $rows = collect($rows);

        if ($rows->isEmpty()) {
            return null;
        }

        // Pool moderation overrides: оператор мог снять галку даже на
        // историческом периоде (исключить из распределения post-factum).
        $modByConsId = DB::table('pool_moderation')
            ->where('year', $year)->where('month', $month)
            ->pluck('participates', 'consultant');

        $participants = $rows->map(fn ($r) => [
            'id' => (int) $r->consultant,
            'level' => (int) ($r->level ?? 0),
            'levelName' => $r->title ?? '',
            'personName' => $r->personName ?? '—',
            // Если в pool_moderation для этого месяца стоит false — UI
            // показывает галку снятой; payout остаётся как был в snapshot
            // (исторический snapshot не пересчитывается).
            'participates' => $modByConsId[(int) $r->consultant] ?? true,
            'payoutRub' => round((float) ($r->poolBonus ?? 0), 2),
            'groupBonusRub' => round((float) ($r->networkGroupBonus ?? 0), 2),
        ])->all();

        // «Теневые» участники: те, у кого ЕСТЬ запись в pool_moderation
        // (operator снял/проставил галку), но по какой-то причине НЕТ в
        // poolLog snapshot (impount пропустил, qualificationLog расходится
        // с poolLog). Добавляем чтобы UI показал их со снятой галкой.
        $alreadyHave = collect($participants)->pluck('id')->all();
        $missingIds = $modByConsId->keys()->diff($alreadyHave)->all();
        if (! empty($missingIds)) {
            $shadowRows = DB::table('consultant as c')
                ->whereIn('c.id', $missingIds)
                ->whereNull('c.dateDeleted')
                ->select(['c.id', 'c.personName', 'c.status_and_lvl'])
                ->get();
            $levelByLvlId = DB::table('status_levels')
                ->whereIn('id', $shadowRows->pluck('status_and_lvl')->filter()->unique())
                ->get(['id', 'level', 'title'])
                ->keyBy('id');
            foreach ($shadowRows as $sr) {
                $lvl = $sr->status_and_lvl ? ($levelByLvlId[$sr->status_and_lvl] ?? null) : null;
                $participants[] = [
                    'id' => (int) $sr->id,
                    'level' => (int) ($lvl?->level ?? 0),
                    'levelName' => $lvl?->title ?? '',
                    'personName' => $sr->personName ?? '—',
                    'participates' => (bool) ($modByConsId[$sr->id] ?? false),
                    'payoutRub' => 0,
                    'groupBonusRub' => 0,
                ];
            }
        }

        $totalPaid = array_sum(array_column($participants, 'payoutRub'));

        // ИСТОРИЧЕСКИЙ / ЗАМОРОЖЕННЫЙ ПЕРИОД — выводим только то, что
        // реально лежит в `poolLog`.
        //
        // Раньше revenue считался по ТЕКУЩЕЙ transaction-таблице, fund =
        // revenue × 1%, shareValues пересчитывались из текущего
        // qualificationLog. Все три могут «уехать» относительно того,
        // что было на момент фиксации (транзакции отредактировали,
        // qLog пересчитали и т.п.). В UI это выглядело как:
        //   • payoutRub в строках — реальные выплаты из poolLog,
        //   • Выручка/Фонд/ИТОГО — расчёт «по сегодняшним данным»,
        //   • цифры расходились на десятки процентов.
        //
        // Решение: revenue/fund/shareValues отдаём как null. Frontend
        // по флагу fromPoolLog рендерит «—» в этих колонках, ИТОГО
        // считает как фактическую сумму payoutRub из снимка.
        return [
            'year' => $year,
            'month' => $month,
            'revenue' => null,
            'fund' => null,
            'shareValues' => [],
            'participants' => $participants,
            'totalPaid' => $totalPaid,
            'totalForfeited' => null,
            'written' => 0,
            'fromPoolLog' => true,
        ];
    }

    /**
     * true, если период <= февраля 2026 (исторический Directual-импорт).
     * Live-расчёт работает с марта 2026 включительно (HISTORICAL_BEFORE).
     */
    private function isHistoricalMonth(int $year, int $month): bool
    {
        $border = self::HISTORICAL_BEFORE;
        return ($year < $border['year'])
            || ($year === $border['year'] && $month < $border['month']);
    }

    /**
     * Снимок «как было» из CSV `Db/Pool/poolLog.csv` — fallback для
     * исторических периодов (Directual выгрузка), когда в БД ничего
     * нет (например, импорт пропустил часть строк).
     *
     * Формат CSV: `id;consultant;poolBonus;networkGroupBonus;date;createdAt;@dateCreated;@dateChanged`
     * Возвращает null если файл не найден / пуст / нет строк за период.
     */
    private function participantsFromCsv(int $year, int $month, float $revenue): ?array
    {
        $path = base_path('Db/Pool/poolLog.csv');
        if (! is_file($path)) return null;

        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = date('Y-m-t', strtotime($start));

        $rows = [];
        $fh = @fopen($path, 'r');
        if (! $fh) return null;
        try {
            $header = fgetcsv($fh, 0, ';');
            if (! $header) return null;
            // BOM защита: первое поле может содержать UTF-8 BOM \xEF\xBB\xBF.
            if (isset($header[0])) {
                $header[0] = preg_replace('/^\xEF\xBB\xBF/u', '', (string) $header[0]);
            }
            $idx = array_flip($header);
            while (($r = fgetcsv($fh, 0, ';')) !== false) {
                $date = $r[$idx['date'] ?? 4] ?? '';
                if ($date < $start || $date > $end . 'T23:59:59') continue;
                $rows[] = [
                    'consultant' => (int) ($r[$idx['consultant'] ?? 1] ?? 0),
                    'poolBonus' => (float) ($r[$idx['poolBonus'] ?? 2] ?? 0),
                    'networkGroupBonus' => $r[$idx['networkGroupBonus'] ?? 3] ?? null,
                ];
            }
        } finally {
            fclose($fh);
        }
        if (empty($rows)) return null;

        $consIds = array_unique(array_column($rows, 'consultant'));
        $consultants = DB::table('consultant')->whereIn('id', $consIds)
            ->pluck('personName', 'id');

        // Подтягиваем уровень из qualificationLog за тот же период (как
        // в participantsFromPoolLog) — для UI отображения квалификации.
        $perCons = DB::table('qualificationLog as ql')
            ->whereBetween('ql.date', [$start, $end])
            ->whereNull('ql.dateDeleted')
            ->whereIn('ql.consultant', $consIds)
            ->select(
                'ql.consultant',
                DB::raw('COALESCE(ql."calculationLevel", ql."nominalLevel") AS lvl_id'),
            )
            ->orderByDesc('ql.date')
            ->get()->keyBy('consultant');
        $levelTitles = DB::table('status_levels')
            ->pluck('title', 'id')->toArray();
        $levelNumbers = DB::table('status_levels')
            ->pluck('level', 'id')->toArray();

        $participants = [];
        foreach ($rows as $r) {
            $consId = $r['consultant'];
            $qlRow = $perCons[$consId] ?? null;
            $lvlId = $qlRow->lvl_id ?? null;
            $level = (int) ($levelNumbers[$lvlId] ?? 0);
            $title = $levelTitles[$lvlId] ?? '';
            $participants[] = [
                'id' => $consId,
                'level' => $level,
                'levelName' => $title,
                'personName' => $consultants[$consId] ?? '—',
                'participates' => true,
                'payoutRub' => round($r['poolBonus'], 2),
                'groupBonusRub' => 0,
            ];
        }

        $totalPaid = array_sum(array_column($participants, 'payoutRub'));

        // CSV — тоже исторический snapshot. revenue/fund/shareValues = null:
        // данные могли «уехать» в текущей БД, и пересчитывать их для UI
        // означает рисовать неконсистентные с poolLog цифры (см. подробный
        // комментарий в participantsFromPoolLog выше).
        return [
            'year' => $year,
            'month' => $month,
            'revenue' => null,
            'fund' => null,
            'shareValues' => [],
            'participants' => $participants,
            'totalPaid' => $totalPaid,
            'totalForfeited' => null,
            'written' => 0,
            'fromCsv' => true,
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
