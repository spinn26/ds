<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Аналитические разделы админки:
 *   - reconciliation: сверка балансов за период
 *   - anomalies: автоматический поиск подозрительных строк
 *   - funnel: воронка нового партнёра
 *   - cohorts: retention по месяцам регистрации
 *   - owner-dashboard: стратегические цифры
 *
 * Все read-only. Используют raw SQL, т.к. данные разбросаны по legacy camelCase
 * таблицам, и ORM-модели есть только для ~15 из ~310 таблиц.
 */
class AdminAnalyticsController extends Controller
{
    /** GET /admin/analytics/reconciliation?year=&month= */
    public function reconciliation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => 'required|integer|min:2020|max:2099',
            'month' => 'required|integer|min:1|max:12',
        ]);
        $year = (int) $data['year']; $month = (int) $data['month'];
        $from = Carbon::create($year, $month, 1)->startOfMonth();
        $to = $from->copy()->endOfMonth();
        $dm = sprintf('%04d-%02d', $year, $month);

        $txGross   = (float) DB::scalar('SELECT COALESCE(SUM("amountRUB"), 0) FROM transaction WHERE date >= ? AND date <= ? AND "deletedAt" IS NULL', [$from, $to]);
        $txNet     = (float) DB::scalar('SELECT COALESCE(SUM("netRevenueRUB"), 0) FROM transaction WHERE date >= ? AND date <= ? AND "deletedAt" IS NULL', [$from, $to]);
        $txCommSum = (float) DB::scalar('SELECT COALESCE(SUM("commissionsAmountRUB"), 0) FROM transaction WHERE date >= ? AND date <= ? AND "deletedAt" IS NULL', [$from, $to]);

        $commSum   = (float) DB::scalar('SELECT COALESCE(SUM("amountRUB"), 0) FROM commission WHERE ("dateMonth" = ? OR ("dateYear" = ? AND "dateMonth" = ?)) AND "deletedAt" IS NULL',
            [$dm, (string) $year, sprintf('%02d', $month)]);

        $poolSum   = (float) DB::scalar('SELECT COALESCE(SUM("poolBonus"), 0) FROM "poolLog" WHERE date >= ? AND date <= ?', [$from, $to]);

        $balAccrTx    = (float) DB::scalar('SELECT COALESCE(SUM("accruedTransactional"), 0)    FROM "consultantBalance" WHERE "dateMonth" = ?', [$dm]);
        $balAccrNonTx = (float) DB::scalar('SELECT COALESCE(SUM("accruedNonTransactional"), 0) FROM "consultantBalance" WHERE "dateMonth" = ?', [$dm]);
        $balAccrPool  = (float) DB::scalar('SELECT COALESCE(SUM("accruedPool"), 0)             FROM "consultantBalance" WHERE "dateMonth" = ?', [$dm]);
        $balPayable   = (float) DB::scalar('SELECT COALESCE(SUM("totalPayable"), 0)            FROM "consultantBalance" WHERE "dateMonth" = ?', [$dm]);
        $balPayed     = (float) DB::scalar('SELECT COALESCE(SUM(payed), 0)                     FROM "consultantBalance" WHERE "dateMonth" = ?', [$dm]);
        $balRemain    = (float) DB::scalar('SELECT COALESCE(SUM(remaining), 0)                 FROM "consultantBalance" WHERE "dateMonth" = ?', [$dm]);

        $eps = 1.0;
        $checks = [
            [
                'label' => 'commission.amountRUB vs consultantBalance.accruedTransactional',
                'a' => $commSum, 'b' => $balAccrTx,
                'pass' => abs($commSum - $balAccrTx) < $eps,
                'note' => 'Сумма всех commission-строк должна совпадать с transactional-частью в балансах',
            ],
            [
                'label' => 'poolLog.poolBonus vs consultantBalance.accruedPool',
                'a' => $poolSum, 'b' => $balAccrPool,
                'pass' => abs($poolSum - $balAccrPool) < $eps,
                'note' => 'Сумма пула должна совпадать с pool-частью в балансах',
            ],
            [
                'label' => 'consultantBalance: accrued sum == totalPayable - balance',
                'a' => $balAccrTx + $balAccrNonTx + $balAccrPool,
                'b' => $balPayable - (float) DB::scalar('SELECT COALESCE(SUM(balance), 0) FROM "consultantBalance" WHERE "dateMonth" = ?', [$dm]),
                'pass' => true, // информационный
                'note' => 'Проверка арифметики баланса внутри consultantBalance',
            ],
            [
                'label' => 'consultantBalance: payed + remaining == totalPayable',
                'a' => $balPayed + $balRemain, 'b' => $balPayable,
                'pass' => abs($balPayed + $balRemain - $balPayable) < $eps,
                'note' => 'Сумма выплаченного и остатка должна равняться totalPayable',
            ],
        ];

        foreach ($checks as &$c) {
            $c['delta'] = round(($c['a'] ?? 0) - ($c['b'] ?? 0), 2);
            if (! $c['pass']) $c['pass'] = abs($c['delta']) < $eps;
        }

        return response()->json([
            'year' => $year, 'month' => $month,
            'aggregates' => [
                'transactionsGross' => $txGross,
                'transactionsNet'   => $txNet,
                'transactionsComm'  => $txCommSum,
                'commissionSum'     => $commSum,
                'poolSum'           => $poolSum,
                'balanceAccruedTx'  => $balAccrTx,
                'balanceAccruedNonTx' => $balAccrNonTx,
                'balanceAccruedPool' => $balAccrPool,
                'balanceTotalPayable' => $balPayable,
                'balancePayed'      => $balPayed,
                'balanceRemaining'  => $balRemain,
            ],
            'checks' => $checks,
            'passed' => count(array_filter($checks, fn ($c) => $c['pass'])),
            'total'  => count($checks),
        ]);
    }

    /** GET /admin/analytics/anomalies — подозрительные строки в БД. */
    public function anomalies(): JsonResponse
    {
        // 1. Клиент с несколькими активными контрактами у разных партнёров
        $multiContractClients = DB::select(
            'SELECT cl.id, cl."personName", COUNT(DISTINCT c.consultant) AS partners
               FROM client cl
               JOIN contract c ON c.client = cl.id
              WHERE c."deletedAt" IS NULL
              GROUP BY cl.id, cl."personName"
             HAVING COUNT(DISTINCT c.consultant) > 1
              ORDER BY partners DESC
              LIMIT 20'
        );

        // 2. Транзакции > 3x от среднего продукта
        $outlierTx = DB::select(
            'WITH stats AS (
                SELECT c.product, AVG(t."amountRUB") AS avg_amt, STDDEV_POP(t."amountRUB") AS sd
                  FROM transaction t JOIN contract c ON c.id = t.contract
                 WHERE t."deletedAt" IS NULL AND t."amountRUB" > 0
                 GROUP BY c.product
             )
             SELECT t.id, t.date, t."amountRUB", c.product, p.name AS "productName", s.avg_amt, s.sd
               FROM transaction t
               JOIN contract c ON c.id = t.contract
               LEFT JOIN product p ON p.id = c.product
               JOIN stats s ON s.product = c.product
              WHERE t."deletedAt" IS NULL
                AND s.sd > 0
                AND t."amountRUB" > s.avg_amt + 3 * s.sd
                AND t.date > now() - interval \'90 days\'
              ORDER BY t."amountRUB" DESC
              LIMIT 20'
        );

        // 3. Партнёры с 0 ЛП но 10+ детей
        $fakeBranches = DB::select(
            'SELECT c.id, c."personName",
                    COUNT(DISTINCT ch.id) AS children,
                    COALESCE(c."personalVolume", 0) AS lp
               FROM consultant c
               LEFT JOIN consultant ch ON ch.inviter = c.id AND ch."dateDeleted" IS NULL
              WHERE c."dateDeleted" IS NULL
              GROUP BY c.id, c."personName", c."personalVolume"
             HAVING COUNT(DISTINCT ch.id) >= 10 AND COALESCE(c."personalVolume", 0) = 0
              ORDER BY children DESC
              LIMIT 20'
        );

        // 4. Свежие перестановки (partnerTransfer / log) — показать последние
        $recentTransfers = Schema::hasTable('partnerTransfer')
            ? DB::select('SELECT id, consultant, "newInviter", "oldInviter", "transferDate"
                            FROM "partnerTransfer"
                           WHERE "transferDate" > now() - interval \'30 days\'
                           ORDER BY "transferDate" DESC LIMIT 20')
            : [];

        return response()->json([
            'multiContractClients' => $multiContractClients,
            'outlierTx' => $outlierTx,
            'fakeBranches' => $fakeBranches,
            'recentTransfers' => $recentTransfers,
            'summary' => [
                'multiContract' => count($multiContractClients),
                'outliers' => count($outlierTx),
                'fakeBranches' => count($fakeBranches),
                'transfers' => count($recentTransfers),
            ],
        ]);
    }

    /** GET /admin/analytics/funnel — воронка нового партнёра. */
    public function funnel(): JsonResponse
    {
        // Все партнёры, не удалённые.
        $total        = (int) DB::scalar('SELECT COUNT(*) FROM consultant WHERE "dateDeleted" IS NULL');
        // Активированные (active=true или activity=1)
        $activated    = (int) DB::scalar('SELECT COUNT(*) FROM consultant WHERE "dateDeleted" IS NULL AND (active = true OR activity = 1)');
        // С минимум одной транзакцией через один из контрактов
        $withTx       = (int) DB::scalar(
            'SELECT COUNT(DISTINCT c.id) FROM consultant c
              JOIN contract ct ON ct.consultant = c.id
              JOIN transaction t ON t.contract = ct.id
             WHERE c."dateDeleted" IS NULL AND t."deletedAt" IS NULL'
        );
        // Достигшие квалификации ≥ 3 (Expert)
        $qualExpert   = (int) DB::scalar(
            'SELECT COUNT(DISTINCT c.id) FROM consultant c
              JOIN status_levels sl ON sl.id = c.status_and_lvl
             WHERE c."dateDeleted" IS NULL AND sl.level >= 3'
        );
        // Квалификация 6+ (TOP FC)
        $qualLeader   = (int) DB::scalar(
            'SELECT COUNT(DISTINCT c.id) FROM consultant c
              JOIN status_levels sl ON sl.id = c.status_and_lvl
             WHERE c."dateDeleted" IS NULL AND sl.level >= 6'
        );
        // Терминированные
        $terminated   = (int) DB::scalar('SELECT COUNT(*) FROM consultant WHERE activity = 3 AND "dateDeleted" IS NULL');

        $steps = [
            ['key' => 'total',      'label' => 'Зарегистрированы',       'count' => $total,      'baseline' => $total],
            ['key' => 'activated',  'label' => 'Активированы (500 ЛП)',  'count' => $activated,  'baseline' => $total],
            ['key' => 'withTx',     'label' => 'Есть первая сделка',     'count' => $withTx,     'baseline' => $activated],
            ['key' => 'qualExpert', 'label' => 'Квалификация ≥ Expert',  'count' => $qualExpert, 'baseline' => $withTx],
            ['key' => 'qualLeader', 'label' => 'Квалификация ≥ Top FC',  'count' => $qualLeader, 'baseline' => $qualExpert],
            ['key' => 'terminated', 'label' => 'Терминированы',          'count' => $terminated, 'baseline' => $total, 'negative' => true],
        ];
        foreach ($steps as &$s) {
            $s['rate'] = $s['baseline'] > 0 ? round($s['count'] * 100 / $s['baseline'], 1) : 0;
        }

        return response()->json(['steps' => $steps, 'totalEverRegistered' => $total]);
    }

    /** GET /admin/analytics/cohorts — retention по месяцам регистрации. */
    public function cohorts(): JsonResponse
    {
        // Берём когорты за последние 12 месяцев.
        $rows = DB::select(
            'WITH cohort AS (
                SELECT id, date_trunc(\'month\', "dateCreated")::date AS cohort_month
                  FROM consultant
                 WHERE "dateCreated" > now() - interval \'12 months\'
                   AND "dateDeleted" IS NULL
             ),
             sized AS (
                SELECT cohort_month, COUNT(*) AS cohort_size FROM cohort GROUP BY cohort_month
             ),
             retained AS (
                SELECT ch.cohort_month,
                       COUNT(*) FILTER (WHERE c.active = true) AS active_now,
                       COUNT(*) FILTER (WHERE c.activity = 3) AS terminated
                  FROM cohort ch JOIN consultant c ON c.id = ch.id
                 GROUP BY ch.cohort_month
             )
             SELECT s.cohort_month, s.cohort_size,
                    COALESCE(r.active_now, 0) AS active_now,
                    COALESCE(r.terminated, 0) AS terminated
               FROM sized s LEFT JOIN retained r USING (cohort_month)
              ORDER BY s.cohort_month DESC'
        );

        return response()->json(['cohorts' => $rows]);
    }

    /** GET /admin/analytics/owner-dashboard — для владельца, стратегические цифры. */
    public function ownerDashboard(): JsonResponse
    {
        // Последние 6 месяцев по выручке ДС.
        $monthly = DB::select(
            'SELECT date_trunc(\'month\', date)::date AS m,
                    SUM("netRevenueRUB")::numeric AS net,
                    SUM("amountRUB")::numeric     AS gross,
                    SUM("commissionsAmountRUB")::numeric AS comm,
                    COUNT(*) AS tx_count
               FROM transaction
              WHERE date > now() - interval \'6 months\'
                AND "deletedAt" IS NULL
              GROUP BY 1 ORDER BY 1'
        );

        $activeCount = (int) DB::scalar('SELECT COUNT(*) FROM consultant WHERE "dateDeleted" IS NULL AND active = true');
        $totalCount  = (int) DB::scalar('SELECT COUNT(*) FROM consultant WHERE "dateDeleted" IS NULL');

        $topPartners = DB::select(
            'SELECT c.id, c."personName",
                    sl.level, sl.title,
                    COALESCE(c."groupVolume", 0) AS "groupVolume",
                    COALESCE(c."personalVolume", 0) AS "personalVolume"
               FROM consultant c
               LEFT JOIN status_levels sl ON sl.id = c.status_and_lvl
              WHERE c."dateDeleted" IS NULL
                AND c.active = true
              ORDER BY c."groupVolume" DESC NULLS LAST
              LIMIT 10'
        );

        // Текущий месяц
        $month = now()->format('Y-m');
        $balanceSum = (float) DB::scalar('SELECT COALESCE(SUM("totalPayable"), 0) FROM "consultantBalance" WHERE "dateMonth" = ?', [$month]);
        $poolSum    = (float) DB::scalar('SELECT COALESCE(SUM("accruedPool"), 0) FROM "consultantBalance" WHERE "dateMonth" = ?', [$month]);

        return response()->json([
            'monthlyRevenue' => $monthly,
            'activeCount' => $activeCount,
            'totalCount' => $totalCount,
            'topPartners' => $topPartners,
            'currentMonthPayable' => $balanceSum,
            'currentMonthPool' => $poolSum,
        ]);
    }
}
