<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Http\Request;

/**
 * Универсальный приём параметров сортировки `sort_by` и `sort_dir` от
 * v-data-table-server и применение их к Query Builder через whitelist.
 *
 * Использование в контроллере:
 *   $query = DB::table('contract');
 *   $this->applySorting($query, $request, [
 *       'date' => 'date',
 *       'amount' => 'ammount',
 *       'consultant' => 'consultantPersonName',
 *   ], 'date', 'desc');
 *
 * Контракт фронта (см. composables/useTableSort на фронте):
 *   GET /endpoint?page=1&sort_by=date&sort_dir=desc
 *
 * Если присланный sort_by нет в whitelist или пустой — fallback на
 * default. Это защита от SQL-инъекции через имя колонки.
 */
trait AppliesSorting
{
    /**
     * @param mixed $query Query Builder (Eloquent или DB::table)
     * @param array<string,string> $allowed map: API-key → реальное имя колонки в SQL.
     */
    protected function applySorting(
        $query,
        Request $request,
        array $allowed,
        string $defaultCol,
        string $defaultDir = 'desc'
    ): void {
        $sortBy = (string) $request->input('sort_by', '');
        $sortDir = strtolower((string) $request->input('sort_dir', $defaultDir)) === 'asc' ? 'asc' : 'desc';

        $col = $allowed[$sortBy] ?? null;
        if (! $col) {
            $col = $defaultCol;
            $sortDir = $defaultDir;
        }

        $query->orderByRaw(sprintf('%s %s NULLS LAST', $col, strtoupper($sortDir)));
    }
}
