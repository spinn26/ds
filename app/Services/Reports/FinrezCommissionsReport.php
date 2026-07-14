<?php

namespace App\Services\Reports;

use App\Support\SupplierResolver;
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
            'Доход DS RUB', 'Без НДС RUB',
            'ЛП', 'Комиссия', 'Прибыль'];
    }

    public function rows(string $from, string $to, array $filters): array
    {
        $rows = DB::table('commission as cm')
            ->leftJoin('transaction as t', 't.id', '=', 'cm.transaction')
            ->leftJoin('contract as c', 'c.id', '=', 't.contract')
            ->leftJoin('program as pr', 'pr.id', '=', 'c.program')
            // Catalog names (source of truth after 2026-07-06 remap); legacy
            // denormalized c.productName/c.programName + pr.providerName kept as fallback.
            ->leftJoin('products_catalog as pc', 'pc.legacy_product_id', '=', 'c.product')
            ->leftJoin('programs_catalog as prc', 'prc.legacy_program_id', '=', 'c.program')
            ->leftJoin('currency as cur', 'cur.id', '=', 't.currency')
            ->leftJoin('consultant as recv', 'recv.id', '=', 'cm.consultant')
            ->leftJoin('consultant as src', 'src.id', '=', 'c.consultant')
            ->whereNull('cm.deletedAt')
            ->whereBetween('cm.date', [$from, $to])
            ->orderByDesc('cm.date')
            ->limit(50000)
            ->get([
                'src.personName as srcPartner', 'recv.personName as recvPartner', 'c.clientName',
                // Поставщик — как в CommissionsReport: приоритет программе,
                // Insmart мапится через SupplierResolver ниже.
                DB::raw(SupplierResolver::sqlProviderExpr('pr', 'pc') . ' as "providerName"'),
                DB::raw('COALESCE(pc.name, c."productName") as "productName"'),
                DB::raw('COALESCE(prc.name, c."programName") as "programName"'),
                'c.number', 'c.id as contractId', 't.date',
                't.amount', 'cur.symbol as curSymbol', 't.amountRUB',
                't.netRevenueRUB', 't.dsCommissionPercentage',
                't.commissionsAmountRUB',
                'cm.personalVolume',
                DB::raw('COALESCE(cm."amountRUB", cm.amount, 0) as commissionRub'),
                't.profitRUB',
            ]);

        // Per spec ✅Отчет Финрез комиссии по ФК: «Доход без НДС в RUB — БАЗА для
        // расчёта выплаты партнёру».
        //   «Доход DS RUB» = amountRUB × %ДС / 100   (ВАЛОВЫЙ, с НДС)
        //   «Без НДС RUB»  = commissionsAmountRUB     (доход ДС без НДС = база)
        // Раньше в «Доход DS RUB» шёл commissionsAmountRUB (это уже БЕЗ НДС), а в
        // «Без НДС RUB» — netRevenueRUB (это «остаток ДС» после выплат цепочке).
        // Обе колонки были смещены по смыслу на одну позицию.
        //
        // «Комиссия» здесь построчная (cm.amountRUB) — отчёт имеет грануляцию
        // «одна строка на commission», это by-design. ⚠ «Прибыль» — величина
        // ТРАНЗАКЦИИ и повторяется в каждой строке цепочки: суммировать этот
        // столбец нельзя (получится двойной счёт).
        return $rows->map(function ($r) {
            return [
                $r->srcPartner, $r->recvPartner, $r->clientName,
                SupplierResolver::resolve($r->productName, $r->providerName),
                $r->productName, $r->programName,
                $r->number, $r->contractId, $r->date,
                $this->n($r->amount), $r->curSymbol, $this->n($r->amountRUB),
                $this->n((float) ($r->amountRUB ?? 0) * (float) ($r->dsCommissionPercentage ?? 0) / 100),
                $this->n($r->commissionsAmountRUB),
                $this->n($r->personalVolume),
                $this->n($r->commissionRub), $this->n($r->profitRUB),
            ];
        })->all();
    }
}
