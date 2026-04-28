<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\DB;

/** Per spec ✅Отчеты §3.1 — сводный отчёт по продуктам. */
class RevenueExpensesReport extends AbstractReportType
{
    public function key(): string { return 'revenue_expenses'; }
    public function headers(): array { return ['Продукт', 'Доход', 'Расход']; }

    public function rows(string $dateFrom, string $dateTo, array $filters): array
    {
        $rows = DB::table('transaction as t')
            ->leftJoin('contract as c', 'c.id', '=', 't.contract')
            ->whereNull('t.deletedAt')
            ->whereBetween('t.date', [$dateFrom, $dateTo])
            ->select(
                DB::raw('COALESCE(c."productName", \'—\') as product'),
                DB::raw('SUM(t."amountRUB") as income'),
                DB::raw('SUM(t."commissionsAmountRUB") as expense')
            )
            ->groupBy('c.productName')
            ->orderBy('product')
            ->get();

        return $rows->map(fn ($r) => [$r->product, $this->n($r->income), $this->n($r->expense)])->all();
    }
}
