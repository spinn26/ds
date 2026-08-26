<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Enums\PartnerActivity;
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

    /**
     * Исторические данные до этой даты неизменны и НЕ пересчитываются.
     * Любой движок пересчёта (каскад, финализация, пул, баланс) обязан
     * пропускать строки с датой/периодом раньше HISTORICAL_CUTOFF —
     * это защищает выгруженную из Directual историю от перезаписи.
     */
    public const HISTORICAL_CUTOFF = '2026-06-01';

    /** true, если дата (YYYY-MM-DD или YYYY-MM) относится к историческому периоду. */
    public static function isHistorical(?string $date): bool
    {
        if (! $date) return false;
        $ym = substr((string) $date, 0, 7); // YYYY-MM
        return strlen($ym) === 7 && $ym < substr(self::HISTORICAL_CUTOFF, 0, 7);
    }

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
     * Если строки за этот месяц для консультанта ещё нет — создаём её,
     * ПЕРЕНОСЯ входящее сальдо: balance ← remaining предыдущего периода
     * (payed=0, accruedPool=0). Старые строки никогда не трогает поля,
     * которые ведёт отдельная логика (payed, accruedPool).
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

    /**
     * Публичная обёртка для пересчёта consultantBalance вручную.
     * Используется при удалении транзакции: после soft-delete commission
     * метод rebuildBalancesForTransaction() не сработает (commission уже
     * отмечены deletedAt), поэтому контроллер сам собирает пары
     * (consultant, dateMonth) ДО удаления и явно вызывает этот метод.
     */
    public function rebuildBalanceFor(int $consultantId, string $dateMonth, ?string $dateYear = null): void
    {
        $this->rebuildBalance($consultantId, $dateMonth, $dateYear ?? substr($dateMonth, 0, 4));
    }

    /**
     * Самопокупка: клиент контракта — тот же человек, что и продавец.
     *
     * Возвращает id наставника (или «Неизвестного консультанта», если наставника
     * нет), на которого надо переписать сделку. null — обычная продажа.
     *
     * Спека (✅Инсмарт §2): «ФИО И мейл клиента совпадает с ФИО и мейлом Партнёра».
     * Оба условия обязательны. Только по контактам сверять НЕЛЬЗЯ: родственники
     * делят телефон/почту (Трунов А.В. -> клиент Трунова Н.В., Горелова Д.А. ->
     * клиент Горелова Н.С.), а продажа жене или сестре — обычная сделка, и снимать
     * за неё комиссию нельзя. Только по ФИО — тоже нельзя: тёзки.
     */
    private function resolveSelfPurchaseUpline(int $consultantId, ?int $clientId): ?int
    {
        if (! $clientId) {
            return null;
        }

        $client = DB::table('client')->where('id', $clientId)->first(['personName', 'email', 'phone']);
        if (! $client) {
            return null;
        }

        $seller = DB::table('consultant as cs')
            ->leftJoin('WebUser as wu', 'wu.id', '=', 'cs.webUser')
            ->where('cs.id', $consultantId)
            ->first(['cs.inviter', 'cs.personName', 'wu.email', 'wu.phone']);
        if (! $seller) {
            return null;
        }

        $normName = fn (?string $v) => ($v = preg_replace('/\s+/u', ' ', trim((string) $v))) === ''
            ? null
            : mb_strtolower(str_replace('ё', 'е', $v));
        $normEmail = fn (?string $v) => ($v = trim((string) $v)) === '' ? null : mb_strtolower($v);
        $normPhone = fn (?string $v) => ($v = preg_replace('/\D/', '', (string) $v)) === '' ? null : $v;

        // ФИО — обязательное условие.
        $clientName = $normName($client->personName);
        $sellerName = $normName($seller->personName);
        if ($clientName === null || $clientName !== $sellerName) {
            return null;
        }

        // Плюс контакт: почта или (достаточно длинный) телефон.
        $clientEmail = $normEmail($client->email);
        $sellerEmail = $normEmail($seller->email);
        $emailMatch = $clientEmail !== null && $clientEmail === $sellerEmail;

        $clientPhone = $normPhone($client->phone);
        $sellerPhone = $normPhone($seller->phone);
        $phoneMatch = $clientPhone !== null
            && strlen($clientPhone) >= 10
            && $clientPhone === $sellerPhone;

        if (! $emailMatch && ! $phoneMatch) {
            return null;
        }

        return $seller->inviter ? (int) $seller->inviter : self::UNKNOWN_CONSULTANT_ID;
    }

    /**
     * Записать начисленный лидерский пул в баланс месяца и пересобрать итоги.
     *
     * До этого пул жил только в `poolLog`: rebuildBalance() бережно сохранял
     * `accruedPool`, ожидая, что его кто-то проставит, но не проставлял никто —
     * и весь посчитанный пул не доезжал ни до «Итого начислено», ни до реестра
     * выплат. Формула баланса намеренно остаётся здесь, в одном месте.
     *
     * Зовётся из PoolRunner::persist() после записи poolLog. $poolRub = 0
     * означает «в этом месяце пула у партнёра больше нет» (пересчёт после
     * снятия галочки участия) — строку надо занулить, а не пропустить.
     */
    public function applyPoolToBalance(int $consultantId, string $dateMonth, string $dateYear, float $poolRub, bool $cascade = true): void
    {
        if (self::isHistorical($dateMonth)) {
            return;
        }

        $row = DB::table('consultantBalance')
            ->where('consultant', $consultantId)
            ->where('dateMonth', $dateMonth)
            ->first();

        // Строки за месяц может не быть вовсе: лидер мог не иметь ни одной
        // собственной комиссии и получать только пул.
        if (! $row) {
            $this->rebuildBalance($consultantId, $dateMonth, $dateYear, false);
            $row = DB::table('consultantBalance')
                ->where('consultant', $consultantId)
                ->where('dateMonth', $dateMonth)
                ->first();
            if (! $row) {
                return;
            }
        }

        $poolRub = round($poolRub, 2);
        $accruedTotal = (float) ($row->accruedTransactional ?? 0)
            + (float) ($row->accruedNonTransactional ?? 0)
            + $poolRub;
        $totalPayable = (float) ($row->balance ?? 0) + $accruedTotal;

        DB::table('consultantBalance')->where('id', $row->id)->update([
            'accruedPool' => $poolRub,
            'accruedTotal' => $accruedTotal,
            'totalPayable' => $totalPayable,
            'remaining' => $totalPayable - (float) ($row->payed ?? 0),
        ]);

        if ($cascade) {
            $ym = str_contains($dateMonth, '-') ? $dateMonth
                : sprintf('%04d-%02d', (int) $dateYear, (int) substr($dateMonth, -2));
            $this->cascadeCarryForward([$consultantId], $ym);
        }
    }

    /**
     * Пересобрать снимок `consultantBalance` за ВЕСЬ месяц: и начисления из
     * `commission`, и пул из `poolLog`.
     *
     * Зачем отдельным методом: снимок обновлялся только точечно —
     *   • финализация (ОП/отрыв) пересобирала только тех, у кого В ЭТОТ ПРОГОН
     *     появились новые удержания. Раннер идемпотентный: на повторном
     *     «пересчёте» affected=0, пересборки нет — и если снимок разошёлся
     *     раньше (сбой rebuild'а, ручная правка commission, пересчёт цепочки),
     *     он таким и оставался: удержания в строках есть, «Итого начислено» —
     *     доштрафное;
     *   • пул писался только внутри PoolRunner::persist(). Если фиксация пула
     *     упала после записи poolLog (или строку баланса создали позже), пул
     *     оставался в poolLog и не доезжал ни до реестра, ни до отчёта.
     *
     * Источник истины: commission (начисления) + poolLog (пул). Идемпотентно.
     * Исторические периоды (< HISTORICAL_CUTOFF) не трогает.
     *
     * @return array{consultants:int, pool:float}
     */
    public function resyncMonth(string $ym): array
    {
        if (self::isHistorical($ym)) {
            return ['consultants' => 0, 'pool' => 0.0];
        }

        $dateYear = substr($ym, 0, 4);

        // Пул месяца из poolLog — по границам месяца, как его пишет PoolRunner.
        $from = \Carbon\Carbon::createFromFormat('Y-m-d', $ym . '-01')->startOfMonth();
        $poolByCons = DB::table('poolLog')
            ->whereBetween('date', [$from->toDateTimeString(), $from->copy()->endOfMonth()->toDateTimeString()])
            ->selectRaw('consultant, COALESCE(SUM("poolBonus"), 0) AS s')
            ->groupBy('consultant')
            ->pluck('s', 'consultant');

        // Партнёры месяца: у кого есть commission, строка снимка или пул.
        // Строка снимка — обязательно: только так обнуляется завышенный
        // снимок партнёра, чьи commission уже soft-deleted.
        $consultants = collect(DB::select(
            'SELECT DISTINCT consultant FROM commission
               WHERE "deletedAt" IS NULL AND "dateMonth" = ? AND consultant IS NOT NULL
             UNION
             SELECT DISTINCT consultant FROM "consultantBalance"
               WHERE "dateMonth" = ? AND consultant IS NOT NULL',
            [$ym, $ym]
        ))->pluck('consultant')->map(fn ($v) => (int) $v)
            ->merge($poolByCons->keys()->map(fn ($v) => (int) $v))
            ->unique()->values();

        foreach ($consultants as $cid) {
            $this->rebuildBalance($cid, $ym, $dateYear, false);
            // Пул проставляем всегда, в том числе нулём: partнёр мог выпасть из
            // делителя при пересчёте, и старое значение нужно снять.
            $this->applyPoolToBalance($cid, $ym, $dateYear, (float) ($poolByCons[$cid] ?? 0), false);
        }

        // Одна протяжка на всех разом (cascade=false выше): иначе каждый
        // партнёр каскадировал бы дважды — после rebuild и после пула.
        $this->cascadeCarryForward($consultants->all(), $ym);

        return ['consultants' => $consultants->count(), 'pool' => (float) $poolByCons->sum()];
    }

    /**
     * @param  bool  $cascade  протянуть новое сальдо на последующие месяцы.
     *                         resyncMonth передаёт false и делает одну общую
     *                         протяжку после цикла — иначе каждый партнёр
     *                         каскадировал бы дважды (rebuild + пул).
     */
    private function rebuildBalance(int $consultantId, string $dateMonth, string $dateYear, bool $cascade = true): void
    {
        // Исторический баланс (< HISTORICAL_CUTOFF) неизменен — не перезаписываем.
        $ym = str_contains($dateMonth, '-') ? $dateMonth
            : sprintf('%04d-%02d', (int) $dateYear, (int) substr($dateMonth, -2));
        if (self::isHistorical($ym)) {
            return;
        }

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

        // Входящее сальдо = remaining ПРЕДЫДУЩЕГО периода — тот же канон, что
        // в PaymentRegistryService ($incomingByCons) и PaymentRegistryReport.
        // Раньше снимок нового месяца создавался с balance=0, а у существующей
        // строки balance не пересчитывался вовсе: с июля 2026 (первые месяцы,
        // строки за которые создаёт платформа, а не Directual) накопленный
        // остаток терялся — totalPayable/remaining в снимке падали до суммы
        // одного месяца. Реестр выплат этого не показывал, потому что считает
        // сальдо живьём, а кабинет партнёра («История по периодам») читает
        // снимок как есть — и показывал остаток без прошлых периодов.
        $incoming = $this->incomingBalance($consultantId, $ym);

        if ($row) {
            $accruedPool = (float) ($row->accruedPool ?? 0);
            $balance = $incoming;
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
                'balance' => $balance,
                'totalPayable' => $totalPayable,
                'remaining' => $remaining,
            ]);
        } else {
            // Новый месяц без записи — создаём минимальную, чтобы реестр
            // выплат и отчёт могли её прочитать. accruedPool/payed = 0 по
            // умолчанию, их при необходимости проставит пул-runner; balance
            // переносим из предыдущего периода (см. $incoming выше).
            $accruedTotal = $accruedTransactional + $accruedNonTransactional;
            DB::table('consultantBalance')->insert([
                'consultant' => $consultantId,
                'dateMonth' => $dateMonth,
                'dateYear' => $dateYear,
                'accruedTransactional' => $accruedTransactional,
                'accruedNonTransactional' => $accruedNonTransactional,
                'accruedPool' => 0,
                'accruedTotal' => $accruedTotal,
                'balance' => $incoming,
                'payed' => 0,
                'totalPayable' => $incoming + $accruedTotal,
                'remaining' => $incoming + $accruedTotal,
                'dateCreated' => now(),
            ]);
        }

        if ($cascade) {
            $this->cascadeCarryForward([$consultantId], $ym);
        }
    }

    /**
     * Входящее сальдо периода = remaining последнего периода СТРОГО ДО $ym.
     * DISTINCT ON — один проход вместо коррелированного подзапроса (тот же
     * приём, что в PaymentRegistryService).
     */
    private function incomingBalance(int $consultantId, string $ym): float
    {
        $row = DB::selectOne(
            'SELECT remaining FROM "consultantBalance"
              WHERE consultant = ? AND "dateMonth" < ? AND "dateMonth" LIKE \'____-__\'
              ORDER BY "dateMonth" DESC LIMIT 1',
            [$consultantId, $ym]
        );

        return (float) ($row->remaining ?? 0);
    }

    /**
     * Протянуть изменившееся сальдо ВПЕРЁД по всем последующим периодам
     * партнёра: balance ← remaining предыдущего месяца, затем totalPayable
     * и remaining пересчитываются от него.
     *
     * Зачем: любая правка месяца M (пересчёт комиссии, пул, запись выплаты)
     * меняет remaining месяца M, но balance месяца M+1 оставался прежним —
     * снимок расходился с цепочкой до следующего полного ресинка. Реестр
     * выплат читает сохранённый remaining прошлого месяца, поэтому протухание
     * тянулось дальше по цепочке.
     *
     * ⚠ Начисления НЕ трогаем — accruedTransactional/NonTransactional/Pool и
     * accruedTotal берутся как есть. Это чисто бухгалтерская протяжка переноса,
     * а не пересчёт денег: принцип «деньги считаются по кнопке» не нарушается.
     *
     * ⚠ `status` тоже не трогаем: он ведётся платёжным контуром
     * (AdminPaymentRegistryController::recalcBalance), и перезапись его отсюда
     * пометила бы «В обработке» месяцы, к которым выплат никто не касался.
     *
     * Затравка — сохранённый remaining последнего периода <= $afterYm, а не
     * пересчёт цепочки с нуля: исторические месяцы (< HISTORICAL_CUTOFF)
     * заморожены и остаются источником истины для первого открытого месяца.
     *
     * @param  int[]  $consultantIds
     * @return int    сколько строк обновлено
     */
    public function cascadeCarryForward(array $consultantIds, string $afterYm): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $consultantIds))));
        if (! $ids || ! preg_match('/^\d{4}-\d{2}$/', $afterYm)) {
            return 0;
        }

        $cutoffYm = substr(self::HISTORICAL_CUTOFF, 0, 7);
        $updated = 0;

        foreach (array_chunk($ids, 5000) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));

            // Одним set-based запросом на всю пачку партнёров: оконная сумма
            // (accruedTotal - payed) по месяцам ПОСЛЕ $afterYm, сдвинутая на
            // строку назад, — это и есть входящее сальдо каждого периода.
            $updated += DB::update("
                WITH seed AS (
                    SELECT DISTINCT ON (consultant) consultant, COALESCE(remaining, 0) AS rem
                      FROM \"consultantBalance\"
                     WHERE consultant IN ($ph)
                       AND \"dateMonth\" LIKE '____-__'
                       AND \"dateMonth\" <= ?
                     ORDER BY consultant, \"dateMonth\" DESC
                ), later AS (
                    SELECT id, consultant, \"dateMonth\",
                           COALESCE(\"accruedTotal\", 0) AS acc,
                           COALESCE(payed, 0) AS pay
                      FROM \"consultantBalance\"
                     WHERE consultant IN ($ph)
                       AND \"dateMonth\" LIKE '____-__'
                       AND \"dateMonth\" > ?
                ), chain AS (
                    SELECT l.id, l.\"dateMonth\", l.acc, l.pay,
                           COALESCE(s.rem, 0) + COALESCE(SUM(l.acc - l.pay) OVER (
                               PARTITION BY l.consultant ORDER BY l.\"dateMonth\"
                               ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING
                           ), 0) AS incoming
                      FROM later l
                      LEFT JOIN seed s ON s.consultant = l.consultant
                )
                UPDATE \"consultantBalance\" b
                   SET balance = c.incoming,
                       \"totalPayable\" = c.incoming + c.acc,
                       remaining = c.incoming + c.acc - c.pay
                  FROM chain c
                 WHERE b.id = c.id
                   AND c.\"dateMonth\" >= ?
                   -- Допуск в полкопейки: колонки numeric, но PHP пишет в них
                   -- float, и round-trip даёт хвосты ~1e-10. Без допуска каскад
                   -- переписывал бы десятки строк «изменениями» на 0.0000000001.
                   AND (abs(COALESCE(b.balance, 0) - c.incoming) > 0.005
                     OR abs(COALESCE(b.\"totalPayable\", 0) - (c.incoming + c.acc)) > 0.005
                     OR abs(COALESCE(b.remaining, 0) - (c.incoming + c.acc - c.pay)) > 0.005)
            ", [...$chunk, $afterYm, ...$chunk, $afterYm, $cutoffYm]);
        }

        return $updated;
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

        // Защита ДО любого изменения commission (раньше freeze стоял ПОСЛЕ
        // soft-delete → для закрытого периода commission удалялся, затем метод
        // бейлил, оставляя 0 строк — потеря данных).
        //
        // 1) Исторические данные (< HISTORICAL_CUTOFF) неизменны.
        $txDate = $tx->date ?: ($tx->dateMonth && str_contains((string) $tx->dateMonth, '-') ? $tx->dateMonth . '-01' : null);
        if (self::isHistorical($txDate)) {
            return ['error' => 'Транзакция до ' . self::HISTORICAL_CUTOFF . ' — исторические данные не пересчитываются'];
        }
        // 2) Заморозка закрытого месяца. dateMonth = 'YYYY-MM' → берём 2 последних символа.
        $txMonth = $tx->dateMonth ? (int) substr((string) $tx->dateMonth, -2) : null;
        $txYear  = $tx->dateYear ? (int) $tx->dateYear : null;
        if ($txYear && $txMonth && $this->periodFreeze->isFrozen($txYear, $txMonth)) {
            return ['error' => "Период {$tx->dateMonth} закрыт — комиссии не пересчитываются"];
        }

        $contract = DB::table('contract')->where('id', $tx->contract)->whereNull('deletedAt')->first();
        if (! $contract) return ['error' => 'Контракт не найден или удалён'];

        $consultantId = $contract->consultant;
        if (! $consultantId) return ['error' => 'Консультант не привязан'];

        // САМОПОКУПКА: партнёр — сам себе клиент. По спеке он в этой сделке клиент
        // ВЫШЕСТОЯЩЕГО: продавцом (chainOrder=1) становится наставник, ему же идут
        // ЛП. Сам себе партнёр комиссию не генерирует — иначе он мог бы и
        // «наактивить» себе 500 ЛП собственной покупкой.
        $selfPurchaseUpline = $this->resolveSelfPurchaseUpline((int) $consultantId, $contract->client ? (int) $contract->client : null);
        if ($selfPurchaseUpline !== null) {
            $consultantId = $selfPurchaseUpline;
        }

        $consultant = DB::table('consultant')->where('id', $consultantId)->whereNull('dateDeleted')->first();
        if (! $consultant) return ['error' => 'Консультант не найден или удалён'];

        // Курс валюты
        $currencyRate = (float) ($tx->currencyRate ?? 1);
        $amountRub = (float) ($tx->amountRUB ?? ((float) ($tx->amount ?? 0) * $currencyRate));

        // НДС — по ДАТЕ ТРАНЗАКЦИИ, а не now(). При пересчёте старой сделки
        // после смены ставки НДС база всех комиссий (amountNoVat) должна
        // считаться по ставке, действовавшей на дату сделки. Закрытые периоды
        // при этом не пересчитываются (защита period_closures/HISTORICAL_CUTOFF).
        //
        // Отсутствие ставки на дату — ошибка данных, а не «ставка 0»: раньше
        // `?? 0` превращал amountRUB в «сумму без НДС», завышая базу на всю
        // ставку, и это уезжало в доход ДС, ЛП, комиссии всей цепочки и базу
        // пула. Молчать здесь нельзя ровно по той же причине, по которой ниже
        // отключён фолбэк «%ДС = 100%».
        $vatDate = $tx->date ?? now();
        try {
            $vatPercent = \App\Support\VatRate::percentOrFail($vatDate);
        } catch (\RuntimeException $e) {
            return ['error' => $e->getMessage()];
        }
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

        // «Своя комиссия»: оператор ввёл Доход ДС вручную (dsCommissionAbsolute —
        // хранится БЕЗ НДС). %ДС выводим обратным расчётом от суммы без НДС,
        // иначе при dsCommissionPercentage<=0 калькулятор берёт тариф/дефолт 100%
        // и затирает ручной доход (жалоба «Брокер+ после фиксации всё 100%»,
        // 2026-07-08). Приоритет — выше тарифа/дефолта.
        // Сравнение с 0, а не «> 0»: у СТОРНО (возврат) и сумма, и доход ДС
        // отрицательные. Прежнее условие на таких сделках не срабатывало, ручной
        // доход ДС затирался тарифом. %ДС при этом остаётся положительным
        // (минус на минус), что и правильно — знак несёт сумма.
        if (! empty($tx->customCommission)
            && abs((float) ($tx->dsCommissionAbsolute ?? 0)) > 0.000001
            && abs($amountNoVat) > 0.000001
        ) {
            // ⚠ 8 знаков, а не 4. Доход ДС считается обратно из этой ставки
            // (commissionsAmountRUB = amountNoVat × %ДС / 100), поэтому грубое
            // округление уводит введённую вручную сумму: на базе 262 658 ₽
            // четыре знака давали 999,94 ₽ вместо ровных 1 000,00 ₽.
            $dsComPercent = round((float) $tx->dsCommissionAbsolute / $amountNoVat * 100, 8);
        }

        // Property-specific тариф ДОЛЖЕН побеждать scalar program.dsPercent,
        // когда у транзакции задано свойство (commissionCalcProperty).
        // program.dsPercent — одно значение (обычно МФ) и не различает свойства:
        // для IB (МФ 30% vs Апфронт 1.8%) применение program.dsPercent давало
        // 30% на Апфронт-транзакции. Полный ключ (program × term × свойство ×
        // окно дат) — в resolveLegacyDsCommission.
        if ($dsComPercent <= 0 && ($tx->commissionCalcProperty ?? null) !== null && $contract->program) {
            $byProperty = self::resolveLegacyDsCommission(
                (int) $contract->program,
                $contract->term ?? null,
                $tx->commissionCalcProperty,
                $tx->date ?? null,
            );
            if ($byProperty !== null && $byProperty > 0) {
                $dsComPercent = (float) $byProperty;
            }
        }
        if ($dsComPercent <= 0 && $programRow && $programRow->dsPercent !== null) {
            $dsComPercent = (float) $programRow->dsPercent;
        }
        if ($dsComPercent <= 0 && $contract->program) {
            // Fallback без свойства — для программ без property-специфичных тарифов.
            // Полный ключ program × term × год КВ × окно дат (см.
            // resolveLegacyDsCommission), не «последняя по id».
            $dsComPercent = (float) (self::resolveLegacyDsCommission(
                (int) $contract->program,
                $contract->term ?? null,
                $tx->commissionCalcProperty ?? null,
                $tx->date ?? null,
            ) ?? 0);
        }

        if ($dsComPercent <= 0) {
            // Тариф не найден НИ В ОДНОМ источнике (своя комиссия -> тариф по
            // свойству -> program.dsPercent -> legacy dsCommission).
            //
            // Раньше здесь стоял фолбэк 100% (commission.default_ds_percent): весь
            // доход без НДС объявлялся доходом ДС, и комиссии цепочки платились с
            // полной суммы контракта — завышение в 10-30 раз (кейс «Брокер+»).
            // 100% не является правдоподобной ставкой ни для одного продукта, так
            // что отсутствие тарифа — это ошибка данных, и она должна быть видна,
            // а не молча оплачена.
            return ['error' => 'Не найден тариф %ДС (программа/свойство/срок). Заведите тариф — расчёт по умолчанию 100% отключён.'];
        }

        // ⚠ ТОЛЬКО ЗДЕСЬ удаляем ранее посчитанные commission — после ВСЕХ
        // проверок, ни одной строкой раньше.
        //
        // Нужно это против дублей: повторный вызов (правка транзакции, бэкфилл,
        // ручное «Пересчитать») иначе плодит вторую цепочку — наблюдалось 6
        // строк вместо 3 на tx#60708 после бэкфилла 2026-05-23.
        //
        // А стояло удаление ВЫШЕ проверок контракта, консультанта, НДС и
        // тарифа. Метод возвращает ошибку обычным return, то есть внешняя
        // DB::transaction КОММИТИТСЯ — и любая из этих ошибок оставляла
        // транзакцию вообще без комиссий: партнёр молча терял начисление, а
        // вызывающий получал строку с ошибкой, которую, например,
        // TransactionImportController::update просто писал в лог.
        // Ровно этот класс бага уже чинили для заморозки и истории (см. выше),
        // но остальные проверки тогда не тронули.
        DB::table('commission')
            ->where('transaction', $transactionId)
            ->whereNull('deletedAt')
            ->update(['deletedAt' => now()]);

        // ЛП per program.pointsMethod, same switch as CalculatorController::computePoints.
        $personalVolume = $this->computePointsForProgram(
            $programRow, $amountNoVat, $amountRub, $dsComPercent,
            $contract->term !== null ? (float) $contract->term : null
        );

        // Per spec ✅Бизнес-логика «Неизвестного консультанта».md:
        // если контракт привязан к плейсхолдер-аккаунту, ставка = 0%
        // и каскад не строится — 100% дохода остаётся компании.
        if ((int) $consultantId === self::UNKNOWN_CONSULTANT_ID) {
            return $this->writeZeroForUnknownConsultant($transactionId, $consultantId, $personalVolume, $tx, $amountNoVat, $dsComPercent);
        }

        // Получить квалификацию прямого партнёра на момент транзакции.
        // Per spec §7: a new rate takes effect from the 1st of the month AFTER
        // the one in which the НГП threshold was crossed. Passing tx->date to
        // the resolver below gives each historical transaction its own rate.
        $qualLevel = $this->getQualificationLevel($consultantId, $tx->date);
        // Стартовый % (нет квалификации) — настраивается (commission.startup_percent, фолбэк 15).
        $startupPercent = (float) \App\Models\SystemSetting::value('commission.startup_percent', 15);
        $qualPercent = $qualLevel ? (float) $qualLevel->percent : $startupPercent;

        // Групповой бонус = ЛП * % квалификации / 100
        $groupBonus = $personalVolume * $qualPercent / 100;
        $groupBonusRub = $groupBonus * 100; // 1 балл = 100 руб

        $commissions = [];
        // Σ всех комиссий цепочке в рублях — для transaction.netRevenueRUB.
        // Раньше считалось через array_map по $commissions, но createCommission
        // возвращает int (insert id), не массив data — sum получался 0.
        $chainTotalRub = 0.0;

        // 1. Комиссия прямого партнёра (chainOrder = 1).
        // Терминированных (3) / исключённых (5) пропускаем: они не получают
        // начислений ни прямым партнёром, ни наставником. ЛП/проценты выше
        // уже посчитаны (нужны как база для каскада), но commission-строку не
        // создаём и в chainTotalRub не добавляем — «доля» остаётся у компании
        // (увеличивает netRevenue/profit). Проценты цепочки не меняются.
        if (! $this->isInactiveForCommission($consultant->activity ?? null)) {
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
        }

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

            // whereNull('dateDeleted'): мягко удалённый наставник — это
            // удалённая запись, а не «проходной» участник цепочки. Без фильтра
            // он получал строку commission (и деньги, если activity ∉ {3,5}),
            // хотя во всех выборках платформы его уже нет.
            $inviter = DB::table('consultant')
                ->where('id', $inviterId)
                ->whereNull('dateDeleted')
                ->first();
            if (! $inviter) break;

            $inviterLevel = $this->getQualificationLevel($inviterId, $tx->date);
            $inviterPercent = $inviterLevel ? (float) $inviterLevel->percent : $startupPercent;

            // Маржинальная разница — разница процентов между наставником и нижестоящим
            $marginPercent = $inviterPercent - $prevPercent;

            // Бонус начисляем ТОЛЬКО при положительной марже и активном наставнике.
            // Терминированный/исключённый или нулевая маржа → бонус 0 (его «слой»
            // поглощается компанией, chainTotalRub не растёт).
            $paid = $marginPercent > 0 && ! $this->isInactiveForCommission($inviter->activity ?? null);
            $inviterBonus = $paid ? $personalVolume * $marginPercent / 100 : 0.0;
            $inviterBonusRub = $inviterBonus * 100;
            if ($paid) {
                $chainTotalRub += round($inviterBonusRub, 2);
            }

            // Строку цепочки создаём для КАЖДОГО наставника — «Цепочка выплат»
            // должна показывать ВСЮ цепочку целиком (просьба владельца 2026-07-08),
            // а не только получателей маржи. Проходной наставник (нулевая маржа /
            // терминированный) → строка с комиссией 0, но с его ГП (groupVolume).
            // Деньги (chainTotalRub/прибыль) от этого не меняются.
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
                'percent' => $paid ? $marginPercent : 0,
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
        // USD-зеркала — по курсу МЕСЯЦА СДЕЛКИ (спека «Валюты и НДС»: справочник
        // хранит средневзвешенный курс за месяц). Раньше брался последний курс
        // справочника, и пересчёт старой сделки конвертировал её по свежему курсу.
        $incomeDsRub = round($amountNoVat * $dsComPercent / 100, 2);
        $netRevenueRub = round($amountNoVat - $chainTotalRub, 2);

        $usdRate = \App\Support\CurrencyRates::usdForDate($tx->date ?? null);
        $incomeDsUsd = $usdRate > 0 ? round($incomeDsRub / $usdRate, 2) : 0;
        $netRevenueUsd = $usdRate > 0 ? round($netRevenueRub / $usdRate, 2) : 0;

        // Доход ДС в ВАЛЮТЕ КОНТРАКТА (ТЗ 2026-08-07). Отдельно от *USD:
        // то зеркало всегда делит на курс доллара и для евровых сделок
        // показывает долларовый эквивалент. Курс — валюты транзакции на месяц
        // сделки, тем же справочником, что и amountRUB, поэтому обратный
        // пересчёт сходится. Для рублёвых значение равно рублёвому (курс 1).
        $txRate = \App\Support\CurrencyRates::forDate(
            $tx->currency !== null ? (int) $tx->currency : null,
            $tx->date ?? null,
        );
        $incomeDsCurrency = $txRate > 0 ? round($incomeDsRub / $txRate, 2) : null;

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
            'commissionsAmountCurrency' => $incomeDsCurrency,
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
        // whereNull('deletedAt') — удалённые строки импорта считать нечего:
        // calculateInTransaction их всё равно отбросит, но раньше они падали
        // в счётчик 'errors' и оператор видел мнимые ошибки импорта.
        $transactions = DB::table('transaction')
            ->where('comment', 'Импорт #' . $importId)
            ->whereNull('deletedAt')
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
     * Терминированный (3) / Исключённый (5) партнёр не получает комиссию —
     * ни прямым, ни наставником в каскаде (правило «пропускать везде»).
     * activity хранится в consultant.activity как int; null/неизвестное
     * трактуем как активного (безопаснее начислить, чем ошибочно срезать).
     */
    private function isInactiveForCommission(int|string|null $activity): bool
    {
        return in_array((int) $activity, [
            PartnerActivity::Terminated->value,
            PartnerActivity::Excluded->value,
        ], true);
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
        ?float $term = null,
    ): float {
        return self::computePoints($program, $amountNoVat, $amountRub, $dsComPercent, $term);
    }

    /** Публичный расчёт ЛП по методике программы (для отчётов/прогноза). */
    public static function computePoints(
        ?object $program,
        float $amountNoVat,
        float $amountRub,
        float $dsComPercent,
        ?float $term = null,
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
            case 'annualized_term':
                // Vantage Platinum II (Hansard): ЛП = (ежемесячный взнос × 12 ×
                // срок × %ДС/100) / 100 = amountRub × 12 × срок × %ДС / 10000.
                // amountRub — взнос в рублях; срок — из контракта.
                return $amountRub * 12 * (float) ($term ?? 0) * $dsComPercent / 10000;
            case 'amount_x_dsPercent':
                // ЛП = Доход ДС без НДС / 100 = amountNoVat × %ДС / 10000.
                // Раньше брали amountRub (с НДС) — Axevil расходился с Медлайфом
                // (default). По правилу проекта ЛП считается от «Дохода ДС без НДС»
                // для ВСЕХ продуктов (фидбек владельца 2026-07-08).
                return $amountNoVat * $dsComPercent / 10000;
            case 'amount_times_ds':
            default:
                return $amountNoVat * $dsComPercent / 10000;
        }
    }

    /**
     * Resolve the legacy dsCommission %ДС for a program by the full tariff key
     * (program × termContract × commissionCalcProperty(год КВ) × окно дат),
     * with progressive relaxation when the narrowest match is empty:
     *   term+год+date → год+date → term+date → date → none (последняя по id).
     *
     * Год КВ — самый сильный дискриминатор (напр. программа 180: 0.5% при
     * год=9 vs 2% при год=10), поэтому держим его до отбрасывания term; окно
     * дат снимаем в последнюю очередь. Используется и каскадом, и preview в
     * ManualTransactionController — чтобы превью совпадало с фактом. Зеркалит
     * выбор в productRates(). Возвращает null, если активной строки нет.
     */
    public static function resolveLegacyDsCommission(int $program, $term, $yearKv, ?string $date = null): ?float
    {
        $term = ($term === null || $term === '') ? null : (int) $term;
        $yearKv = ($yearKv === null || $yearKv === '') ? null : (int) $yearKv;
        $date = $date ?: now()->toDateString();

        $build = function (bool $withTerm, bool $withYear, bool $withDate) use ($program, $term, $yearKv, $date) {
            return DB::table('dsCommission')
                ->where('program', $program)
                ->where('active', true)
                ->whereNull('dateDeleted')
                ->when($withTerm && $term !== null, fn ($q) => $q->where('termContract', $term))
                ->when($withYear && $yearKv !== null, fn ($q) => $q->where('commissionCalcProperty', $yearKv))
                ->when($withDate, fn ($q) => $q
                    ->where('date', '<=', $date)->where('dateFinish', '>=', $date))
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->first();
        };

        $row = $build(true, true, true)
            ?: $build(false, true, true)
            ?: $build(true, false, true)
            ?: $build(false, false, true)
            ?: $build(false, false, false);

        return $row ? (float) ($row->comission ?? 0) : null;
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
    /**
     * Уровень + стартовый % для ПРЕВЬЮ ручной транзакции — теми же правилами,
     * что и факт: максимум nominalLevel/calculationLevel по последнему
     * qualificationLog до месяца сделки (getQualificationLevel), стартовый % —
     * из настройки commission.startup_percent. Раньше превью брало
     * nominalLevel ?? calculationLevel и хардкод 15 — расходилось с фактическим
     * начислением (пункт «предпросмотр ≠ факт»).
     *
     * @return array{percent: float, levelId: ?int}
     */
    public function resolveLevelForPreview(int $consultantId, ?string $txDate): array
    {
        $startup = (float) \App\Models\SystemSetting::value('commission.startup_percent', 15);
        if (! $txDate) {
            return ['percent' => $startup, 'levelId' => null];
        }
        $level = $this->getQualificationLevel($consultantId, $txDate);

        return [
            'percent' => $level ? (float) ($level->percent ?? $startup) : $startup,
            'levelId' => $level?->id,
        ];
    }

    private function getQualificationLevel(int $consultantId, ?string $txDate = null): ?object
    {
        if ($txDate) {
            // Уровень для сделок месяца M = ВХОДНОЙ уровень месяца: открывающая
            // строка qualificationLog, датированная 1-м числом M (Directual писал
            // её на начало месяца, отражая уровень, с которым партнёр ВХОДИТ в
            // месяц; сдвиг МСК даёт ей время 03:00).
            //
            // Прежняя граница `date < M-01` отсекала эту строку (03:00 > 00:00) и
            // брала уровень ПРЕДЫДУЩЕГО месяца — после повышения партнёр считался
            // на уровень ниже (в карточке ФК, а комиссии по Эксперту). Граница —
            // конец 1-го дня месяца: включает открывающую строку M, но исключает
            // закрывающую строку M (23:59:59 последнего дня — это ИТОГ месяца,
            // для сделок этого же месяца её брать нельзя, иначе циклично).
            $boundary = \Carbon\Carbon::parse($txDate)->startOfMonth()->addDay()->toDateTimeString();

            $qLog = DB::table('qualificationLog')
                ->where('consultant', $consultantId)
                ->whereNull('dateDeleted')
                ->whereRaw('date::timestamp < ?::timestamp', [$boundary])
                ->orderByRaw('date::timestamp DESC')
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
    private function writeZeroForUnknownConsultant(int $transactionId, int $consultantId, float $personalVolume, object $tx, float $amountNoVat = 0.0, float $dsComPercent = 0.0): array
    {
        // Доход ДС (без НДС) считаем и для неизвестного ФК — комиссия партнёрам 0,
        // поэтому весь доход остаётся компанией: прибыль = Доход ДС без НДС
        // (фидбек владельца 2026-07-08). Раньше эти поля не обновлялись → в
        // отчётах у неизвестных ФК Доход ДС/прибыль были пустыми/устаревшими.
        $incomeDsRub = round($amountNoVat * $dsComPercent / 100, 2);
        // USD — по курсу месяца сделки (см. CurrencyRates).
        $usdRate = \App\Support\CurrencyRates::usdForDate($tx->date ?? null);
        $incomeDsUsd = $usdRate > 0 ? round($incomeDsRub / $usdRate, 2) : 0;
        // Доход ДС в валюте контракта — как в основной ветке.
        $txRate = \App\Support\CurrencyRates::forDate(
            $tx->currency !== null ? (int) $tx->currency : null,
            $tx->date ?? null,
        );
        $incomeDsCurrency = $txRate > 0 ? round($incomeDsRub / $txRate, 2) : null;
        // ЛП/ГП у Неизвестного консультанта = 0: это плейсхолдер, реального
        // партнёра нет — начислять личный/групповой объём (баллы квалификации)
        // не за кого (фидбек владельца 2026-07-09, робо-импорт). Доход ДС при
        // этом остаётся (компания удерживает 100%).
        $this->createCommission([
            'transaction' => $transactionId,
            'consultant' => $consultantId,
            'chainOrder' => 1,
            'type' => 'transaction',
            'personalVolume' => 0,
            'groupVolume' => 0,
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
            'personalVolume' => 0,
            'groupVolume' => 0,
            'dsCommissionPercentage' => round($dsComPercent, 4),
            'commissionsAmountRUB' => $incomeDsRub,      // Доход ДС без НДС
            'commissionsAmountUSD' => $incomeDsUsd,
            'commissionsAmountCurrency' => $incomeDsCurrency,
            'netRevenueRUB' => round($amountNoVat, 2),   // остаток (комиссий 0)
            // netRevenueUSD раньше здесь не обновлялся — оставалось устаревшее
            // значение с прошлого расчёта, хотя рублёвое перезаписывалось.
            'netRevenueUSD' => $usdRate > 0 ? round($amountNoVat / $usdRate, 2) : 0,
            'profitRUB' => $incomeDsRub,                 // прибыль = Доход ДС без НДС
            'commissionAmountRubBeforeGapReduction' => 0,
            'profitRubBeforeGapReduction' => $incomeDsRub,
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
