<?php
/**
 * Dry-run recalculator for февраль-март 2026 (или любой заданный диапазон).
 *
 * Для каждой транзакции из выбранного месяца (не удалённой, в открытом периоде)
 * прогоняет CommissionCalculator::calculateForTransaction в обёртке
 * DB::beginTransaction()/rollBack(), суммирует полученные commission-ы
 * по каждому консультанту и сравнивает с тем, что сейчас лежит в
 * commission.amountRUB (для 2026 это ~везде NULL — плачено через
 * transaction.commissionsAmountRUB, но commission-детализация не
 * заполнена).
 *
 * Запуск:
 *   php scripts/commissions-recalc.php 2026-02           # dry-run febr
 *   php scripts/commissions-recalc.php 2026-02 --apply   # реальный пересчёт
 *
 * По умолчанию ничего не пишет в БД.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Services\CommissionCalculator;

$period = $argv[1] ?? null;
$apply = in_array('--apply', $argv, true);
$limit = null;
foreach ($argv as $a) {
    if (preg_match('/^--limit=(\d+)$/', $a, $m)) $limit = (int) $m[1];
}

if (! $period || ! preg_match('/^(\d{4})-(\d{2})$/', $period, $pm)) {
    fwrite(STDERR, "Usage: php scripts/commissions-recalc.php YYYY-MM [--apply] [--limit=N]\n");
    exit(1);
}
[$year, $month] = [(int) $pm[1], (int) $pm[2]];

$calc = app(CommissionCalculator::class);

echo "Period:       $period\n";
echo "Mode:         " . ($apply ? "APPLY (persists commission rows)" : "dry-run (rollback)") . "\n";

// Транзакции этого периода.
$q = DB::table('transaction')
    ->whereNull('deletedAt')
    ->where(function ($q) use ($period) {
        // Поддерживаем обе формы dateMonth: "02" и "2026-02".
        $q->where('dateMonth', substr($period, 5, 2))
          ->where('dateYear', substr($period, 0, 4))
          ->orWhere('dateMonth', $period);
    })
    ->orderBy('id');
if ($limit) $q->limit($limit);
$txIds = $q->pluck('id');

echo "Transactions: " . $txIds->count() . "\n";

// Фильтр для выборок commission — покрывает обе формы dateMonth.
$commissionPeriodFilter = function ($q) use ($period, $month) {
    $q->whereNull('deletedAt')
      ->where(function ($qq) use ($period, $month) {
          $qq->where('dateMonth', $period)
             ->orWhere('dateMonth', sprintf('%02d', $month));
      });
};

$snapshot = fn () => DB::table('commission')
    ->tap($commissionPeriodFilter)
    ->selectRaw('consultant, COALESCE(SUM("amountRUB"), 0) AS total, COUNT(*) AS rows')
    ->groupBy('consultant')
    ->pluck('total', 'consultant')
    ->map(fn ($v) => (float) $v)
    ->toArray();

$existingByConsultant = $snapshot();

$failed = 0;
$ok = 0;

DB::beginTransaction();
try {
    foreach ($txIds as $txId) {
        // CommissionCalculator сам пишет в commission. Внешняя транзакция
        // гарантирует откат, если apply=false.
        $res = $calc->calculateForTransaction($txId);
        if (! empty($res['error'])) {
            $failed++;
            continue;
        }
        $ok++;
    }

    // Snapshot после пересчёта — пока транзакция не закрыта, видим свои записи.
    $expectedByConsultant = $snapshot();

    if ($apply) {
        DB::commit();
    } else {
        DB::rollBack();
    }
} catch (\Throwable $e) {
    DB::rollBack();
    fwrite(STDERR, "FATAL: {$e->getMessage()}\n");
    exit(1);
}

$totalExpected = array_sum($expectedByConsultant);
$totalExisting = array_sum($existingByConsultant);

echo "Tx processed: $ok ok, $failed skipped\n";
echo "Existing Σ amountRUB (in commission): " . number_format($totalExisting, 2, '.', ' ') . "\n";
echo "Expected Σ after recalc:              " . number_format($totalExpected, 2, '.', ' ') . "\n";
echo "Delta:                                " . number_format($totalExpected - $totalExisting, 2, '.', ' ') . "\n\n";

// Top-20 самых крупных изменений для визуального контроля.
$deltas = [];
$allIds = array_unique(array_merge(array_keys($expectedByConsultant), array_keys($existingByConsultant)));
foreach ($allIds as $cid) {
    $exp = $expectedByConsultant[$cid] ?? 0;
    $cur = $existingByConsultant[$cid] ?? 0;
    $deltas[$cid] = $exp - $cur;
}
arsort($deltas);
$names = DB::table('consultant')
    ->whereIn('id', array_slice(array_keys($deltas), 0, 20))
    ->pluck('personName', 'id');

echo "Top-20 consultants by delta:\n";
$printed = 0;
foreach ($deltas as $cid => $delta) {
    if ($printed++ >= 20) break;
    $name = $names[$cid] ?? "#$cid";
    printf("  %-40s  exp=%12s  cur=%12s  Δ=%+12s\n",
        mb_substr($name, 0, 40),
        number_format($expectedByConsultant[$cid] ?? 0, 0, '.', ' '),
        number_format($existingByConsultant[$cid] ?? 0, 0, '.', ' '),
        number_format($delta, 0, '.', ' '),
    );
}

if (! $apply) {
    echo "\n(dry-run — ничего не записано, rollback сделан)\n";
    echo "Чтобы применить: --apply\n";
}
