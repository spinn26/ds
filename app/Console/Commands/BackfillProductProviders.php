<?php

namespace App\Console\Commands;

use App\Support\SupplierResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill products_catalog.provider_name (поставщик на уровне продукта) из
 * существующих данных БД.
 *
 * Источник (как видят отчёты «Комиссии»/«Матрица продаж»):
 *  1) Insmart-продукты → «Insmart» (SupplierResolver).
 *  2) Иначе — самый частый providerName среди legacy-программ продукта
 *     (program.product = products_catalog.legacy_product_id).
 *  3) Фолбэк — самый частый programs_catalog.vendor.
 *
 * Только ЗАПОЛНЯЕТ пустое поле продукта; программы НЕ трогает (per-program
 * поставщики и отчёты остаются как есть). --force перезаписывает и непустые.
 * Безопасно по умолчанию: dry-run, пока не передан --apply.
 */
class BackfillProductProviders extends Command
{
    protected $signature = 'products:backfill-providers
        {--apply : Persist (otherwise dry-run preview only)}
        {--force : Overwrite even non-empty provider_name}';

    protected $description = 'Заполнить products_catalog.provider_name поставщиком из данных БД (legacy program.providerName / Insmart)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $force = (bool) $this->option('force');

        $products = DB::table('products_catalog')->orderBy('id')
            ->get(['id', 'name', 'legacy_product_id', 'provider_name']);

        $planned = [];
        foreach ($products as $p) {
            $current = trim((string) ($p->provider_name ?? ''));
            if ($current !== '' && ! $force) {
                continue; // уже заполнено — не трогаем (idempotent)
            }

            $resolved = $this->resolveProvider($p);
            if ($resolved === null || $resolved === '') {
                continue; // нечего проставить
            }
            if ($resolved === $current) {
                continue; // уже совпадает
            }

            $planned[] = [
                'id' => $p->id,
                'name' => $p->name,
                'from' => $current === '' ? '∅' : $current,
                'to' => $resolved,
            ];
        }

        $this->info(sprintf('Продуктов всего: %d | к проставлению: %d%s',
            $products->count(), count($planned), $apply ? '' : ' (dry-run)'));

        foreach (array_slice($planned, 0, 80) as $row) {
            $this->line(sprintf('  #%-4d %-30s %s → %s',
                $row['id'], mb_substr($row['name'], 0, 30), $row['from'], $row['to']));
        }
        if (count($planned) > 80) {
            $this->line('  … ещё ' . (count($planned) - 80));
        }

        if (! $apply) {
            $this->warn('Dry-run. Запустите с --apply, чтобы записать.');
            return self::SUCCESS;
        }

        $n = 0;
        foreach ($planned as $row) {
            DB::table('products_catalog')->where('id', $row['id'])->update([
                'provider_name' => $row['to'],
                'updated_at' => now(),
            ]);
            $n++;
        }
        $this->info("Проставлено: $n");

        return self::SUCCESS;
    }

    /** Доминирующий поставщик продукта по данным БД. */
    private function resolveProvider(object $p): ?string
    {
        if (SupplierResolver::isInsmartProduct($p->name)) {
            return 'Insmart';
        }

        // Самый частый providerName среди legacy-программ продукта.
        if ($p->legacy_product_id) {
            $legacy = DB::table('program')
                ->where('product', $p->legacy_product_id)
                ->whereNull('dateDeleted')
                ->whereNotNull('providerName')
                ->where('providerName', '<>', '')
                ->groupBy('providerName')
                ->orderByRaw('COUNT(*) DESC')
                ->orderBy('providerName')
                ->value('providerName');
            if ($legacy) {
                return trim((string) $legacy);
            }
        }

        // Фолбэк — самый частый vendor у catalog-программ.
        $vendor = DB::table('programs_catalog')
            ->where('product_id', $p->id)
            ->whereNotNull('vendor')
            ->where('vendor', '<>', '')
            ->groupBy('vendor')
            ->orderByRaw('COUNT(*) DESC')
            ->orderBy('vendor')
            ->value('vendor');

        return $vendor ? trim((string) $vendor) : null;
    }
}
