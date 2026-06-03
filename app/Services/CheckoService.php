<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Обёртка над Checko.ru API (api.checko.ru/v2).
 *
 * Зачем отдельно от DaData: бесплатный тариф DaData НЕ отдаёт налоговый режим
 * (finance.tax_system почти всегда null), а Checko на бесплатном тарифе
 * (100 запросов/сутки) возвращает применяемые спецрежимы в `Налоги.ОсобРежим`
 * (УСН / АУСН / ЕСХН / ПСН / НПД). Используется как приоритетный источник
 * `requisites.tax_regime`; для основной сверки реквизитов остаётся DaData.
 *
 * Ключ хранится в api_settings под `checko.api_key` (ApiSettingsService).
 *
 * Возвращает тот же нормализованный массив, что и DadataService::findByInn,
 * чтобы при желании быть drop-in заменой:
 *   [
 *     'found'            => bool,
 *     'type'            => 'INDIVIDUAL' | 'LEGAL' | null,
 *     'name'            => наименование (ЮЛ) или ФИО (ИП),
 *     'fio'             => ФИО (только ИП),
 *     'status'          => 'ACTIVE' | 'LIQUIDATED' | null,
 *     'inn', 'ogrn',
 *     'address'         => адрес/регион,
 *     'registrationDate'=> 'Y-m-d',
 *     'taxSystem'       => первый спецрежим (метка, напр. 'УСН') | null,
 *     'taxSystemLabel'  => все спецрежимы через запятую (напр. 'УСН, ПСН') | null,
 *     'raw'             => блок data,
 *     'error'           => строка при ошибке,
 *   ]
 */
class CheckoService
{
    private const BASE = 'https://api.checko.ru/v2';

    public function __construct(
        private readonly ApiSettingsService $settings,
    ) {}

    public function isConfigured(): bool
    {
        return $this->settings->get('checko.api_key') !== null;
    }

    /**
     * Проверка ИНН. По 12-значному ИНН идёт в /entrepreneur (ИП), по 10-значному
     * — в /company (ЮЛ). Не бросает исключения наружу — возвращает found=false.
     */
    public function findByInn(string $inn): array
    {
        $apiKey = $this->settings->get('checko.api_key');
        if (! $apiKey) {
            return ['found' => false, 'error' => 'Checko API key не настроен в /admin/api-keys'];
        }

        $inn = preg_replace('/\D/', '', $inn);
        $isIndividual = strlen($inn) === 12;
        $isLegal = strlen($inn) === 10;
        if (! $isIndividual && ! $isLegal) {
            return ['found' => false, 'error' => "Некорректный ИНН ({$inn}): должно быть 10 или 12 цифр"];
        }

        $endpoint = $isIndividual ? '/entrepreneur' : '/company';

        try {
            $response = Http::timeout(8)
                ->acceptJson()
                ->get(self::BASE . $endpoint, [
                    'key' => $apiKey,
                    'inn' => $inn,
                ]);

            if (! $response->ok()) {
                Log::warning('checko: non-200', ['status' => $response->status(), 'body' => mb_substr((string) $response->body(), 0, 200)]);
                return ['found' => false, 'error' => "Checko вернул HTTP {$response->status()}"];
            }

            $json = $response->json();
            $metaStatus = $json['meta']['status'] ?? null;
            if ($metaStatus !== null && $metaStatus !== 'ok') {
                return ['found' => false, 'error' => $json['meta']['message'] ?? 'Checko: запись не найдена'];
            }

            $data = $json['data'] ?? null;
            if (empty($data)) {
                return ['found' => false, 'error' => 'По такому ИНН в Checko ничего не найдено'];
            }

            // Спецрежимы — массив человекочитаемых меток («УСН», «ПСН», …).
            $modes = array_values(array_filter(array_map(
                fn ($m) => is_string($m) ? trim($m) : null,
                (array) ($data['Налоги']['ОсобРежим'] ?? [])
            )));
            $taxSystemLabel = $modes ? implode(', ', $modes) : null;

            $name = $isIndividual
                ? ($data['ФИО'] ?? null)
                : ($data['НаимПолн'] ?? $data['НаимСокр'] ?? null);

            return [
                'found'   => true,
                'type'    => $isIndividual ? 'INDIVIDUAL' : 'LEGAL',
                'name'    => $name,
                'fio'     => $isIndividual ? ($data['ФИО'] ?? null) : null,
                'status'  => $this->normalizeStatus($data['Статус'] ?? null),
                'inn'     => $data['ИНН'] ?? $inn,
                'ogrn'    => $data['ОГРНИП'] ?? $data['ОГРН'] ?? null,
                'address' => $this->extractAddress($data, $isIndividual),
                'registrationDate' => ! empty($data['ДатаРег'])
                    ? date('Y-m-d', strtotime((string) $data['ДатаРег']))
                    : null,
                'taxSystem'      => $modes[0] ?? null,
                'taxSystemLabel' => $taxSystemLabel,
                'raw' => $data,
            ];
        } catch (\Throwable $e) {
            Log::warning('checko: network error', ['error' => $e->getMessage()]);
            return ['found' => false, 'error' => 'Сетевая ошибка Checko: ' . $e->getMessage()];
        }
    }

    /** Статус из Checko (объект {Код, Наим}) → ACTIVE / LIQUIDATED. */
    private function normalizeStatus($status): ?string
    {
        $label = is_array($status) ? ($status['Наим'] ?? null) : (is_string($status) ? $status : null);
        if (! $label) return null;
        $low = mb_strtolower($label);
        foreach (['ликвид', 'прекрат', 'недейств', 'исключ'] as $needle) {
            if (mb_strpos($low, $needle) !== false) return 'LIQUIDATED';
        }
        return 'ACTIVE';
    }

    /** Для ИП — населённый пункт/регион; для ЮЛ — юр.адрес. */
    private function extractAddress(array $data, bool $isIndividual): ?string
    {
        if (! $isIndividual) {
            return $data['ЮрАдрес']['АдресРФ'] ?? $data['Адрес']['АдресРФ'] ?? $data['Адрес'] ?? null;
        }
        $region = $data['Регион']['Наим'] ?? null;
        $town = $data['НасПункт'] ?? null;
        $parts = array_filter([$town, $region], fn ($v) => is_string($v) && $v !== '');
        return $parts ? implode(', ', array_unique($parts)) : null;
    }
}
