<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Worldwide city autocomplete.
 *
 * Источник выбирается автоматически:
 *   1. Google Places (New) — если задан ключ `google.places.api_key`. Лучшее
 *      качество, всё на русском (languageCode=ru).
 *   2. Nominatim (OpenStreetMap) — фолбэк БЕЗ ключа и регистрации. Города
 *      всего мира на русском (accept-language=ru), понимает кириллический
 *      ввод. Photon не подошёл: у него нет ru-локали (Токио → 東京都).
 *
 * В отличие от DaData (только РФ) оба источника знают города всего мира.
 * Возвращает нормализованный массив [{ title, value, region, country }] —
 * тот же контракт, что ждёт фронт профиля (Profile.vue) и метод cities().
 *
 * Результаты кэшируются на сутки по нормализованному запросу — и ускоряет
 * UI, и бережёт публичный Nominatim (политика: ~1 запрос/сек).
 */
class GeoService
{
    private const CACHE_TTL = 86400; // 24h

    // Типы мест Nominatim, которые считаем «городом/населённым пунктом».
    private const PLACE_TYPES = ['city', 'town', 'village', 'municipality', 'hamlet', 'borough'];

    public function __construct(
        private readonly ApiSettingsService $settings,
    ) {}

    /** Настроен ли премиум-провайдер (Google). Nominatim доступен всегда. */
    public function hasGoogle(): bool
    {
        return $this->settings->get('google.places.api_key') !== null;
    }

    /** Главная точка входа: Google если есть ключ, иначе Nominatim. */
    public function suggestCity(string $query, int $count = 8): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }

        $provider = $this->hasGoogle() ? 'google' : 'osm';
        $cacheKey = 'geo:city:' . $provider . ':' . mb_strtolower($query);

        $ttl = (int) \App\Models\SystemSetting::value('performance.geo_cache_ttl_hours', 24) * 3600;
        return Cache::remember($cacheKey, $ttl, function () use ($provider, $query, $count) {
            return $provider === 'google'
                ? $this->suggestViaGoogle($query, $count)
                : $this->suggestViaNominatim($query, $count);
        });
    }

    // --- Google Places (New) ---

    private function suggestViaGoogle(string $query, int $count): array
    {
        $apiKey = $this->settings->get('google.places.api_key');

        try {
            $response = Http::timeout(6)
                ->withHeaders([
                    'X-Goog-Api-Key' => $apiKey,
                    // Урезаем ответ до нужных полей — меньше трафик/биллинг.
                    'X-Goog-FieldMask' => 'suggestions.placePrediction.text,suggestions.placePrediction.structuredFormat',
                    'Content-Type' => 'application/json',
                ])
                ->post('https://places.googleapis.com/v1/places:autocomplete', [
                    'input' => $query,
                    'languageCode' => 'ru',
                    // Коллекцию (cities) нельзя комбинировать с другими типами.
                    'includedPrimaryTypes' => ['(cities)'],
                ]);

            if (! $response->ok()) {
                Log::warning('geo: google autocomplete non-200', [
                    'status' => $response->status(),
                    'body' => mb_substr((string) $response->body(), 0, 300),
                ]);
                return [];
            }

            $seen = [];
            $out = [];
            foreach ($response->json('suggestions') ?? [] as $s) {
                $p = $s['placePrediction'] ?? null;
                if (! $p) {
                    continue;
                }

                $main = $p['structuredFormat']['mainText']['text'] ?? null;
                $secondary = $p['structuredFormat']['secondaryText']['text'] ?? null;
                $full = $p['text']['text'] ?? $main;
                if (! $main) {
                    continue;
                }

                // secondaryText = «Регион, …, Страна» — последняя часть страна.
                [$country, $region] = $this->splitSecondary($secondary);

                if (! $this->pushUnique($out, $seen, $main, $secondary, $full, $region, $country, $count)) {
                    break;
                }
            }

            return $out;
        } catch (\Throwable $e) {
            Log::warning('geo: google autocomplete error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    // --- Nominatim (OSM) ---

    private function suggestViaNominatim(string $query, int $count): array
    {
        try {
            $response = Http::timeout(6)
                // Nominatim требует осмысленный User-Agent с контактом.
                ->withHeaders(['User-Agent' => 'newds-platform/1.0 (dev.dsconsult.ru)'])
                ->get('https://nominatim.openstreetmap.org/search', [
                    'q' => $query,
                    'format' => 'jsonv2',
                    'accept-language' => 'ru',
                    'addressdetails' => 1,
                    'limit' => $count * 2, // запас под фильтр/дедуп
                ]);

            if (! $response->ok()) {
                Log::warning('geo: nominatim non-200', ['status' => $response->status()]);
                return [];
            }

            $rows = $response->json() ?? [];
            // Сначала — только населённые пункты; если таких нет, берём как есть.
            $cities = array_filter($rows, fn ($r) => in_array(($r['addresstype'] ?? $r['type'] ?? null), self::PLACE_TYPES, true));
            if (! $cities) {
                $cities = $rows;
            }

            $seen = [];
            $out = [];
            foreach ($cities as $r) {
                $name = $r['name'] ?? null;
                if (! $name) {
                    continue;
                }
                $addr = $r['address'] ?? [];
                $country = $addr['country'] ?? null;
                $region = $addr['state'] ?? $addr['region'] ?? $addr['county'] ?? null;
                $full = implode(', ', array_filter([$name, $region, $country]));

                if (! $this->pushUnique($out, $seen, $name, $country, $full, $region, $country, $count)) {
                    break;
                }
            }

            return $out;
        } catch (\Throwable $e) {
            Log::warning('geo: nominatim error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    // --- helpers ---

    /** «Регион, …, Страна» → [country, region]. */
    private function splitSecondary(?string $secondary): array
    {
        if (! $secondary) {
            return [null, null];
        }
        $parts = array_values(array_filter(array_map('trim', explode(',', $secondary))));
        if (! $parts) {
            return [null, null];
        }
        $country = array_pop($parts);
        $region = $parts ? implode(', ', $parts) : null;

        return [$country, $region];
    }

    /**
     * Добавляет элемент в $out, если ключ (name|disambiguator) ещё не встречался.
     * Возвращает false, когда достигнут лимит $count (сигнал прервать цикл).
     */
    private function pushUnique(array &$out, array &$seen, string $value, ?string $disambiguator, string $title, ?string $region, ?string $country, int $count): bool
    {
        $key = mb_strtolower($value . '|' . ($disambiguator ?? ''));
        if (isset($seen[$key])) {
            return true;
        }
        $seen[$key] = true;

        $out[] = [
            'title'   => $title,
            'value'   => $value,
            'region'  => $region,
            'country' => $country,
        ];

        return count($out) < $count;
    }
}
