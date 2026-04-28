<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\DB;

/** Per spec ✅Отчеты §3.6 — связь партнёра, транзакции и комиссии. */
class FinrezCommissionsReport extends AbstractReportType
{
    public function key(): string { return 'finrez_commissions'; }
    public function headers(): array
    {
        return ['Партнёр сделки', 'Партнёр комиссии', 'Клиент',
            'Поставщик', 'Продукт', 'Программа',
            'Номер', 'ID контракта', 'Дата',
            'Сумма', 'Валюта', 'Сумма RUB',
            'Доход DS', 'Доход DS RUB', 'Без НДС RUB',
            'ЛП', 'Комиссия', 'Прибыль'];
    }

    public function rows(string $from, string $to, array $filters): array
    {
        $rows = DB::table('commission as cm')
            ->leftJoin('transaction as t', 't.id', '=', 'cm.transaction')
            ->leftJoin('contract as c', 'c.id', '=', 't.contract')
            ->leftJoin('program as pr', 'pr.id', '=', 'c.program')
            ->leftJoin('currency as cur', 'cur.id', '=', 't.currency')
            ->leftJoin('consultant as recv', 'recv.id', '=', 'cm.consultant')
            ->leftJoin('consultant as src', 'src.id', '=', 'c.consultant')
            ->whereNull('cm.deletedAt')
            ->whereBetween('cm.date', [$from, $to])
            ->orderByDesc('cm.date')
            ->limit(50000)
            ->get([
                'src.personName as srcPartner', 'recv.personName as recvPartner', 'c.clientName',
                'pr.providerName', 'c.productName', 'c.programName',
                'c.number', 'c.id as contractId', 't.date',
                't.amount', 'cur.symbol as curSymbol', 't.amountRUB',
                't.netRevenueRUB', 't.dsCommissionPercentage',
                'cm.personalVolume', 'cm.amountRUB as commissionRub', 't.profitRUB',
            ]);

        return $rows->map(function ($r) {
            $dsRub = $this->dsRevenueGross($r);
            return [
                $r->srcPartner, $r->recvPartner, $r->clientName,
                $r->providerName, $r->productName, $r->programName,
                $r->number, $r->contractId, $r->date,
                $this->n($r->amount), $r->curSymbol, $this->n($r->amountRUB),
                $this->n($dsRub), $this->n($dsRub),
                $this->n($r->netRevenueRUB),
                $this->n($r->personalVolume),
                $this->n($r->commissionRub), $this->n($r->profitRUB),
            ];
        })->all();
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
