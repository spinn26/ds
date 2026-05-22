<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Program;
use App\Support\LegacyId;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Синхронизация каталога продуктов / программ из Google Sheets.
 *
 * Источник: лист «Тарифы продуктов» в spreadsheet, согласованном с
 * отделом продуктов. Колонки (по индексу):
 *   1: ТИП | 2: ПРОДУКТ | 3: ПРОГРАММА | 4: Стоимость | 5: ВАЛЮТА
 *   6: ПОСТАВЩИК | 7: % DS | 8: Свойство | 9: Срок Контракта
 *   10: Год выплаты КВ | 11: Баллы | 12: МЕТОДИКА | 13: Комментарии
 *   14: Категория
 *
 * Логика:
 *  - Парсим валидные строки (product + program заполнены, валюта RUB/USD/
 *    EUR/GBP, % DS парсится). Остальное → log + skip.
 *  - Для каждой целевой записи: find_or_create по композитному ключу
 *    (product.name, program.name, program.term, program.kvPayoutYear).
 *  - Обновляем dsPercent, pointsMethod='amount_div_100', pointsFormula,
 *    currency, provider/providerName, имя продукта/программы (на случай
 *    мелких правок имени).
 *  - Soft-deactivate: продукты/программы, которых НЕТ в таблице, ставим
 *    active=false (без удаления — FK от contract сохраняется).
 *
 * Команда НЕ трогает таблицы contract, transaction, commission.
 *
 * По умолчанию работает в режиме --dry-run (показывает план, в БД ничего
 * не пишет). Для применения нужно явно передать --apply.
 */
class SyncProductsFromSheet extends Command
{
    protected $signature = 'products:sync-from-sheet
                            {--spreadsheet-id= : ID Google-таблицы (URL …/spreadsheets/d/<ID>/edit)}
                            {--sheet-name=Тарифы : Имя листа внутри таблицы}
                            {--api-key= : Google Sheets API key (по умолчанию — из api_settings/.env)}
                            {--apply : Реально применить изменения (по умолчанию dry-run)}
                            {--strict : Пропускать невалидные строки без warning (по умолчанию warning)}';

    protected $description = 'Синхронизирует каталог продуктов/программ из Google Sheets';

