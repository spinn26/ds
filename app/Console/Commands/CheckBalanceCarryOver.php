<?php

namespace App\Console\Commands;

use App\Services\CommissionCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Сплошная проверка переноса остатка между месяцами по ВСЕМ партнёрам.
 *
 * ПРОБЛЕМА. Входящее сальдо месяца — это `remaining` предыдущего месяца
 * (канон в CommissionCalculator::incomingBalance, PaymentRegistryService и
 * PaymentRegistryReport). Раньше строка нового месяца создавалась с
 * `balance = 0`, а у существующей строки `balance` не пересчитывался вовсе —
 * накопленный остаток терялся, и `totalPayable`/`remaining` падали до суммы
 * одного месяца. Реестр выплат этого не показывал, потому что считает сальдо
 * живьём; кабинет партнёра читает снимок как есть — и показывал неверно.
 *
 * Код починен: rebuildBalance берёт incoming из прошлого месяца и протягивает
 * изменение вперёд через cascadeCarryForward. Но починка кода НЕ лечит строки,
 * записанные до неё, — они остаются с прежними значениями, пока по партнёру
 * заново не отработает пересчёт. Именно так выглядят находки бэкофиса по
 * переходу с июня на июль 2026.
 *
 * Существующие проверки инвариантов (Аналитика → «Сверки») считают суммы
 * ВНУТРИ одного месяца и такой разрыв не видят: у каждой строки арифметика
 * сходится, ломается связь между строками.
 *
 * Команда сверяет три инварианта по каждой паре (партнёр, месяц):
 *   1. balance(M)      == remaining(M−1)     — сам перенос
 *   2. totalPayable(M) == balance + accruedTotal
 *   3. remaining(M)    == totalPayable − payed
 *
 * По умолчанию только ЧИТАЕТ. С --fix протягивает перенос через
 * CommissionCalculator::cascadeCarryForward — это бухгалтерская протяжка,
 * а не пересчёт денег: начисления (accrued*) не трогаются вовсе.
 *
 *   php artisan finance:check-carryover
 *   php artisan finance:check-carryover --from=2026-06
 *   php artisan finance:check-carryover --consultant=256
 *   php artisan finance:check-carryover --fix
 */
class CheckBalanceCarryOver extends Command
{
    protected $signature = 'finance:check-carryover
        {--from= : с какого месяца проверять, YYYY-MM (по умолчанию с HISTORICAL_CUTOFF)}
        {--consultant= : только этот consultant.id}
        {--limit=40 : сколько строк расхождений печатать}
        {--fix : протянуть перенос у найденных партнёров (пишет в consultantBalance)}';

    protected $description = 'Проверить перенос остатка между месяцами по всем партнёрам (по умолчанию только чтение)';

    /** Порог в рублях: копеечные хвосты округления — не расхождение. */
    private const EPS = 0.01;

