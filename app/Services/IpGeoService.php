<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Резолв IP → страна/регион/город с локальным кэшем.
 *
 * Источник — ip-api.com (бесплатный JSON-эндпоинт, без ключа, лимит
 * 45 rps с одного IP). Этого с запасом хватает для админских окон
 * «История входа»: подгружаем при первом просмотре, складываем в
 * `ip_geo_cache`, дальше отдаём из БД.
 *
 * Не падаем на ошибках — это вторичная фича, провал резолва → пустые
 * поля гео, IP всё равно показываем.
 */
class IpGeoService
{
    private const TTL_OK_DAYS = 30;       // успешные резолвы
    private const TTL_FAIL_HOURS = 6;     // неудачные — пробуем заново быстрее
    private const ENDPOINT = 'http://ip-api.com/json/';

    /**
     * Массовый резолв. Возвращает массив ip => ['country'=>..., 'region'=>..., 'city'=>...].
     * Один HTTP-вызов на IP, но только для cache-miss'ов.
     */
    public function resolveMany(array $ips): array
    {
        $ips = array_values(array_unique(array_filter($ips)));
        if (! $ips) return [];

        // Загружаем существующий кэш одним запросом.
        $cached = DB::table('ip_geo_cache')
            ->whereIn('ip', $ips)
            ->get()
            ->keyBy('ip');

        $now = now();
        $okThreshold = $now->copy()->subDays(self::TTL_OK_DAYS);
        $failThreshold = $now->copy()->subHours(self::TTL_FAIL_HOURS);

        $result = [];
        foreach ($ips as $ip) {
            $row = $cached->get($ip);
            $stale = ! $row || (
                $row->status === 'ok' && $row->resolved_at < $okThreshold
            ) || (
                $row->status !== 'ok' && $row->resolved_at < $failThreshold
            );

            if (! $stale) {
                $result[$ip] = $this->formatRow($row);
                continue;
            }

            // Приватные/loopback пропускаем без HTTP-вызова.
            if ($this->isPrivate($ip)) {
                $this->upsert($ip, [
                    'status' => 'private', 'country_code' => null,
                    'country_name' => null, 'region' => null, 'city' => null, 'isp' => null,
                ]);
                $result[$ip] = ['country' => 'Локальная сеть', 'region' => null, 'city' => null, 'isp' => null];
                continue;
            }

            $resolved = $this->fetchFromApi($ip);
            $this->upsert($ip, $resolved);
            $result[$ip] = [
                'country' => $resolved['country_name'],
                'region' => $resolved['region'],
                'city' => $resolved['city'],
                'isp' => $resolved['isp'],
            ];
        }

        return $result;
    }

    private function fetchFromApi(string $ip): array
    {
        try {
            // fields: country, regionName, city, isp, status — экономим
            // трафик и не тянем лишнее.
            $res = Http::timeout(3)->get(self::ENDPOINT . $ip, [
                'fields' => 'status,country,countryCode,regionName,city,isp',
                'lang' => 'ru',
            ]);
            if (! $res->ok()) {
                return $this->failRow();
            }
            $j = $res->json();
            if (($j['status'] ?? '') !== 'success') {
                return $this->failRow();
            }
            return [
                'status' => 'ok',
                'country_code' => mb_substr((string) ($j['countryCode'] ?? ''), 0, 2) ?: null,
                'country_name' => mb_substr((string) ($j['country'] ?? ''), 0, 80) ?: null,
                'region' => mb_substr((string) ($j['regionName'] ?? ''), 0, 120) ?: null,
                'city' => mb_substr((string) ($j['city'] ?? ''), 0, 120) ?: null,
                'isp' => mb_substr((string) ($j['isp'] ?? ''), 0, 200) ?: null,
            ];
        } catch (\Throwable $e) {
            Log::debug('ip-api resolve failed', ['ip' => $ip, 'error' => $e->getMessage()]);
            return $this->failRow();
        }
    }

    private function failRow(): array
    {
        return [
            'status' => 'fail', 'country_code' => null,
            'country_name' => null, 'region' => null, 'city' => null, 'isp' => null,
        ];
    }

    private function upsert(string $ip, array $data): void
    {
        try {
            DB::table('ip_geo_cache')->upsert(
                [array_merge(['ip' => $ip, 'resolved_at' => now()], $data)],
                ['ip'],
                ['country_code', 'country_name', 'region', 'city', 'isp', 'status', 'resolved_at'],
            );
        } catch (\Throwable $e) {
            // Не критично — следующий запрос попробует ещё раз.
            Log::debug('ip_geo_cache upsert failed', ['ip' => $ip, 'error' => $e->getMessage()]);
        }
    }

    private function formatRow(object $row): array
    {
        if ($row->status === 'private') {
            return ['country' => 'Локальная сеть', 'region' => null, 'city' => null, 'isp' => null];
        }
        if ($row->status !== 'ok') {
            return ['country' => null, 'region' => null, 'city' => null, 'isp' => null];
        }
        return [
            'country' => $row->country_name,
            'region' => $row->region,
            'city' => $row->city,
            'isp' => $row->isp,
        ];
    }

    private function isPrivate(string $ip): bool
    {
        // Loopback / приватные сети / link-local — резолвить бесполезно.
        return ! filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );
    }
}
