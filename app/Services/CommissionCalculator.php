<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\PeriodFreezeService;

/**
 * Каскадный расчёт комиссий по MLM-структуре.
 *
 * Алгоритм:
 * 1. Для транзакции найти контракт → консультанта
 * 2. Рассчитать ЛП (личные продажи) для прямого партнёра
 * 3. Пройтись вверх по структуре (inviter цепочка) и рассчитать ГП
 * 4. Рассчитать комиссии по разнице квалификаций
 * 5. Сохранить в таблицу commission
 * 6. После успешного расчёта — обновить consultant.personalVolume
 *    и авто-активировать партнёра при переходе порога 500 ЛП.
 */
class CommissionCalculator
{
    /**
     * ID плейсхолдер-консультанта «Неизвестный консультант».
     * Per spec ✅Бизнес-логика «Неизвестного консультанта».md:
     *   ставка 0%, 100% удерживается компанией, цепочка не строится.
     */
    public const UNKNOWN_CONSULTANT_ID = 536;

    public function __construct(
        private readonly PartnerStatusService $statusService,
        private readonly PeriodFreezeService $periodFreeze,
    ) {}


    /**
     * Рассчитать комиссии для одной транзакции.
     */
    public function calculateForTransaction(int $transactionId): array
    {
        $result = DB::transaction(function () use ($transactionId) {
            return $this->calculateInTransaction($transactionId);
        });

        // Side-effect after commit: recompute consultant's LP for the period
        // and auto-activate if the 500-point threshold has been crossed.
        // Intentionally outside the transaction so an activation save doesn't
        // roll back the commission cascade on failure.
        if (! empty($result['consultantId'])) {
            try {
                $this->statusService->recomputeVolumeAndActivate($result['consultantId']);
            } catch (\Throwable $e) {
                Log::warning('Auto-activate after commission calc failed', [
                    'consultant' => $result['consultantId'],
                    'transaction' => $transactionId,
                    'error' => $e->getMessage(),
                ]);
            }

            // consultantBalance — это (consultant, dateMonth) агрегат, на
            // котором держится «Реестр выплат» (колонка «Начислено») и
            // блок «Итог за месяц» в отчёте. Раньше его пересчитывала
            // только большая миграция 2026_04_28_…_resync_*; после ручной
            // фиксации/импорта он оставался устаревшим, и партнёр в
            // реестре висел с «Начислено=0», хотя commission уже есть.
            try {
                $this->rebuildBalancesForTransaction($transactionId);
            } catch (\Throwable $e) {
                Log::warning('Rebuild consultantBalance after commission calc failed', [
                    'transaction' => $transactionId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }

    /**
     * Пересобрать `consultantBalance` для всех (consultant, dateMonth) пар,
     * затронутых данной транзакцией. Формула — та же, что в миграции
     * 2026_04_28_000040_resync_consultant_balance_aggregates.php:
     *   accruedTransactional ← SUM(commission.amountRUB WHERE type='transaction')
     *   accruedNonTransactional ← SUM(commission.amountRUB WHERE type='nonTransactional')
     *   accruedTotal ← accruedTransactional + accruedNonTransactional + accruedPool
     *   totalPayable ← balance + accruedTotal
     *   remaining ← totalPayable - payed
     *
     * Если строки за этот месяц для консультанта ещё нет — создаём
     * минимальную (balance=0, payed=0, accruedPool=0). Старые строки
     * никогда не трогает поля, которые ведёт отдельная логика (payed,
     * accruedPool, balance).
     */
    private function rebuildBalancesForTransaction(int $transactionId): void
    {
        $pairs = DB::table('commission')
            ->where('transaction', $transactionId)
            ->whereNull('deletedAt')
            ->select('consultant', 'dateMonth', 'dateYear')
            ->distinct()
            ->get();

        foreach ($pairs as $p) {
            if (! $p->consultant || ! $p->dateMonth) continue;
            $this->rebuildBalance((int) $p->consultant, (string) $p->dateMonth, (string) ($p->dateYear ?? substr($p->dateMonth, 0, 4)));
        }
    }

    private function rebuildBalance(int $consultantId, string $dateMonth, string $dateYear): void
    {
        $sums = DB::table('commission')
            ->where('consultant', $consultantId)
            ->where('dateMonth', $dateMonth)
            ->whereNull('deletedAt')
            ->selectRaw("
                COALESCE(SUM(CASE WHEN type = 'transaction' THEN \"amountRUB\" ELSE 0 END), 0) AS tx,
                COALESCE(SUM(CASE WHEN type = 'nonTransactional' THEN \"amountRUB\" ELSE 0 END), 0) AS nontx
            ")
            ->first();

        $accruedTransactional = (float) ($sums->tx ?? 0);
        $accruedNonTransactional = (float) ($sums->nontx ?? 0);

        $row = DB::table('consultantBalance')
            ->where('consultant', $consultantId)
            ->where('dateMonth', $dateMonth)
            ->first();

        if ($row) {
            $accruedPool = (float) ($row->accruedPool ?? 0);
            $balance = (float) ($row->balance ?? 0);
            $payed = (float) ($row->payed ?? 0);
            $accruedTotal = $accruedTransactional + $accruedNonTransactional + $accruedPool;
            $totalPayable = $balance + $accruedTotal;
            $remaining = $totalPayable - $payed;

            // В легаси-схеме consultantBalance нет колонки changedAt/updatedAt
            // (только createdAt/dateCreated на момент INSERT). Не пишем
            // несуществующее поле — иначе UPDATE падает 42703.
            DB::table('consultantBalance')->where('id', $row->id)->update([
                'accruedTransactional' => $accruedTransactional,
                'accruedNonTransactional' => $accruedNonTransactional,
                'accruedTotal' => $accruedTotal,
                'totalPayable' => $totalPayable,
                'remaining' => $remaining,
            ]);
        } else {
            // Новый месяц без записи — создаём минимальную, чтобы реестр
            // выплат и отчёт могли её прочитать. accruedPool/balance/payed
            // = 0 по умолчанию, их при необходимости проставит пул-runner.
            $accruedTotal = $accruedTransactional + $accruedNonTransactional;
            DB::table('consultantBalance')->insert([
                'consultant' => $consultantId,
                'dateMonth' => $dateMonth,
                'dateYear' => $dateYear,
                'accruedTransactional' => $accruedTransactional,
                'accruedNonTransactional' => $accruedNonTransactional,
                'accruedPool' => 0,
                'accruedTotal' => $accruedTotal,
                'balance' => 0,
                'payed' => 0,
                'totalPayable' => $accruedTotal,
                'remaining' => $accruedTotal,
                'dateCreated' => now(),
            ]);
        }
    }

    private function calculateInTransaction(int $transactionId): array
    {
        // Сериализуем параллельные расчёты по этой транзакции через row-lock
        // на parent-строке. Без него две одновременные джобы (например, две
        // ручных «Рассчитать» в истории импортов) делают SOFT-DELETE по
        // своему MVCC-снэпшоту, не видят чужие свежие INSERT'ы и каждая
        // INSERT'ит полную цепочку — в БД остаются 2× дубли (на проде
        // обнаружено 341 такая группа, 2026-05-28).
        $tx = DB::table('transaction')
            ->where('id', $transactionId)
            ->whereNull('deletedAt')
            ->lockForUpdate()
            ->first();
        if (! $tx) return ['error' => 'Транзакция не найдена или удалена'];

        // Удаляем ранее посчитанные commission по этой транзакции — иначе
        // повторный вызов calculateForTransaction (после правки тх, бэкфилла
        // или ручного «Пересчитать») плодит дубли (наблюдалось: 6 commission
        // вместо 3 на tx#60708 после backfill 2026-05-23).
        DB::table('commission')
            ->where('transaction', $transactionId)
            ->whereNull('deletedAt')
            ->update(['deletedAt' => now()]);

        // Заморозка: нельзя пересчитывать комиссии в закрытом месяце.
        // Per ./.claude/specs/✅Комиссии .md Part 2 §1.
        // ВАЖНО: dateMonth хранится как 'YYYY-MM' (например, '2026-02'),
        // intval() даёт 2026, не 2 → проверка заморозки никогда не срабатывала.
        // Берём последние 2 символа (число месяца).
        $txMonth = $tx->dateMonth ? (int) substr((string) $tx->dateMonth, -2) : null;
        $txYear  = $tx->dateYear ? (int) $tx->dateYear : null;
        if ($txYear && $txMonth && $this->periodFreeze->isFrozen($txYear, $txMonth)) {
            return ['error' => "Период {$tx->dateMonth} закрыт — комиссии не пересчитываются"];
        }

        $contract = DB::table('contract')->where('id', $tx->contract)->whereNull('deletedAt')->first();
        if (! $contract) return ['error' => 'Контракт не найден или удалён'];

        $consultantId = $contract->consultant;
        if (! $consultantId) return ['error' => 'Консультант не привязан'];

        $consultant = DB::table('consultant')->where('id', $consultantId)->whereNull('dateDeleted')->first();
        if (! $consultant) return ['error' => 'Консультант не найден или удалён'];

        // Курс валюты
        $currencyRate = (float) ($tx->currencyRate ?? 1);
        $amountRub = (float) ($tx->amountRUB ?? ((float) ($tx->amount ?? 0) * $currencyRate));

        // НДС
        $vat = DB::table('vat')
            ->where('dateFrom', '<=', now())
            ->where('dateTo', '>=', now())
            ->first();
        $vatPercent = (float) ($vat->value ?? 0);
        $amountNoVat = $amountRub / (1 + $vatPercent / 100);

        // Program row — holds the BackOffice-editable fields:
        // dsPercent (overrides legacy dsCommission), pointsMethod / fixedCost /
        // pointsMin (drives the points formula).
        $programRow = $contract->program
            ? DB::table('program')->where('id', $contract->program)->first()
            : null;

        // dsCommission — legacy precedence: tx->dsCommissionPercentage →
        // program.dsPercent → legacy dsCommission row → fallback 100%.
        $dsComPercent = (float) ($tx->dsCommissionPercentage ?? 0);
        if ($dsComPercent <= 0 && $programRow && $programRow->dsPercent !== null) {
            $dsComPercent = (float) $programRow->dsPercent;
        }
        if ($dsComPercent <= 0 && $contract->program) {
            // Детерминированный выбор: если у программы несколько активных
            // записей dsCommission (наблюдалось у Axevil/187: 3% и 30%),
            // берём последнюю по id. Без orderBy ->first() возвращает
            // случайную и платежи цепочке могут превысить доход DS
            // (отрицательная прибыль).
            $dsCom = DB::table('dsCommission')
                ->where('program', $contract->program)
                ->where('active', true)
                ->where('date', '<=', now())
                ->where('dateFinish', '>=', now())
                ->whereNull('dateDeleted')
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->first();
            $dsComPercent = (float) ($dsCom->comission ?? 0);
        }

        if ($dsComPercent <= 0) {
            $dsComPercent = 100; // Fallback
        }

        // ЛП per program.pointsMethod, same switch as CalculatorController::computePoints.
        $personalVolume = $this->computePointsForProgram(
            $programRow, $amountNoVat, $amountRub, $dsComPercent
        );

        // Per spec ✅Бизнес-логика «Неизвестного консультанта».md:
        // если контракт привязан к плейсхолдер-аккаунту, ставка = 0%
        // и каскад не строится — 100% дохода остаётся компании.
        if ((int) $consultantId === self::UNKNOWN_CONSULTANT_ID) {
            return $this->writeZeroForUnknownConsultant($transactionId, $consultantId, $personalVolume, $tx);
        }

        // Получить квалификацию прямого партнёра на момент транзакции.
        // Per spec §7: a new rate takes effect from the 1st of the month AFTER
        // the one in which the НГП threshold was crossed. Passing tx->date to
        // the resolver below gives each historical transaction its own rate.
        $qualLevel = $this->getQualificationLevel($consultantId, $tx->date);
        $qualPercent = $qualLevel ? (float) $qualLevel->percent : 15; // Start = 15%

        // Групповой бонус = ЛП * % квалификации / 100
        $groupBonus = $personalVolume * $qualPercent / 100;
        $groupBonusRub = $groupBonus * 100; // 1 балл = 100 руб

        $commissions = [];
        // Σ всех комиссий цепочке в рублях — для transaction.netRevenueRUB.
        // Раньше считалось через array_map по $commissions, но createCommission
        // возвращает int (insert id), не массив data — sum получался 0.
        $chainTotalRub = 0.0;

        // 1. Комиссия прямого партнёра (chainOrder = 1)
        $chainTotalRub += round($groupBonusRub, 2);
        $commissions[] = $this->createCommission([
            'transaction' => $transactionId,
            'consultant' => $consultantId,
            'chainOrder' => 1,
            'type' => 'transaction',
            'personalVolume' => round($personalVolume, 6),
            'groupVolume' => round($personalVolume, 6),
            'groupBonus' => round($groupBonus, 6),
            'groupBonusRub' => round($groupBonusRub, 2),
            'percent' => $qualPercent,
            'amount' => round($amountNoVat * $dsComPercent / 100, 2),
            'amountRUB' => round($groupBonusRub, 2),
            'amountUSD' => 0,
            'currency' => $tx->currency ?? 67,
            'date' => $tx->date,
            'dateMonth' => $tx->dateMonth,
            'dateYear' => $tx->dateYear,
            'calculationLevel' => $qualLevel?->id,
        ]);

        // 2. Каскад вверх по структуре (inviter цепочка)
        $currentConsultantId = $consultantId;
        $prevPercent = $qualPercent;
        $chainOrder = 2;
        $visited = [$consultantId]; // защита от зацикливания

        for ($i = 0; $i < 20; $i++) {
            $current = DB::table('consultant')->where('id', $currentConsultantId)->first();
            $inviterId = $current->inviter ?? null;

            if (! $inviterId || in_array($inviterId, $visited)) break;
            $visited[] = $inviterId;

            $inviter = DB::table('consultant')->where('id', $inviterId)->first();
            if (! $inviter) break;

            $inviterLevel = $this->getQualificationLevel($inviterId, $tx->date);
            $inviterPercent = $inviterLevel ? (float) $inviterLevel->percent : 15;

            // Маржинальная разница — разница процентов между наставником и нижестоящим
            $marginPercent = $inviterPercent - $prevPercent;

            if ($marginPercent > 0) {
                $inviterBonus = $personalVolume * $marginPercent / 100;
                $inviterBonusRub = $inviterBonus * 100;
                $chainTotalRub += round($inviterBonusRub, 2);

                $commissions[] = $this->createCommission([
                    'transaction' => $transactionId,
                    'consultant' => $inviterId,
                    'chainOrder' => $chainOrder,
                    'type' => 'transaction',
                    'commissionFromOtherConsultant' => $consultantId,
                    'personalVolume' => 0,
                    'groupVolume' => round($personalVolume, 6),
                    'groupBonus' => round($inviterBonus, 6),
                    'groupBonusRub' => round($inviterBonusRub, 2),
                    'percent' => $marginPercent,
                    'amount' => 0,
                    'amountRUB' => round($inviterBonusRub, 2),
                    'amountUSD' => 0,
                    'currency' => $tx->currency ?? 67,
                    'date' => $tx->date,
                    'dateMonth' => $tx->dateMonth,
                    'dateYear' => $tx->dateYear,
                    'calculationLevel' => $inviterLevel?->id,
                ]);

                $chainOrder++;
            }

            $prevPercent = max($prevPercent, $inviterPercent);
            $currentConsultantId = $inviterId;
        }

        // Обновить агрегаты на транзакции — иначе в /manage/commissions
        // колонки «% ДС», «Доход ДС», «Без НДС» остаются пустыми после
        // ручной фиксации, хотя commission уже создан (жалоба Богдановой
        // 2026-05-22: «при ручном занесении не подтянулась дата открытия
        // контракта и не рассчитался доход ДС»).
        //
        // Доход ДС (commissionsAmountRUB) = amountNoVat × %ДС / 100.
        // netRevenueRUB = amountNoVat − Σ комиссии цепочке = «остаток ДС».
        // USD-зеркала пересчитываем через текущий USD-курс.
        $incomeDsRub = round($amountNoVat * $dsComPercent / 100, 2);
        $netRevenueRub = round($amountNoVat - $chainTotalRub, 2);

        $usdRow = DB::table('currencyRate')->where('currency', 5)->orderByDesc('date')->first();
        $usdRate = (float) ($usdRow->rate ?? 1);
        $incomeDsUsd = $usdRate > 0 ? round($incomeDsRub / $usdRate, 2) : 0;
        $netRevenueUsd = $usdRate > 0 ? round($netRevenueRub / $usdRate, 2) : 0;

        // Прибыль DS = доход DS - выплаты партнёрам цепочки.
        // *BeforeGapReduction — снимок ДО применения отрыва (на момент
        // создания commission штраф ещё не применён, MonthlyPenaltyRunner
        // подтянет reduction ночью). После отрыва profitRUB должен расти
        // (удержание партнёра становится прибылью DS), но *BeforeGapReduction
        // остаются как точка отсчёта — нужны для отчёта «комиссия до отрыва».
        $profitRub = round($incomeDsRub - $chainTotalRub, 2);

        DB::table('transaction')->where('id', $transactionId)->update([
            'personalVolume' => round($personalVolume, 6),
            'groupVolume' => round($personalVolume, 6),
            'dsCommissionPercentage' => round($dsComPercent, 4),
            'commissionsAmountRUB' => $incomeDsRub,
            'commissionsAmountUSD' => $incomeDsUsd,
            'netRevenueRUB' => $netRevenueRub,
            'netRevenueUSD' => $netRevenueUsd,
            'profitRUB' => $profitRub,
            'commissionAmountRubBeforeGapReduction' => round($chainTotalRub, 2),
            'profitRubBeforeGapReduction' => $profitRub,
        ]);

        // openDate контракта подтягиваем из транзакции, если контракт ещё
        // без даты открытия (legacy-импорт оставлял её NULL — оператор
        // в Менеджере контрактов мог не заполнить). Берём минимальную
        // дату из всех неудалённых транзакций по этому контракту.
        if ($contract->openDate === null || $contract->openDate === '') {
            $firstTxDate = DB::table('transaction')
                ->where('contract', $contract->id)
                ->whereNull('deletedAt')
                ->whereNotNull('date')
                ->min('date');
            if ($firstTxDate) {
                DB::table('contract')->where('id', $contract->id)
                    ->update(['openDate' => $firstTxDate]);
            }
        }

        return [
            'success' => true,
            'transactionId' => $transactionId,
            'consultantId' => (int) $consultantId,
            'personalVolume' => round($personalVolume, 6),
            'commissionsCount' => count($commissions),
        ];
    }

    /**
     * Рассчитать комиссии для всех транзакций импорта.
     */
    public function calculateForImport(int $importId): array
    {
        $transactions = DB::table('transaction')
            ->where('comment', 'Импорт #' . $importId)
            ->pluck('id');

        $results = ['total' => $transactions->count(), 'success' => 0, 'errors' => 0];

        foreach ($transactions as $txId) {
            $result = $this->calculateForTransaction($txId);
            if (isset($result['success'])) {
                $results['success']++;
            } else {
                $results['errors']++;
            }
        }

        return $results;
    }

    /**
     * Применить `program.pointsMethod` для расчёта ЛП одной сделки.
     * Дублирует логику CalculatorController::computePoints чтобы ручной
     * калькулятор партнёра и фоновый пересчёт давали одинаковые цифры.
     */
    private function computePointsForProgram(
        ?object $program,
        float $amountNoVat,
        float $amountRub,
        float $dsComPercent,
    ): float {
        $method = $program->pointsMethod ?? null;
        $fixedCost = $program && $program->fixedCost !== null ? (float) $program->fixedCost : null;
        $pointsMin = $program && $program->pointsMin !== null ? (float) $program->pointsMin : null;

        switch ($method) {
            case 'cost_div_100':
                return ($fixedCost ?? $amountRub) / 100;
            case 'amount_div_100':
                return $amountRub / 100;
            case 'fixed':
                return (float) ($pointsMin ?? 0);
            case 'amount_x_dsPercent':
                // amount × dsPercent / 10000 — без вычета НДС. Используется
                // когда баллы партнёра должны идти от дохода ДС (а не от
                // суммы транзакции). Пример: Axevil — pointsFormula
                // «Сумма × курс / 100 × 0.03» → math: amountRub × 3 / 10000.
                return $amountRub * $dsComPercent / 10000;
            case 'amount_times_ds':
            default:
                return $amountNoVat * $dsComPercent / 10000;
        }
    }

    /**
     * Резолвит квалификацию консультанта на конкретную дату транзакции.
     *
     * Правило из спеки §7: новый процент применяется с 1-го числа
     * месяца, СЛЕДУЮЩЕГО за тем, в котором НГП пересёк порог.
     * То есть для транзакции даты `txDate` берём последнюю запись
     * qualificationLog, чья `date` строго меньше начала месяца
     * транзакции.
     *
     * Если такой записи нет (очень ранние транзакции) — используем
     * Start (fallback возвращается из null в calculateInTransaction).
     *
     * Если `txDate` не передан — поведение legacy: берём актуальное
     * значение `consultant.status_and_lvl` (нужно, когда требуется
     * "текущая" квалификация, например в отчётах). Это единственный
     * вызов без даты в коде.
     */
    private function getQualificationLevel(int $consultantId, ?string $txDate = null): ?object
    {
        if ($txDate) {
            $startOfTxMonth = \Carbon\Carbon::parse($txDate)->startOfMonth()->toDateString();

            $qLog = DB::table('qualificationLog')
                ->where('consultant', $consultantId)
                ->whereNull('dateDeleted')
                ->where('date', '<', $startOfTxMonth)
                ->orderByDesc('date')
                ->first();

            // «Единая квалификация» (spec ✅Квалификации.md §2): у партнёра в месяц
            // один уровень. Legacy-схема записывала nominalLevel и calculationLevel
            // по отдельности (calc мог быть ниже при штрафе отрыва). По новой спеке
            // выбираем максимум — штрафы применяются отдельным шагом через
            // MonthlyFinaliser, а не подменой процента в calculateForTransaction.
            $levelId = $this->resolveMaxLevel(
                $qLog->nominalLevel ?? null,
                $qLog->calculationLevel ?? null,
            );

            if ($levelId) {
                return DB::table('status_levels')->where('id', $levelId)->first();
            }
            // Fallthrough: no qualificationLog entry before this tx — partner
            // is fresh. Fall back to Start (15%) via the null return at the
            // bottom; caller substitutes the default.
            return null;
        }

        // Legacy "current qualification" path — no tx context supplied.
        $consultant = DB::table('consultant')->where('id', $consultantId)->first();
        $levelId = $consultant->status_and_lvl ?? null;

        if (! $levelId) {
            $qLog = DB::table('qualificationLog')
                ->where('consultant', $consultantId)
                ->whereNull('dateDeleted')
                ->orderByDesc('date')
                ->first();
            $levelId = $this->resolveMaxLevel(
                $qLog->nominalLevel ?? null,
                $qLog->calculationLevel ?? null,
            );
        }

        if (! $levelId) return null;

        return DB::table('status_levels')->where('id', $levelId)->first();
    }

    /**
     * Возвращает id уровня, у которого status_levels.level выше. Nullable-
     * безопасно: если один из аргументов null — возвращает второй. Используется
     * для реализации «единой квалификации» (см. spec Квалификации §2).
     */
    private function resolveMaxLevel(?int $aId, ?int $bId): ?int
    {
        if (! $aId) return $bId;
        if (! $bId) return $aId;
        if ($aId === $bId) return $aId;

        $levels = DB::table('status_levels')
            ->whereIn('id', [$aId, $bId])
            ->pluck('level', 'id');

        return ($levels[$aId] ?? 0) >= ($levels[$bId] ?? 0) ? $aId : $bId;
    }

    /**
     * Создать запись комиссии.
     */
    private function createCommission(array $data): int
    {
        return DB::table('commission')->insertGetId(array_merge($data, [
            'createdAt' => now(),
        ]));
    }

    /**
     * Транзакция привязана к «Неизвестному консультанту»:
     * ставка 0%, цепочки нет, в commission пишется 1 запись с нулём
     * (для аудит-следа), 100% Дохода ДС остаётся компании.
     */
    private function writeZeroForUnknownConsultant(int $transactionId, int $consultantId, float $personalVolume, object $tx): array
    {
        $this->createCommission([
            'transaction' => $transactionId,
            'consultant' => $consultantId,
            'chainOrder' => 1,
            'type' => 'transaction',
            'personalVolume' => round($personalVolume, 6),
            'groupVolume' => round($personalVolume, 6),
            'groupBonus' => 0,
            'groupBonusRub' => 0,
            'percent' => 0,
            'amount' => 0,
            'amountRUB' => 0,
            'amountUSD' => 0,
            'currency' => $tx->currency ?? 67,
            'date' => $tx->date,
            'dateMonth' => $tx->dateMonth,
            'dateYear' => $tx->dateYear,
            'comment' => 'Неизвестный консультант: 0% (спека ✅Бизнес-логика «Неизвестного консультанта».md)',
        ]);

        DB::table('transaction')->where('id', $transactionId)->update([
            'personalVolume' => round($personalVolume, 6),
            'groupVolume' => round($personalVolume, 6),
        ]);

        return [
            'success' => true,
            'transactionId' => $transactionId,
            'consultantId' => (int) $consultantId,
            'personalVolume' => round($personalVolume, 6),
            'commissionsCount' => 1,
            'unknownConsultant' => true,
        ];
    }
}