    public function __construct(
        private readonly CommissionCalculator $calculator,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $from = (string) ($this->option('from') ?: substr(CommissionCalculator::HISTORICAL_CUTOFF, 0, 7));
        if (! preg_match('/^\d{4}-\d{2}$/', $from)) {
            $this->error('--from должен быть в формате YYYY-MM');

            return self::FAILURE;
        }

        $only = $this->option('consultant') ? (int) $this->option('consultant') : null;
        $limit = (int) $this->option('limit');

        // LAG по месяцам внутри партнёра. Окно НЕ ограничено $from: чтобы
        // проверить первый открытый месяц, нужен предыдущий — он может быть
        // историческим и заморожен, но именно он источник входящего сальдо.
        $rows = DB::select(<<<'SQL'
            WITH ordered AS (
                SELECT consultant,
                       "dateMonth",
                       COALESCE(balance, 0)          AS balance,
                       COALESCE("accruedTotal", 0)   AS accrued,
                       COALESCE(payed, 0)            AS payed,
                       COALESCE("totalPayable", 0)   AS payable,
                       COALESCE(remaining, 0)        AS remaining,
                       LAG(COALESCE(remaining, 0)) OVER (
                           PARTITION BY consultant ORDER BY "dateMonth"
                       ) AS prev_remaining,
                       LAG("dateMonth") OVER (
                           PARTITION BY consultant ORDER BY "dateMonth"
                       ) AS prev_month
                  FROM "consultantBalance"
                 WHERE consultant IS NOT NULL
                   AND "dateMonth" LIKE '____-__'
            )
            SELECT o.*, c."personName"
              FROM ordered o
              LEFT JOIN consultant c ON c.id = o.consultant
             WHERE o."dateMonth" >= ?
             ORDER BY o.consultant, o."dateMonth"
        SQL, [$from]);

        $bad = [];
        $byKind = ['carry' => 0, 'payable' => 0, 'remaining' => 0];
        $partners = [];

        foreach ($rows as $r) {
            if ($only && (int) $r->consultant !== $only) {
                continue;
            }

            // Первый месяц партнёра: предыдущего нет, входящее обязано быть 0.
            $expectedBalance = $r->prev_month === null ? 0.0 : (float) $r->prev_remaining;

            $dCarry = (float) $r->balance - $expectedBalance;
            $dPayable = (float) $r->payable - ((float) $r->balance + (float) $r->accrued);
            $dRemaining = (float) $r->remaining - ((float) $r->payable - (float) $r->payed);

            $kinds = [];
            if (abs($dCarry) > self::EPS) { $kinds[] = 'carry'; $byKind['carry']++; }
            if (abs($dPayable) > self::EPS) { $kinds[] = 'payable'; $byKind['payable']++; }
            if (abs($dRemaining) > self::EPS) { $kinds[] = 'remaining'; $byKind['remaining']++; }

            if ($kinds) {
                $bad[] = [$r, $dCarry, $dPayable, $dRemaining, $kinds, $expectedBalance];
                $partners[(int) $r->consultant] = true;
            }
        }

        $this->info('Проверено строк: ' . count($rows) . ' (с ' . $from . '), партнёров с расхождением: ' . count($partners));
        $this->line("  перенос balance≠remaining(M−1): {$byKind['carry']}");
        $this->line("  totalPayable ≠ balance+accrued: {$byKind['payable']}");
        $this->line("  remaining ≠ totalPayable−payed: {$byKind['remaining']}");

        if (! $bad) {
            $this->info('Расхождений нет — остатки переходят корректно.');

            return self::SUCCESS;
        }

        // Крупнейшие сверху: разбирать всегда начинают с них.
        usort($bad, fn ($a, $b) => abs($b[1]) <=> abs($a[1]));

        $this->newLine();
        $this->table(
            ['ID', 'Партнёр', 'Месяц', 'balance', 'ожидалось', 'Δ перенос', 'Δ payable', 'Δ remaining'],
            array_map(fn ($b) => [
                $b[0]->consultant,
                mb_substr((string) ($b[0]->personName ?? '—'), 0, 26),
                $b[0]->dateMonth,
                $this->n((float) $b[0]->balance),
                $this->n($b[5]),
                $this->n($b[1]),
                $this->n($b[2]),
                $this->n($b[3]),
            ], array_slice($bad, 0, $limit))
        );

        if (count($bad) > $limit) {
            $this->comment('Показаны первые ' . $limit . ' из ' . count($bad) . '. Полный список — увеличьте --limit.');
        }

        if (! $this->option('fix')) {
            $this->newLine();
            $this->comment('Это только диагностика. Протянуть перенос: php artisan finance:check-carryover --fix');
            $this->comment('Если расходятся и начисления — сначала php artisan commission:resync-balances.');

            return self::SUCCESS;
        }

        // Протяжка от самого раннего сломанного месяца партнёра: чинить
        // с середины бессмысленно — ошибка ниже по цепочке останется.
        $earliest = [];
        foreach ($bad as $b) {
            $id = (int) $b[0]->consultant;
            $ym = (string) $b[0]->dateMonth;
            if (! isset($earliest[$id]) || $ym < $earliest[$id]) {
                $earliest[$id] = $ym;
            }
        }

        $this->newLine();
        $this->warn('Протяжка переноса по ' . count($earliest) . ' партнёрам. Начисления не трогаются.');

        $updated = 0;
        foreach ($earliest as $id => $ym) {
            // cascadeCarryForward протягивает ПОСЛЕ указанного месяца, поэтому
            // отступаем на месяц назад: иначе самый ранний сломанный останется.
            $prev = date('Y-m', strtotime($ym . '-01 -1 month'));
            $updated += $this->calculator->cascadeCarryForward([$id], $prev);
        }

        $this->info("Обновлено строк: {$updated}. Перепроверьте: php artisan finance:check-carryover --from={$from}");

        return self::SUCCESS;
    }

    private function n(float $v): string
    {
        return number_format($v, 2, '.', ' ');
    }
}
