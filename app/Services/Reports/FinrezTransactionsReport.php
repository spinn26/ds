<?php

namespace App\Services\Reports;

use App\Support\SupplierResolver;
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
                // Поставщик — как в CommissionsReport: приоритет программе
                // (она per-program), Insmart мапится через SupplierResolver ниже.
                DB::raw('COALESCE(pr."providerName", pc.provider_name) as "providerName"'),
                DB::raw('COALESCE(pc.name, c."productName") as "productName"'),
                DB::raw('COALESCE(prc.name, c."programName") as "programName"'),
                'c.clientName', 't.date', 't.amountRUB', 't.netRevenueRUB',
                't.dsCommissionPercentage',
                'cn.personName as partner', 't.commissionsAmountRUB', 't.profitRUB',
                't.amount', 'cur.symbol as curSymbol', 't.score', 'c.paymentCount',
                'c.term', 'c.openDate',
            ]);

        // Per spec ✅Отчеты §Финрез: «Доход DS до вычета НДС» и «Доход без НДС в RUB».
        //   «Доход DS»    = amountRUB × %ДС / 100        (ВАЛОВЫЙ, с НДС)
        //   «Без НДС RUB» = commissionsAmountRUB          (доход ДС без НДС)
        //   «Комиссия»    = Σ комиссий цепочки
        //   «Прибыль»     = Без НДС − Комиссия
        // Раньше в «Доход DS» шёл commissionsAmountRUB (это уже БЕЗ НДС), а в
        // «Без НДС» — netRevenueRUB (это «остаток ДС» после выплат цепочке, а не
        // доход) → обе колонки были неверны. Тот же баг, что чинили в
        // CommissionsReport; здесь фикс не был применён.
        $chain = $this->chainCommissionByTx($rows->pluck('id')->all());

        return $rows->map(function ($r) use ($chain) {
            $noVat = (float) ($r->commissionsAmountRUB ?? 0);
            $commission = (float) ($chain[$r->id] ?? 0);

            return [
                $r->number,
                SupplierResolver::resolve($r->productName, $r->providerName),
                $r->productName, $r->programName,
                $r->clientName, $r->date, $this->n($r->amountRUB),
                $this->n((float) ($r->amountRUB ?? 0) * (float) ($r->dsCommissionPercentage ?? 0) / 100),
                $this->n($noVat),
                $r->partner, $this->n($commission), $this->n($noVat - $commission),
                $this->n($r->amount), $r->curSymbol, $r->score,
                $r->paymentCount, $r->term, $r->openDate,
            ];
        })->all();
    }
}
