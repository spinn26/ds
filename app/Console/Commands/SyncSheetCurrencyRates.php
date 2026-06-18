<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Синхронизация управленческих курсов валют из Google-таблицы (по ссылке) в
 * management_currency_rate. Тянет CSV-экспорт листа, парсит блоки по годам
 * (3 строки: USD/EUR/GB; у GB подпись может отсутствовать — берём по порядку),
 * upsert по (currency, YYYY-MM-01). Источник истины — таблица руководителей,
 * чтобы не править курсы в коде.
 *
 * currency-id: USD = 5, EUR = 17, GBP = 10 (RUB = 67 всегда 1, не пишем).
 */
class SyncSheetCurrencyRates extends Command
{
    protected $signature = 'management-rates:sync-sheet {--id= : ID Google-таблицы} {--gid=0 : gid листа} {--file= : путь к локальному CSV вместо загрузки}';

    protected $description = 'Импорт управленческих курсов (USD/EUR/GBP) из Google-таблицы по ссылке в management_currency_rate';

    /** ID таблицы по умолчанию (лист курсов руководителей). */
    private const DEFAULT_SHEET_ID = '1UQv3UCLNLMmcckn0ll8GFrSvAVAhJ8yyWtHbXSiNi80';

    /** Порядок валют в блоке: 1-я строка USD, 2-я EUR, 3-я GBP. */
    private const SLOT_CURRENCY = [5, 17, 10];

    public function handle(): int
    {
        $file = $this->option('file');
        if ($file) {
            if (! is_readable($file)) {
                $this->error("Файл не найден или недоступен: {$file}");

                return self::FAILURE;
            }
            $body = (string) file_get_contents($file);
        } else {
            $id = $this->option('id') ?: self::DEFAULT_SHEET_ID;
            $gid = $this->option('gid') ?: '0';
            $url = "https://docs.google.com/spreadsheets/d/{$id}/export?format=csv&gid={$gid}";
            $this->info("Загрузка курсов из таблицы {$id}…");
            try {
                $resp = Http::timeout(30)->get($url);
            } catch (\Throwable $e) {
                $this->error('Не удалось получить таблицу: '.$e->getMessage());

                return self::FAILURE;
            }
            if (! $resp->ok()) {
                $this->error("Таблица недоступна (HTTP {$resp->status()}). Проверьте, что доступ «по ссылке».");

                return self::FAILURE;
            }
            $body = $resp->body();
        }

        $lines = preg_split('/\r\n|\r|\n/', $body);
        $now = Carbon::now();

        $year = null;
        $slot = 0;
        $written = 0;

        foreach ($lines as $line) {
            if ($line === '' || $line === null) {
                continue;
            }
            $cells = str_getcsv($line);
            $head = trim((string) ($cells[0] ?? ''));

            // Заголовок блока: первая ячейка — 4-значный год.
            if (preg_match('/^\d{4}$/', $head)) {
                $year = (int) $head;
                $slot = 0;
                continue;
            }
            if ($year === null) {
                continue;
            }

            // Определяем валюту: по подписи, иначе по порядку строки в блоке.
            $currency = $this->currencyFromLabel($head);
            if ($currency === null) {
                // строка без подписи — берём по слоту (обычно GB)
                $currency = self::SLOT_CURRENCY[$slot] ?? null;
            }

            // Это вообще строка курса? Нужна хотя бы одна числовая ячейка месяца.
            $monthVals = [];
            for ($m = 1; $m <= 12; $m++) {
                $monthVals[$m] = $this->parseNumber($cells[$m] ?? null);
            }
            $hasData = count(array_filter($monthVals, fn ($v) => $v !== null)) > 0;
            if (! $hasData || $currency === null) {
                continue;
            }

            foreach ($monthVals as $m => $rate) {
                if ($rate === null) {
                    continue;
                }
                $date = sprintf('%04d-%02d-01', $year, $m);
                DB::table('management_currency_rate')->updateOrInsert(
                    ['currency' => $currency, 'date' => $date],
                    ['rate' => $rate, 'updated_at' => $now],
                );
                $written++;
            }
            $slot++;
        }

        if ($written === 0) {
            $this->warn('Курсы не распознаны — проверьте структуру таблицы.');

            return self::FAILURE;
        }

        $this->info("Готово: загружено/обновлено {$written} строк курсов из Google-таблицы.");

        return self::SUCCESS;
    }

    /** USD → 5, EUR → 17, GB/GBP → 10. null, если подпись не про валюту. */
    private function currencyFromLabel(string $label): ?int
    {
        $u = mb_strtoupper($label);
        if (str_contains($u, 'USD')) {
            return 5;
        }
        if (str_contains($u, 'EUR')) {
            return 17;
        }
        if (str_contains($u, 'GB')) {
            return 10;
        }

        return null;
    }

    /** «77,5632» → 77.5632; пусто/нечисло → null. */
    private function parseNumber($raw): ?float
    {
        $s = trim((string) $raw);
        if ($s === '') {
            return null;
        }
        $s = str_replace([' ', "\u{00A0}"], '', $s);
        $s = str_replace(',', '.', $s);
        if (! is_numeric($s)) {
            return null;
        }

        return (float) $s;
    }
}
