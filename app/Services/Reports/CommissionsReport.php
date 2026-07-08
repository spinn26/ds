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
            // Catalog names (source of truth after 2026-07-06 remap); legacy
            // product/program kept as fallback. See AdminFinanceController::transactions.
            ->leftJoin('products_catalog as pc', 'pc.legacy_product_id', '=', 'c.product')
            ->leftJoin('programs_catalog as prc', 'prc.legacy_program_id', '=', 'c.program')
            ->leftJoin('consultant as cn', 'cn.id', '=', 'c.consultant')
            ->whereNull('t.deletedAt')
            ->whereBetween('t.date', [$from, $to])
            ->orderByDesc('t.date')
            ->limit(50000)
            ->get([
                't.id',
                'c.number',
                DB::raw('COALESCE(pc.provider_name, pr."providerName") as "providerName"'),
                DB::raw('COALESCE(pc.name, p.name) as "productName"'),
                DB::raw('COALESCE(prc.name, pr.name) as "programName"'),
                'c.clientName', 't.date', 't.amountRUB',
                't.netRevenueRUB', 't.dsCommissionPercentage',
                't.personalVolume', 'cn.personName as partnerName',
                't.commissionsAmountRUB', 't.profitRUB',
                't.commissionAmountRubBeforeGapReduction', 't.profitRubBeforeGapReduction',
            ]);

        // Per spec commission-spec §: «Доход ДС» = сумма × %ДС (с НДС, gross =
        // amountRUB × %ДС/100), «Доход ДС без НДС» = commissionsAmountRUB
        // (калькулятор считает его от amountNoVat × %ДС). Раньше в отчёте «Доход DS»
        // показывал commissionsAmountRUB (это как раз БЕЗ НДС), а «Без НДС» —
        // netRevenueRUB (это «остаток ДС», не доход) → оба столбца были неверны.
        // «Комиссия» = Σ комиссий цепочки; «Прибыль» = profitRUB (остаток ДС).
        $chain = $this->chainCommissionByTx($rows->pluck('id')->all());

        return $rows->map(fn ($r) => [
            $r->number, $r->providerName, $r->productName, $r->programName,
            $r->clientName, $r->date, $this->n($r->amountRUB),
            $this->n((float) ($r->amountRUB ?? 0) * (float) ($r->dsCommissionPercentage ?? 0) / 100),
            $this->n($r->commissionsAmountRUB),
            $this->n($r->personalVolume), $r->partnerName,
            $this->n($chain[$r->id] ?? 0), $this->n($r->profitRUB),
            $this->n($r->commissionAmountRubBeforeGapReduction),
            $this->n($r->profitRubBeforeGapReduction),
        ])->all();
    }
}
