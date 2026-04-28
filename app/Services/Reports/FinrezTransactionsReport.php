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
            ->leftJoin('currency as cur', 'cur.id', '=', 't.currency')
            ->leftJoin('consultant as cn', 'cn.id', '=', 'c.consultant')
            ->whereNull('t.deletedAt')
            ->whereBetween('t.date', [$from, $to])
            ->orderByDesc('t.date')
            ->limit(50000)
            ->get([
                'c.number', 'pr.providerName', 'c.productName', 'c.programName',
                'c.clientName', 't.date', 't.amountRUB', 't.netRevenueRUB',
                't.dsCommissionPercentage',
                'cn.personName as partner', 't.commissionsAmountRUB', 't.profitRUB',
                't.amount', 'cur.symbol as curSymbol', 't.score', 'c.paymentCount',
                'c.term', 'c.openDate',
            ]);

        return $rows->map(fn ($r) => [
            $r->number, $r->providerName, $r->productName, $r->programName,
            $r->clientName, $r->date, $this->n($r->amountRUB),
            $this->n($this->dsRevenueGross($r)), $this->n($r->netRevenueRUB),
            $r->partner, $this->n($r->commissionsAmountRUB), $this->n($r->profitRUB),
            $this->n($r->amount), $r->curSymbol, $r->score,
            $r->paymentCount, $r->term, $r->openDate,
        ])->all();
    }

    /** Доход DS до вычета НДС: net × 1.05, либо amountRUB × dsCommission% / 100 как fallback. */
    private function dsRevenueGross(object $r): float
    {
        if (! empty($r->netRevenueRUB)) {
            return ((float) $r->netRevenueRUB) * 1.05;
        }
        $pct = (float) ($r->dsCommissionPercentage ?? 0);
        if ($pct > 0 && ! empty($r->amountRUB)) {
            return ((float) $r->amountRUB) * $pct / 100.0;
        }
        return 0.0;
    }
}
