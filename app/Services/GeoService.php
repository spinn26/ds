<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Worldwide city autocomplete via Google Places API (New).
 *
 * Endpoint: POST https://places.googleapis.com/v1/places:autocomplete
 * Auth:     header X-Goog-Api-Key (key from api_settings `google.places.api_key`,
 *           требует включённого Places API + биллинга в GCP).
 *
 * В отличие от DaData (только РФ), Google знает города всего мира и отдаёт
 * названия на русском (languageCode=ru). Тип `(cities)` = locality либо
 * administrative_area_level_3.
 *
 * Возвращает нормализованный массив [{ title, value, region, country }] —
 * тот же контракт, что ждёт фронт профиля (Profile.vue) и метод cities().
 */
class GeoService
{
    public function __construct(
        private readonly ApiSettingsService $settings,
    ) {}

    public function isConfigured(): bool
    {
        return $this->settings->get('google.places.api_key') !== null;
    }

    public function suggestCity(string $query, int $count = 8): array
    {
        $apiKey = $this->settings->get('google.places.api_key');
        $query = trim($query);
        if (! $apiKey || mb_strlen($query) < 2) {
            return [];
        }

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
                Log::warning('geo: places autocomplete non-200', [
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
                $country = null;
                $region = null;
                if ($secondary) {
                    $parts = array_values(array_filter(array_map('trim', explode(',', $secondary))));
                    if ($parts) {
                        $country = array_pop($parts);
                        $region = $parts ? implode(', ', $parts) : null;
                    }
                }

                $key = mb_strtolower($main . '|' . ($secondary ?? ''));
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $out[] = [
                    'title'   => $full,
                    'value'   => $main,
                    'region'  => $region,
                    'country' => $country,
                ];
                if (count($out) >= $count) {
                    break;
                }
            }

            return $out;
        } catch (\Throwable $e) {
            Log::warning('geo: places autocomplete error', ['error' => $e->getMessage()]);
            return [];
        }
    }
}
