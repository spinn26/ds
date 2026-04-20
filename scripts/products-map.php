<?php
/**
 * Dry-run mapper: green reference CSV → existing `program` rows in DB.
 *
 * Reads `Db/reference_products_green.csv`, tries to find a unique
 * program in the DB for each row using several match strategies
 * (exact name, case-insensitive, product+vendor substring).
 * Writes `storage/app/products-map.json` with four buckets:
 *   - mapped        one hit  — ready to activate
 *   - ambiguous     several hits — BackOffice decides
 *   - not_in_db     green row has no matching DB program
 *   - not_in_csv    active DB program that's NOT in the green list
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

// Helper: normalise a Russian name for fuzzy match
function norm(string $s): string
{
    $s = mb_strtolower(trim($s));
    $s = preg_replace('/["«»`]/u', '', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return $s;
}

// Parse CSV
$fp = fopen(__DIR__ . '/../Db/reference_products_green.csv', 'r');
$headers = null;
$rows = [];
while (($line = fgetcsv($fp, 0, ';')) !== false) {
    // Strip UTF-8 BOM from the first cell (might stick to the 'row' header)
    $line[0] = preg_replace('/^\xEF\xBB\xBF/', '', $line[0] ?? '');
    if ($headers === null) { $headers = $line; continue; }
    $rows[] = array_combine($headers, $line);
}
fclose($fp);

// Dedupe CSV rows by (product, program) — one green program may have many
// rate lines for different properties / terms, but it's still the same program.
$unique = [];
foreach ($rows as $r) {
    $k = trim((string) ($r['product'] ?? '')) . '|' . trim((string) ($r['program'] ?? ''));
    if ($k === '|') continue;
    if (!isset($unique[$k])) $unique[$k] = $r;
}
$rows = array_values($unique);
echo 'Green CSV rows (deduped): ' . count($rows) . PHP_EOL;

// Load all programs once (2k-ish rows)
$programs = DB::select('
    SELECT pr.id,
           pr.name AS program_name,
           pr."productName" AS "productName",
           pr."vendorName" AS "vendorName",
           pr."providerName" AS "providerName",
           pr.active,
           p.name AS product_name
      FROM program pr
      LEFT JOIN product p ON p.id = pr.product
');
$programs = collect($programs);

echo 'DB program rows: ' . $programs->count() . PHP_EOL;

// Build lookup indexes
$byName = [];                   // norm(program.name) → [ids]
$byVendorName = [];             // norm(vendor) + '|' + norm(program.name) → [ids]
foreach ($programs as $p) {
    $pname = norm((string) ($p->program_name ?? ''));
    if ($pname !== '') $byName[$pname][] = $p->id;

    $vendor = norm((string) ($p->vendorName ?? $p->product_name ?? $p->productName ?? ''));
    if ($vendor !== '' && $pname !== '') {
        $byVendorName[$vendor . '|' . $pname][] = $p->id;
    }
}

$mapped = [];
$ambiguous = [];
$notInDb = [];
$usedProgramIds = [];

foreach ($rows as $r) {
    $type = $r['type'] ?? '';
    $product = trim((string) ($r['product'] ?? ''));  // Google col C
    $program = trim((string) ($r['program'] ?? ''));  // Google col D
    $dsPct = trim((string) ($r['dsPercent'] ?? ''));
    if ($product === '' && $program === '') continue;

    $pname = norm($program);
    $vendor = norm($product);

    $hits = [];

    // Strategy 1: vendor + name exact match
    if ($vendor && $pname && isset($byVendorName[$vendor . '|' . $pname])) {
        $hits = $byVendorName[$vendor . '|' . $pname];
    }
    // Strategy 2: just program name
    if (!$hits && $pname && isset($byName[$pname])) {
        $hits = $byName[$pname];
    }
    // Strategy 3: substring over program.name and join of vendor+program
    if (!$hits && $pname) {
        foreach ($byName as $key => $ids) {
            if (str_contains($key, $pname) || str_contains($pname, $key)) {
                $hits = array_merge($hits, $ids);
            }
        }
        $hits = array_values(array_unique($hits));
    }

    $rec = [
        'csvRow' => (int) $r['row'],
        'type' => $type,
        'product' => $product,
        'program' => $program,
        'dsPercent' => $dsPct,
    ];

    if (count($hits) === 1) {
        $rec['programId'] = $hits[0];
        $mapped[] = $rec;
        $usedProgramIds[$hits[0]] = true;
    } elseif (count($hits) > 1) {
        $rec['candidates'] = $hits;
        $ambiguous[] = $rec;
        foreach ($hits as $id) $usedProgramIds[$id] = true;
    } else {
        $notInDb[] = $rec;
    }
}

// DB programs not touched by any green row — candidates to deactivate
$notInCsv = [];
foreach ($programs as $p) {
    if ($p->active && !isset($usedProgramIds[$p->id])) {
        $notInCsv[] = [
            'id' => $p->id,
            'productName' => $p->product_name ?? $p->productName,
            'programName' => $p->program_name,
            'vendorName' => $p->vendorName,
        ];
    }
}

$summary = [
    'mapped' => count($mapped),
    'ambiguous' => count($ambiguous),
    'not_in_db' => count($notInDb),
    'not_in_csv' => count($notInCsv),
    'total_csv' => count($rows),
    'total_db' => $programs->count(),
    'active_db_before' => $programs->where('active', true)->count(),
];

echo PHP_EOL . '=== SUMMARY ===' . PHP_EOL;
foreach ($summary as $k => $v) printf("  %-20s %s\n", $k, $v);

$out = [
    'summary' => $summary,
    'mapped' => $mapped,
    'ambiguous' => array_slice($ambiguous, 0, 50),
    'not_in_db' => array_slice($notInDb, 0, 50),
    'not_in_csv' => array_slice($notInCsv, 0, 100),
];
$outPath = __DIR__ . '/../storage/app/products-map.json';
if (!is_dir(dirname($outPath))) mkdir(dirname($outPath), 0775, true);
file_put_contents($outPath, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "\nFull report → " . realpath($outPath) . PHP_EOL;
