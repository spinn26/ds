<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\DB;

/** Per spec ✅Отчеты §3.7 — мастер-отчёт транзакций. */
class FinrezTransactionsReport extends AbstractReportType
{
    public function key(): string { return 'finrez_transactions'; }
    public function headers(): array
    {
        return ['Номер', 'Поставщик', 'Продукт', 'Программа', 'Клиент', 'Дата',
            'Сумма RUB', 'Доход DS', 'Без НДС RUB',
            'Партнёр', 'Комиссия', 'Прибыль',
            'Сумма исх.', 'Валюта', 'Тип', 'Кол-во оплат', 'Срок', 'Дата открытия'];
    }

    public function rows(string $from, string $to, array $filters): array
    {
        $rows = DB::table('transaction as t')
            ->leftJoin('contract as c', 'c.id', '=', 't.contract')
            ->leftJoin('program as pr', 'pr.id', '=', 'c.program')
            // Catalog names (source of truth after 2026-07-06 remap); legacy
            // denormalized c.productName/c.programName + pr.providerName kept as fallback.
            ->leftJoin('products_catalog as pc', 'pc.legacy_product_id', '=', 'c.product')
            ->leftJoin('programs_catalog as prc', 'prc.legacy_program_id', '=', 'c.program')
            ->leftJoin('currency as cur', 'cur.id', '=', 't.currency')
            ->leftJoin('consultant as cn', 'cn.id', '=', 'c.consultant')
            ->whereNull('t.deletedAt')
            ->whereBetween('t.date', [$from, $to])
            ->orderByDesc('t.date')
            ->limit(50000)
            ->get([
                't.id', 'c.number',
                DB::raw('COALESCE(pc.provider_name, pr."providerName") as "providerName"'),
                DB::raw('COALESCE(pc.name, c."productName") as "productName"'),
                DB::raw('COALESCE(prc.name, c."programName") as "programName"'),
                'c.clientName', 't.date', 't.amountRUB', 't.netRevenueRUB',
                't.dsCommissionPercentage',
                'cn.personName as partner', 't.commissionsAmountRUB', 't.profitRUB',
                't.amount', 'cur.symbol as curSymbol', 't.score', 'c.paymentCount',
                'c.term', 'c.openDate',
            ]);

        // «Доход DS» = сохранённое commissionsAmountRUB (как колонка «Доход DS RUB»
        // на странице «Комиссии»), а «Комиссия» = Σ комиссий цепочки по транзакции
        // (как одноимённая колонка страницы). Раньше «Доход DS» считался как
        // netRevenue×1.05 (≠ странице), а «Комиссия» = commissionsAmountRUB (это
        // доход DS, а не выплаты партнёрам) — обе колонки расходились со страницей.
        $chain = $this->chainCommissionByTx($rows->pluck('id')->all());

        return $rows->map(fn ($r) => [
            $r->number, $r->providerName, $r->productName, $r->programName,
            $r->clientName, $r->date, $this->n($r->amountRUB),
            $this->n($r->commissionsAmountRUB), $this->n($r->netRevenueRUB),
            $r->partner, $this->n($chain[$r->id] ?? 0), $this->n($r->profitRUB),
            $this->n($r->amount), $r->curSymbol, $r->score,
            $r->paymentCount, $r->term, $r->openDate,
        ])->all();
    }
}
