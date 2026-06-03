<?php

namespace App\Console\Commands;

use App\Services\CheckoService;
use App\Services\DadataService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Разовый backfill налогового режима (requisites.tax_regime) по ИНН.
 *
 * Источник — Checko (приоритет, отдаёт спецрежим на бесплатном тарифе) с
 * фоллбэком на DaData. Заполняет ТОЛЬКО пустые tax_regime — не перетирает уже
 * заполненные. НЕ трогает статус верификации (та всегда ручная) — только
 * справочное поле режима.
 *
 * Безопасно по умолчанию: dry-run, пока не передан --apply. Checko free —
 * 100 запросов/сутки; команда шлёт по одному запросу на реквизит с пустым
 * режимом (кэш checko:inn:{inn} 1ч дедуплицирует повторы).
 */
class BackfillRequisitesTaxRegime extends Command
{
    protected $signature = 'requisites:backfill-tax-regime
        {--apply : Persist the changes (otherwise dry-run preview only)}
        {--limit=0 : Process at most N requisites (0 = all)}';

    protected $description = 'Заполнить requisites.tax_regime по ИНН через Checko (фоллбэк DaData) — только пустые';

    public function handle(CheckoService $checko, DadataService $dadata): int
    {
        if (! $checko->isConfigured() && ! $dadata->isConfigured()) {
            $this->error('Ни Checko, ни DaData не настроены (/admin/integrations?tab=api-keys).');
            return self::FAILURE;
        }
        if (! $checko->isConfigured()) {
            $this->warn('Checko не настроен — режим будет искаться только в DaData (на free-тарифе обычно пуст).');
        }

        $query = DB::table('requisites')
            ->whereNull('deletedAt')
            ->whereNotNull('inn')
            ->where('inn', '!=', '')
            ->where(function ($q) {
                $q->whereNull('tax_regime')->orWhere('tax_regime', '');
            })
            ->orderBy('id')
            ->select(['id', 'inn', 'individualEntrepreneur']);

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $query->limit($limit);
        }

        $rows = $query->get();
        $this->info("Реквизитов с пустым режимом: {$rows->count()}");
        if ($rows->isEmpty()) {
            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');
        $filled = 0;
        $preview = [];

        foreach ($rows as $r) {
            $clean = preg_replace('/\D/', '', (string) $r->inn);
            if (strlen($clean) !== 10 && strlen($clean) !== 12) {
                continue;
            }

            $regime = null;
            if ($checko->isConfigured()) {
                $c = Cache::remember("checko:inn:{$clean}", 3600, fn () => $checko->findByInn($clean));
                if (! empty($c['found']) && ! empty($c['taxSystemLabel'])) {
                    $regime = $c['taxSystemLabel'];
                }
            }
            if ($regime === null && $dadata->isConfigured()) {
                $f = Cache::remember("dadata:inn:{$clean}", 3600, fn () => $dadata->findByInn($clean));
                if (! empty($f['found']) && ! empty($f['taxSystemLabel'])) {
                    $regime = $f['taxSystemLabel'];
                }
            }

            if ($regime === null) {
                continue;
            }

            $filled++;
            if (count($preview) < 50) {
                $preview[] = [$r->id, $clean, mb_substr((string) $r->individualEntrepreneur, 0, 30), $regime];
            }

            if ($apply) {
                DB::table('requisites')->where('id', $r->id)->update(['tax_regime' => $regime]);
            }
        }

        if ($preview) {
            $this->table(['req', 'inn', 'ИП', 'режим'], $preview);
        }
        $this->info("Найден режим у: {$filled} из {$rows->count()}");

        if (! $apply) {
            $this->warn('DRY-RUN — изменения не записаны. Повторите с --apply, чтобы применить.');
        } else {
            $this->info('Готово.');
        }
        return self::SUCCESS;
    }
}
