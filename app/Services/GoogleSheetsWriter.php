<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Запись в Google Sheets через СЕРВИСНЫЙ АККАУНТ (server-to-server).
 *
 * В отличие от GoogleSheetsReader (read-only API-ключ), для ЗАПИСИ нужен
 * OAuth-токен, полученный по JWT-подписи ключа сервисного аккаунта. Тяжёлую
 * google/apiclient не тянем — подписываем RS256 сами (openssl) и дёргаем
 * Sheets API на Http.
 *
 * Требования:
 *   - JSON ключа сервисного аккаунта (client_email + private_key);
 *   - целевая таблица расшарена на client_email как «Редактор»;
 *   - включены Google Sheets API (+ Drive API для создания листов).
 *
 * Путь к JSON и id таблицы берутся из api_settings (google.sa.credentials_path,
 * google.sheets.export_id) с фолбэком на config/env.
 */
class GoogleSheetsWriter
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    // spreadsheets — чтение/запись значений; drive — создание таблиц и шаринг.
    private const SCOPE = 'https://www.googleapis.com/auth/spreadsheets https://www.googleapis.com/auth/drive';
    private const API = 'https://sheets.googleapis.com/v4/spreadsheets';
    private const DRIVE_API = 'https://www.googleapis.com/drive/v3/files';

    private ?array $sa = null;
    private ?string $credentialsPath;

    public function __construct(?string $credentialsPath = null)
    {
        // Путь запоминаем, но JSON грузим ЛЕНИВО (только при записи) — чтобы сервис
        // можно было конструировать/тестировать без ключа (напр. проверка SQL).
        $this->credentialsPath = $credentialsPath;
    }

    /**
     * Ленивая загрузка ключа сервисного аккаунта. Приоритет:
     *   1) JSON, вставленный прямо в настройку google.sa.credentials_json (UI);
     *   2) файл по пути google.sa.credentials_path / конструктору / storage.
     */
    private function sa(): array
    {
        if ($this->sa !== null) {
            return $this->sa;
        }

        // 1) JSON из настройки (вставлен в /admin/integrations, хранится зашифрованно).
        $inline = app(ApiSettingsService::class)->get('google.sa.credentials_json');
        if (is_string($inline) && trim($inline) !== '') {
            $json = json_decode($inline, true);
            if (is_array($json) && ! empty($json['client_email']) && ! empty($json['private_key'])) {
                return $this->sa = $json;
            }
            throw new RuntimeException('google.sa.credentials_json задан, но это не валидный service-account JSON (нужны client_email + private_key).');
        }

        // 2) Файл по пути.
        $path = $this->credentialsPath
            ?: (app(ApiSettingsService::class)->get('google.sa.credentials_path')
                ?: config('services.google_sheets.sa_credentials_path')
                ?: storage_path('app/google-sa.json'));

        if (! is_file($path)) {
            throw new RuntimeException("Ключ сервис-аккаунта не задан: ни google.sa.credentials_json (вставка JSON в интеграции), ни файл {$path}.");
        }
        $json = json_decode((string) file_get_contents($path), true);
        if (! is_array($json) || empty($json['client_email']) || empty($json['private_key'])) {
            throw new RuntimeException("Неверный service-account JSON (нет client_email/private_key): {$path}");
        }

        return $this->sa = $json;
    }

    /** OAuth access token (кэш 55 минут по отпечатку ключа). */
    private function accessToken(): string
    {
        $sa = $this->sa();
        $cacheKey = 'gsheets-sa-token:' . md5($sa['client_email'] . '|' . self::SCOPE);

        return Cache::remember($cacheKey, 3300, function () use ($sa) {
            $now = time();
            $claims = [
                'iss' => $sa['client_email'],
                'scope' => self::SCOPE,
                'aud' => self::TOKEN_URL,
                'iat' => $now,
                'exp' => $now + 3600,
            ];
            $jwt = $this->signJwt($claims);

            $resp = Http::asForm()->timeout(30)->post(self::TOKEN_URL, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);
            if (! $resp->ok() || ! $resp->json('access_token')) {
                throw new RuntimeException('OAuth token error: ' . $resp->status() . ' ' . $resp->body());
            }

            return (string) $resp->json('access_token');
        });
    }

    /** Подпись JWT RS256 приватным ключом сервисного аккаунта. */
    private function signJwt(array $claims): string
    {
        $b64 = fn ($d) => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
        $header = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = $b64(json_encode($claims));
        $signingInput = $header . '.' . $payload;

        $signature = '';
        $ok = openssl_sign($signingInput, $signature, $this->sa()['private_key'], OPENSSL_ALGO_SHA256);
        if (! $ok) {
            throw new RuntimeException('openssl_sign failed (проверь private_key)');
        }

        return $signingInput . '.' . $b64($signature);
    }

    private function req(string $method, string $url, array $body = null)
    {
        $r = Http::withToken($this->accessToken())->timeout(60)
            ->send($method, $url, $body !== null ? ['json' => $body] : []);
        if (! $r->ok()) {
            throw new RuntimeException("Sheets API {$method} error: " . $r->status() . ' ' . mb_substr($r->body(), 0, 500));
        }
        return $r->json();
    }

    /**
     * Создать НОВУЮ таблицу с заданными листами. Возвращает spreadsheetId.
     * Таблица создаётся под сервисным аккаунтом — после создания расшарь её
     * на нужный email через shareWith().
     */
    public function createSpreadsheet(string $title, array $tabTitles): string
    {
        $sheets = array_map(fn ($t) => ['properties' => ['title' => $t]], $tabTitles);
        $data = $this->req('POST', self::API, [
            'properties' => ['title' => $title],
            'sheets' => $sheets,
        ]);
        return (string) ($data['spreadsheetId'] ?? '');
    }

    /** Дать доступ email'у к файлу (role: writer/reader/owner). */
    public function shareWith(string $fileId, string $email, string $role = 'writer'): void
    {
        $this->req('POST', self::DRIVE_API . "/{$fileId}/permissions?sendNotificationEmail=false", [
            'role' => $role,
            'type' => 'user',
            'emailAddress' => $email,
        ]);
    }

    /** Метаданные таблицы (список листов). */
    public function sheetTitles(string $spreadsheetId): array
    {
        $data = $this->req('GET', self::API . "/{$spreadsheetId}?fields=sheets.properties.title");
        return array_map(fn ($s) => $s['properties']['title'] ?? '', $data['sheets'] ?? []);
    }

    /** Создать лист, если его ещё нет. */
    public function ensureSheet(string $spreadsheetId, string $title): void
    {
        if (in_array($title, $this->sheetTitles($spreadsheetId), true)) {
            return;
        }
        $this->req('POST', self::API . "/{$spreadsheetId}:batchUpdate", [
            'requests' => [['addSheet' => ['properties' => ['title' => $title]]]],
        ]);
    }

    /** Прочитать все значения листа (для upsert-карты id→строка). */
    public function readValues(string $spreadsheetId, string $range): array
    {
        $data = $this->req('GET', self::API . "/{$spreadsheetId}/values/" . rawurlencode($range));
        return $data['values'] ?? [];
    }

    /** Записать значения в диапазон (RAW, перезапись). */
    public function updateValues(string $spreadsheetId, string $range, array $values): void
    {
        $this->req('PUT', self::API . "/{$spreadsheetId}/values/" . rawurlencode($range) . '?valueInputOption=RAW', [
            'range' => $range,
            'majorDimension' => 'ROWS',
            'values' => $values,
        ]);
    }

    /** Дописать строки в конец листа. */
    public function appendValues(string $spreadsheetId, string $sheetTitle, array $values): void
    {
        if (! $values) return;
        $this->req('POST', self::API . "/{$spreadsheetId}/values/" . rawurlencode($sheetTitle) . ':append?valueInputOption=RAW&insertDataOption=INSERT_ROWS', [
            'range' => $sheetTitle,
            'majorDimension' => 'ROWS',
            'values' => $values,
        ]);
    }

    /** Пакетная перезапись нескольких диапазонов за один вызов. */
    public function batchUpdateValues(string $spreadsheetId, array $data): void
    {
        if (! $data) return;
        $this->req('POST', self::API . "/{$spreadsheetId}/values:batchUpdate", [
            'valueInputOption' => 'RAW',
            'data' => $data, // [['range'=>..., 'majorDimension'=>'ROWS', 'values'=>[[...]]], ...]
        ]);
    }
}
