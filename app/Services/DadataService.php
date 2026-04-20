<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Обёртка над DaData «find party by INN» (dadata.ru/api/suggestions).
 *
 * API key хранится в api_settings под ключом `dadata.api_key`, берётся через
 * ApiSettingsService. Free tier: 10k запросов/сутки.
 *
 * Возвращает нормализованный массив:
 *   [
 *     'found'       => bool,
 *     'type'        => 'INDIVIDUAL' | 'LEGAL' | null,
 *     'name'        => 'Индивидуальный предприниматель Иванов И. И.',
 *     'fio'         => 'Иванов Иван Иванович',       // только для ИП
 *     'status'      => 'ACTIVE' | 'LIQUIDATED' | …,
 *     'inn'         => '780112345678',
 *     'ogrn'        => '312784712400020',
 *     'address'     => 'г Санкт-Петербург, …',
 *     'raw'         => полный ответ DaData,
 *   ]
 */
class DadataService
{
    public function __construct(
        private readonly ApiSettingsService $settings,
    ) {}

    public function isConfigured(): bool
    {
        return $this->settings->get('dadata.api_key') !== null;
    }

    /**
     * Проверка ИНН. Возвращает массив с found=false при ошибке/ненахождении,
     * не бросает исключения наружу.
     */
    public function findByInn(string $inn): array
    {
        $apiKey = $this->settings->get('dadata.api_key');
        if (! $apiKey) {
            return ['found' => false, 'error' => 'DaData API key не настроен в /admin/api-keys'];
        }

        $inn = preg_replace('/\D/', '', $inn);
        if (strlen($inn) !== 10 && strlen($inn) !== 12) {
            return ['found' => false, 'error' => "Некорректный ИНН ({$inn}): должно быть 10 или 12 цифр"];
        }

        try {
            $response = Http::timeout(8)
                ->withHeaders([
                    'Authorization' => 'Token ' . $apiKey,
                    'Accept' => 'application/json',
                ])
                ->post('https://suggestions.dadata.ru/suggestions/api/4_1/rs/findById/party', [
                    'query' => $inn,
                    'count' => 1,
                ]);

            if (! $response->ok()) {
                Log::warning('dadata: non-200', ['status' => $response->status(), 'body' => mb_substr((string) $response->body(), 0, 200)]);
                return ['found' => false, 'error' => "DaData вернула HTTP {$response->status()}"];
            }

            $suggestions = $response->json('suggestions') ?? [];
            if (empty($suggestions)) {
                return ['found' => false, 'error' => 'По такому ИНН ничего не найдено'];
            }

            $s = $suggestions[0];
            $data = $s['data'] ?? [];

            return [
                'found'   => true,
                'type'    => $data['type'] ?? null,
                'name'    => $s['value'] ?? null,
                'fio'     => $this->extractFio($data),
                'status'  => $data['state']['status'] ?? null,
                'inn'     => $data['inn'] ?? null,
                'ogrn'    => $data['ogrn'] ?? null,
                'kpp'     => $data['kpp'] ?? null,
                'okved'   => $data['okved'] ?? null,
                'address' => $data['address']['value'] ?? null,
                'registrationDate' => isset($data['state']['registration_date'])
                    ? date('Y-m-d', (int) ($data['state']['registration_date'] / 1000))
                    : null,
                'raw' => $data,
            ];
        } catch (\Throwable $e) {
            Log::warning('dadata: network error', ['error' => $e->getMessage()]);
            return ['found' => false, 'error' => 'Сетевая ошибка DaData: ' . $e->getMessage()];
        }
    }

    /**
     * Сравнить ФИО из DaData c ФИО партнёра. Возвращает структуру:
     *   [match, expected, actual, firstMatch, lastMatch, patronymicMatch]
     */
    public function compareFio(?string $actualFio, ?string $expectedLast, ?string $expectedFirst, ?string $expectedPatronymic): array
    {
        $actual = $this->normalizeFio($actualFio);
        $exp = $this->normalizeFio(trim(($expectedLast ?? '') . ' ' . ($expectedFirst ?? '') . ' ' . ($expectedPatronymic ?? '')));

        $firstMatch = $exp['first'] && $actual['first'] && $exp['first'] === $actual['first'];
        $lastMatch  = $exp['last']  && $actual['last']  && $exp['last']  === $actual['last'];
        $patMatch   = (! $exp['patronymic']) || ($actual['patronymic'] && $exp['patronymic'] === $actual['patronymic']);

        return [
            'match' => $firstMatch && $lastMatch && $patMatch,
            'expected' => trim(implode(' ', array_filter([$exp['last'], $exp['first'], $exp['patronymic']]))),
            'actual'   => trim(implode(' ', array_filter([$actual['last'], $actual['first'], $actual['patronymic']]))),
            'firstMatch' => $firstMatch,
            'lastMatch' => $lastMatch,
            'patronymicMatch' => $patMatch,
        ];
    }

    /** ФИО для ИП — в data.fio или data.name.full. */
    private function extractFio(array $data): ?string
    {
        if (! empty($data['fio']['source'])) return $data['fio']['source'];
        if (! empty($data['fio']['value'])) return $data['fio']['value'];
        if (! empty($data['name']['full']) && ($data['type'] ?? null) === 'INDIVIDUAL') {
            // name.full может быть "Индивидуальный предприниматель Иванов Иван Иванович"
            return preg_replace('/^(индивидуальный предприниматель|ип)\s+/iu', '', $data['name']['full']);
        }
        return null;
    }

    /** Разложить ФИО → ['last','first','patronymic'] в lowercase без лишних пробелов. */
    private function normalizeFio(?string $fio): array
    {
        $parts = preg_split('/\s+/u', mb_strtolower(trim((string) $fio)));
        return [
            'last'       => $parts[0] ?? '',
            'first'      => $parts[1] ?? '',
            'patronymic' => $parts[2] ?? '',
        ];
    }
}
