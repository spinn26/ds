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
                't.amount', 't.currency', 't.amountRUB',
                't.netRevenueRUB', 'cm.personalVolume', 'cm.amountRUB as commissionRub', 't.profitRUB',
            ]);

        return $rows->map(fn ($r) => [
            $r->srcPartner, $r->recvPartner, $r->clientName,
            $r->providerName, $r->productName, $r->programName,
            $r->number, $r->contractId, $r->date,
            $this->n($r->amount), $r->currency, $this->n($r->amountRUB),
            '', $this->n($r->amountRUB),
            $this->n($r->netRevenueRUB),
            $this->n($r->personalVolume),
            $this->n($r->commissionRub), $this->n($r->profitRUB),
        ])->all();
    }
}
