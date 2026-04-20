<?php
/**
 * Apply the green-list mapping to `program.active`:
 *   - every mapped/ambiguous candidate program gets active=true
 *   - everything else in `program` gets active=false
 *
 * Reads storage/app/products-map.json produced by scripts/products-map.php.
 * Prints what changed. Run with --dry-run to preview.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$dryRun = in_array('--dry-run', $argv);
$mapPath = __DIR__ . '/../storage/app/products-map.json';
if (!file_exists($mapPath)) {
    fwrite(STDERR, "Run scripts/products-map.php first\n");
    exit(1);
}
$map = json_decode(file_get_contents($mapPath), true);

// Collect IDs to keep active.
$keep = [];
foreach ($map['mapped'] as $m) {
    $keep[$m['programId']] = true;
}
foreach ($map['ambiguous'] as $a) {
    foreach ($a['candidates'] as $id) $keep[$id] = true;
}
$keepIds = array_keys($keep);

$current = DB::table('program')->pluck('active', 'id');
$toActivate = [];
$toDeactivate = [];
foreach ($current as $id => $active) {
    $shouldBeActive = isset($keep[$id]);
    if ($shouldBeActive && !$active) $toActivate[] = (int) $id;
    if (!$shouldBeActive && $active) $toDeactivate[] = (int) $id;
}

echo "Dry-run: " . ($dryRun ? 'yes' : 'NO — will write') . PHP_EOL;
echo "Programs to activate:   " . count($toActivate) . PHP_EOL;
echo "Programs to deactivate: " . count($toDeactivate) . PHP_EOL;
echo "Programs already correct: " . (count($current) - count($toActivate) - count($toDeactivate)) . PHP_EOL;

if (!$dryRun && ($toActivate || $toDeactivate)) {
    DB::transaction(function () use ($toActivate, $toDeactivate) {
        if ($toActivate) DB::table('program')->whereIn('id', $toActivate)->update(['active' => true]);
        if ($toDeactivate) DB::table('program')->whereIn('id', $toDeactivate)->update(['active' => false]);
    });
    echo "\n✓ Applied\n";
} elseif ($dryRun) {
    echo "\n(dry-run — nothing written)\n";
    // show a few samples
    $sample = array_slice($toDeactivate, 0, 5);
    if ($sample) {
        $names = DB::table('program')->whereIn('id', $sample)->pluck('name', 'id');
        echo "\nSample to deactivate:\n";
        foreach ($names as $id => $n) echo "  - #$id  $n\n";
    }
}

$afterActive = DB::table('program')->where('active', true)->count();
echo "\nActive programs after: $afterActive\n";