    public function handle(\App\Services\ApiSettingsService $settings): int
    {
        $apply = (bool) $this->option('apply');
        $strict = (bool) $this->option('strict');
        $spreadsheetId = $this->option('spreadsheet-id')
            ?: $settings->get('google.sheets.products_id', env('GOOGLE_SHEETS_PRODUCTS_ID'));
        $sheetName = (string) $this->option('sheet-name');
        $apiKey = $this->option('api-key')
            ?: $settings->get('google.sheets.api_key', env('GOOGLE_SHEETS_API_KEY'));

        if (! $spreadsheetId || ! $apiKey) {
            $this->error('Не задан spreadsheet-id или api-key. Передайте через опции или настройте google.sheets.* в api_settings.');
            return self::FAILURE;
        }

        $this->info("Mode: " . ($apply ? 'APPLY' : 'DRY-RUN'));
        $this->info("Source: spreadsheet={$spreadsheetId}, sheet={$sheetName}");

        // === STEP 1: Read sheet ===
        $values = $this->readSheet($spreadsheetId, $sheetName, $apiKey);
        if (! $values) {
            $this->error('Лист пустой или недоступен.');
            return self::FAILURE;
        }
        $this->info("Прочитано строк: " . count($values));

        // === STEP 2: Parse + validate ===
        $candidates = [];      // валидные строки → запишутся в БД
        $skipped = [];         // невалидные → лог
        // Skip первые 3 заголовка (header + separator + col-numbers).
        for ($i = 3; $i < count($values); $i++) {
            $row = $values[$i];
            $rec = $this->parseRow($row);
            if (! $rec) {
                $skipped[] = ['line' => $i + 1, 'reason' => 'empty/header'];
                continue;
            }
            $err = $this->validateRecord($rec);
            if ($err) {
                $skipped[] = ['line' => $i + 1, 'reason' => $err, 'product' => $rec['product'] ?? '—', 'program' => $rec['program'] ?? '—'];
                continue;
            }
            $candidates[] = $rec;
        }

        $this->info('Валидных тарифов: ' . count($candidates));
        $this->info('Пропущено (мусор/невалидные): ' . count($skipped));
        if (! $strict && $skipped) {
            $this->newLine();
            $this->warn('Пропущенные строки (первые 30):');
            foreach (array_slice($skipped, 0, 30) as $s) {
                $this->line(sprintf('  стр %d: %s — %s / %s', $s['line'], $s['reason'], $s['product'] ?? '', $s['program'] ?? ''));
            }
        }

        // === STEP 3: Resolve refs (counterparty/currency) ===
        $counterpartyMap = DB::table('counterparty')->pluck('id', 'counterpartyName');
        $counterpartyLower = $counterpartyMap->mapWithKeys(fn ($id, $name) => [mb_strtolower($name) => $id]);
        $currencyMap = DB::table('currency')->pluck('id', 'cbrCode')
            ->merge(DB::table('currency')->pluck('id', 'symbol'))
            ->merge(DB::table('currency')->pluck('id', 'nameEn'));

        // === STEP 4: Match against DB ===
        $existingProducts = DB::table('product')->where('active', true)
            ->orWhere(fn ($q) => $q->where('active', false)->whereNotNull('name'))
            ->get(['id', 'name', 'active'])
            ->keyBy(fn ($p) => mb_strtolower(trim($p->name)));

        $existingPrograms = DB::table('program')
            ->whereNull('dateDeleted')
            ->get(['id', 'product', 'name', 'term', 'kvPayoutYear', 'active'])
            ->map(fn ($p) => (array) $p);

        $plan = [
            'products_create' => [],
            'products_update' => [],
            'products_deactivate' => [],
            'programs_create' => [],
            'programs_update' => [],
            'programs_deactivate' => [],
        ];

        // Set of target keys (для последующего deactivate)
        $targetProductNames = [];
        $targetProgramKeys = [];

        foreach ($candidates as $rec) {
            $productNameKey = mb_strtolower(trim($rec['product']));
            $targetProductNames[$productNameKey] = $rec['product'];
            $existingProduct = $existingProducts->get($productNameKey);

            if (! $existingProduct) {
                $plan['products_create'][$productNameKey] = $rec['product'];
            } else if (! $existingProduct->active) {
                $plan['products_update'][$existingProduct->id] = ['name' => $rec['product'], 'active' => true];
            }

            $progKey = $productNameKey . '||' . mb_strtolower(trim($rec['program']))
                . '||' . ($rec['term'] ?? '') . '||' . ($rec['kvPayoutYear'] ?? '');
            $targetProgramKeys[$progKey] = true;
        }

        // Programs: match by (product_id, name, term, kvPayoutYear).
        // Сначала нужны product_id для existing+create.
        $productIdByNameLower = [];
        foreach ($existingProducts as $key => $p) $productIdByNameLower[$key] = $p->id;

        foreach ($candidates as $rec) {
            $productNameKey = mb_strtolower(trim($rec['product']));
            $productId = $productIdByNameLower[$productNameKey] ?? null;

            $providerId = null;
            if ($rec['providerName']) {
                $providerId = $counterpartyLower[mb_strtolower($rec['providerName'])] ?? null;
            }
            $currencyId = null;
            if ($rec['currency']) {
                $currencyId = $currencyMap[$rec['currency']] ?? null;
            }

            // Поиск программы среди existing
            $matched = $existingPrograms->first(function ($p) use ($productId, $rec) {
                if ($productId && $p['product'] != $productId) return false;
                if (mb_strtolower(trim($p['name'])) !== mb_strtolower(trim($rec['program']))) return false;
                if ((int) ($p['term'] ?? 0) !== (int) ($rec['term'] ?? 0)) return false;
                if ((int) ($p['kvPayoutYear'] ?? 0) !== (int) ($rec['kvPayoutYear'] ?? 0)) return false;
                return true;
            });

            $progData = [
                'name' => $rec['program'],
                'dsPercent' => $rec['dsPercent'],
                'pointsMethod' => 'amount_div_100',
                'pointsFormula' => $rec['formula'],
                'term' => $rec['term'],
                'kvPayoutYear' => $rec['kvPayoutYear'],
                'currency' => $currencyId,
                'provider' => $providerId,
                'providerName' => $rec['providerName'],
                'productName' => $rec['product'],   // легаси-зеркало
                'productType' => null,
                'active' => true,
                'visibleToCalculator' => true,
            ];

            if ($matched) {
                $plan['programs_update'][] = ['id' => $matched['id'], 'before' => $matched, 'after' => $progData];
            } else {
                $plan['programs_create'][] = ['productNameKey' => $productNameKey, 'data' => $progData];
            }
        }

        // Soft-deactivate: программы и продукты, которых нет в targets.
        foreach ($existingPrograms as $p) {
            if (! $p['active']) continue;
            $productName = DB::table('product')->where('id', $p['product'])->value('name');
            $progKey = mb_strtolower(trim((string) $productName)) . '||' . mb_strtolower(trim($p['name']))
                . '||' . ($p['term'] ?? '') . '||' . ($p['kvPayoutYear'] ?? '');
            if (! isset($targetProgramKeys[$progKey])) {
                $plan['programs_deactivate'][] = $p['id'];
            }
        }
        foreach ($existingProducts as $key => $p) {
            if (! $p->active) continue;
            if (! isset($targetProductNames[$key])) {
                $plan['products_deactivate'][] = $p->id;
            }
        }

        // === STEP 5: Print plan ===
        $this->newLine();
        $this->info('=== ПЛАН ===');
        $this->line("Продукты — создать: " . count($plan['products_create']));
        foreach (array_slice($plan['products_create'], 0, 20) as $name) $this->line("  + {$name}");

        $this->line("Продукты — реактивировать: " . count($plan['products_update']));
        $this->line("Продукты — деактивировать: " . count($plan['products_deactivate']));

        $this->line("Программы — создать: " . count($plan['programs_create']));
        foreach (array_slice($plan['programs_create'], 0, 20) as $p) {
            $d = $p['data'];
            $this->line(sprintf('  + %s :: %s (term=%s yearKV=%s %s%%)',
                $p['productNameKey'], $d['name'], $d['term'] ?: '—', $d['kvPayoutYear'] ?: '—', $d['dsPercent'] ?? '?'));
        }
        $this->line("Программы — обновить: " . count($plan['programs_update']));
        $this->line("Программы — деактивировать: " . count($plan['programs_deactivate']));

        // === STEP 6: Apply (если --apply) ===
        if (! $apply) {
            $this->newLine();
            $this->warn('DRY-RUN: ничего не изменено. Запустите с --apply для применения.');
            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('=== APPLY ===');
        DB::transaction(function () use ($plan, $candidates, &$productIdByNameLower, $counterpartyLower, $currencyMap) {
            // Create products
            foreach ($plan['products_create'] as $key => $name) {
                $id = LegacyId::next('product');
                DB::table('product')->insert([
                    'id' => $id,
                    'name' => $name,
                    'active' => true,
                    'visibleToCalculator' => true,
                    'visibleToResident' => false,
                    'publish_status' => 'draft',
                ]);
                $productIdByNameLower[$key] = $id;
            }
            // Reactivate
            foreach ($plan['products_update'] as $id => $upd) {
                DB::table('product')->where('id', $id)->update($upd);
            }
            // Create programs
            foreach ($plan['programs_create'] as $p) {
                $productId = $productIdByNameLower[$p['productNameKey']] ?? null;
                if (! $productId) continue;
                $data = $p['data'];
                $progId = LegacyId::next('program');
                DB::table('program')->insert(array_merge($data, [
                    'id' => $progId,
                    'product' => $productId,
                ]));
            }
            // Update programs
            foreach ($plan['programs_update'] as $u) {
                $data = $u['after'];
                unset($data['productName'], $data['productType']);
                DB::table('program')->where('id', $u['id'])->update($data);
            }
            // Soft-deactivate
            if ($plan['programs_deactivate']) {
                DB::table('program')->whereIn('id', $plan['programs_deactivate'])
                    ->update(['active' => false, 'visibleToCalculator' => false]);
            }
            if ($plan['products_deactivate']) {
                DB::table('product')->whereIn('id', $plan['products_deactivate'])
                    ->update(['active' => false, 'visibleToResident' => false]);
            }
        });

        $this->info('Готово.');
        return self::SUCCESS;
    }

    /** Прочитать raw values из Google Sheets API. */
    private function readSheet(string $spreadsheetId, string $sheetName, string $apiKey): array
    {
        $range = urlencode($sheetName);
        $url = "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values/{$range}?key={$apiKey}";
        $response = Http::timeout(30)->get($url);
        if (! $response->ok()) {
            $this->error("Sheets API error: HTTP {$response->status()}");
            return [];
        }
        return $response->json('values') ?? [];
    }

    /** Распарсить строку таблицы в каноническую запись. */
    private function parseRow(array $row): ?array
    {
        // Колонки по индексу (см. docblock команды).
        $product = trim((string) ($row[2] ?? ''));
        $program = trim((string) ($row[3] ?? ''));
        if (! $product || ! $program) return null;

        $dsPercentRaw = trim((string) ($row[7] ?? ''));
        // "77,50%" → 77.5
        $dsPercent = null;
        if ($dsPercentRaw !== '') {
            $clean = str_replace([',', '%', ' '], ['.', '', ''], $dsPercentRaw);
            if (is_numeric($clean)) $dsPercent = (float) $clean;
        }

        // Срок Контракта — number of years.
        $termRaw = trim((string) ($row[9] ?? ''));
        $term = $termRaw !== '' && is_numeric(str_replace([',', ' '], ['.', ''], $termRaw))
            ? (int) str_replace([',', ' '], ['.', ''], $termRaw)
            : null;

        // Год выплаты КВ — "1 год", "2 год" → 1, 2. Иначе null.
        $yearKvRaw = trim((string) ($row[10] ?? ''));
        $kvPayoutYear = null;
        if (preg_match('/(\d+)/u', $yearKvRaw, $m)) {
            $kvPayoutYear = (int) $m[1];
        }

        return [
            'type' => trim((string) ($row[1] ?? '')),
            'product' => $product,
            'program' => $program,
            'fixedCost' => trim((string) ($row[4] ?? '')),
            'currency' => mb_strtoupper(trim((string) ($row[5] ?? ''))),
            'providerName' => trim((string) ($row[6] ?? '')),
            'dsPercent' => $dsPercent,
            'property' => trim((string) ($row[8] ?? '')),
            'term' => $term,
            'kvPayoutYear' => $kvPayoutYear,
            'formula' => trim((string) ($row[12] ?? '')),
            'category' => trim((string) ($row[14] ?? '')),
        ];
    }

    /** Проверка валидности — null если ок, иначе строка-причина пропуска. */
    private function validateRecord(array $rec): ?string
    {
        // Отбрасываем строки-разметки таблицы («4 / 5 / 6» в product/program).
        if (preg_match('/^\d+$/', $rec['product']) || preg_match('/^\d+$/', $rec['program'])) {
            return 'header-numbering row';
        }
        // Currency — только RUB/USD/EUR/GBP (как в нашей системе).
        if ($rec['currency'] && ! in_array($rec['currency'], ['RUB', 'USD', 'EUR', 'GBP'], true)) {
            return "currency «{$rec['currency']}» не поддерживается";
        }
        // % DS — должен быть распарсенным числом 0..100.
        if ($rec['dsPercent'] === null) {
            return '% DS пустой/невалидный';
        }
        if ($rec['dsPercent'] < 0 || $rec['dsPercent'] > 100) {
            return "% DS вне диапазона: {$rec['dsPercent']}";
        }
        return null;
    }
}
