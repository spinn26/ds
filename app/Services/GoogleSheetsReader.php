<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class GoogleSheetsReader
{
    public function __construct(
        private readonly ?ApiSettingsService $settings = null,
    ) {}

    /**
     * Прочитать данные с листа Google Sheets.
     *
     * Вариант 1: Через API Key (таблица должна быть расшарена "Anyone with link")
     * Вариант 2: Через экспорт CSV (без API Key, таблица должна быть публичной)
     *
     * @param string $spreadsheetId ID таблицы из URL
     * @param string $sheetName Имя листа (поставщик)
     * @param string|null $apiKey Google API Key — если null, берётся из ApiSettingsService.
     * @return array Массив строк [{column: value}, ...]
     */
    public function readSheet(string $spreadsheetId, string $sheetName, ?string $apiKey = null): array
    {
        $apiKey ??= $this->settings?->get('google.sheets.api_key');

        if ($apiKey) {
            return $this->readViaApi($spreadsheetId, $sheetName, $apiKey);
        }

        return $this->readViaCsvExport($spreadsheetId, $sheetName);
    }

    /**
     * Получить список листов таблицы.
     */
    public function getSheetNames(string $spreadsheetId, ?string $apiKey = null): array
    {
        $apiKey ??= $this->settings?->get('google.sheets.api_key');
        if (! $apiKey) return [];

        $url = "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}?key={$apiKey}&fields=sheets.properties.title";

        $response = Http::timeout(15)->get($url);
        if (! $response->ok()) return [];

        $sheets = $response->json('sheets') ?? [];
        return array_map(fn ($s) => $s['properties']['title'] ?? '', $sheets);
    }

    /**
     * Через Google Sheets API v4 (нужен API Key).
     */
    private function readViaApi(string $spreadsheetId, string $sheetName, string $apiKey): array
    {
        $encodedSheet = urlencode($sheetName);
        $url = "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values/{$encodedSheet}?key={$apiKey}";

        $response = Http::timeout(30)->get($url);
        if (! $response->ok()) {
            throw new \RuntimeException("Google Sheets API error: " . $response->status() . " " . $response->body());
        }

        $values = $response->json('values') ?? [];
        if (empty($values)) return [];

        // Первая строка — заголовки
        $headers = array_map(fn ($h) => strtolower(trim($h)), $values[0]);
        $rows = [];

        for ($i = 1; $i < count($values); $i++) {
            $row = [];
            foreach ($headers as $j => $header) {
                $row[$header] = $values[$i][$j] ?? null;
            }
            if (! empty(array_filter($row))) {
                $rows[] = $this->normalizeRow($row);
            }
        }

        return $rows;
    }

    /**
     * Через CSV экспорт (таблица должна быть публичной).
     */
    private function readViaCsvExport(string $spreadsheetId, string $sheetName): array
    {
        // GID можно передать как числовой ID листа, но проще через имя
        $encodedSheet = urlencode($sheetName);
        $url = "https://docs.google.com/spreadsheets/d/{$spreadsheetId}/gviz/tq?tqx=out:csv&sheet={$encodedSheet}";

        $response = Http::timeout(30)->get($url);
        if (! $response->ok()) {
            throw new \RuntimeException("CSV export error: " . $response->status());
        }

        $csv = $response->body();
        $lines = str_getcsv($csv, "\n");
        if (empty($lines)) return [];

        $headers = array_map(fn ($h) => strtolower(trim(str_replace('"', '', $h))), str_getcsv($lines[0]));
        $rows = [];

        for ($i = 1; $i < count($lines); $i++) {
            $values = str_getcsv($lines[$i]);
            $row = [];
            foreach ($headers as $j => $header) {
                $row[$header] = $values[$j] ?? null;
            }
            if (! empty(array_filter($row))) {
                $rows[] = $this->normalizeRow($row);
            }
        }

        return $rows;
    }

    /**
     * Нормализовать имена колонок к стандартным.
     */
    private function normalizeRow(array $row): array
    {
        $normalized = [];
        foreach ($row as $key => $value) {
            if (str_contains($key, 'контракт') || str_contains($key, 'contract') || str_contains($key, 'номер')) {
                $normalized['contract_number'] = trim($value ?? '');
            } elseif (str_contains($key, 'сумма') || str_contains($key, 'amount') || str_contains($key, 'sum')) {
                $normalized['amount'] = str_replace([' ', ','], ['', '.'], $value ?? '0');
            } elseif (str_contains($key, 'дата') || str_contains($key, 'date')) {
                $normalized['date'] = $value;
            } elseif (str_contains($key, 'свойство') || str_contains($key, 'property')) {
                $normalized['property'] = $value;
            } elseif (str_contains($key, 'процент') || str_contains($key, 'ds') || str_contains($key, 'комисс')) {
                $normalized['ds_percent'] = $value;
            } elseif (str_contains($key, 'год') || str_contains($key, 'year')) {
                $normalized['year'] = $value;
            } else {
                $normalized[$key] = $value;
            }
        }
        return $normalized;
    }
}
