<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\DB;

/** Per spec ✅Отчеты §3.5 — детальный по сделкам с аналитикой отрыва. */
class CommissionsReport extends AbstractReportType
{
    public function key(): string { return 'commissions'; }
    public function headers(): array
    {
        return ['Номер', 'Поставщик', 'Продукт', 'Программа', 'Клиент',
            'Дата', 'Сумма',
            'Доход DS', 'Без НДС',
            'ЛП', 'Партнёр', 'Комиссия', 'Прибыль',
            'Комиссия до отрыва', 'Прибыль до отрыва'];
    }

    public function rows(string $from, string $to, array $filters): array
    {
        $rows = DB::table('transaction as t')
            ->leftJoin('contract as c', 'c.id', '=', 't.contract')
            ->leftJoin('product as p', 'p.id', '=', 'c.product')
            ->leftJoin('program as pr', 'pr.id', '=', 'c.program')
            ->leftJoin('consultant as cn', 'cn.id', '=', 'c.consultant')
            ->whereNull('t.deletedAt')
            ->whereBetween('t.date', [$from, $to])
            ->orderByDesc('t.date')
            ->limit(50000)
            ->get([
                'c.number', 'pr.providerName', 'p.name as productName', 'pr.name as programName',
                'c.clientName', 't.date', 't.amountRUB',
                't.netRevenueRUB', 't.dsCommissionPercentage',
                't.personalVolume', 'cn.personName as partnerName',
                't.commissionsAmountRUB', 't.profitRUB',
                't.commissionAmountRubBeforeGapReduction', 't.profitRubBeforeGapReduction',
            ]);

        return $rows->map(fn ($r) => [
            $r->number, $r->providerName, $r->productName, $r->programName,
            $r->clientName, $r->date, $this->n($r->amountRUB),
            $this->n($this->dsRevenueGross($r)), $this->n($r->netRevenueRUB),
            $this->n($r->personalVolume), $r->partnerName,
            $this->n($r->commissionsAmountRUB), $this->n($r->profitRUB),
            $this->n($r->commissionAmountRubBeforeGapReduction),
            $this->n($r->profitRubBeforeGapReduction),
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
