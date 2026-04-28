<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Расчёт «ГП (баллы)» и «Моё вознаграждение» для контрактов команды
 * (per spec ✅Контракты моей команды §3).
 *
 * Сценарий А (Активирован) — фактические данные из commission/transaction.
 * Сценарий Б (Сбор документов / Комплаенс / любой другой не-активирован) —
 *   прогноз: amount × dsPercent / 105 / 100 × (viewerPercent − sellerPercent).
 *
 * Возвращает map[contractId] => {gp, commission, isActual}.
 */
class ContractForecastService
{
    /**
     * @param Collection<int,object> $contracts  contract rows (id, status, ammount, product, consultant, currency)
     * @param int $viewerConsultantId            наставник, для которого считаем
     * @return array<int,array{gp:float, commission:float, isActual:bool}>
     */
    public function forecastForContracts(Collection $contracts, int $viewerConsultantId): array
    {
        if ($contracts->isEmpty()) return [];

        $contractIds = $contracts->pluck('id')->all();

        // Активные контракты → берём фактические commission текущего viewer'а.
        $actuals = DB::table('commission as cm')
            ->join('transaction as t', 't.id', '=', 'cm.transaction')
            ->whereIn('t.contract', $contractIds)
            ->where('cm.consultant', $viewerConsultantId)
            ->whereNull('cm.deletedAt')
            ->whereNull('t.deletedAt')
            ->select(
                't.contract as contractId',
                DB::raw('SUM(COALESCE(cm."groupVolume", 0)) as gp'),
                // legacy commission rows often have amountRUB=NULL but amount filled
                // (assumed to be RUB since контракт.валюта default RUB).
                DB::raw('SUM(COALESCE(cm."amountRUB", cm.amount, 0)) as commission')
            )
            ->groupBy('t.contract')
            ->get()
            ->keyBy('contractId');

        // Для прогноза — текущая квалификация viewer'а
        $viewer = DB::table('consultant')->where('id', $viewerConsultantId)->first();
        $viewerLevel = $viewer?->status_and_lvl
            ? DB::table('status_levels')->where('id', $viewer->status_and_lvl)->first()
            : null;
        $viewerPercent = (float) ($viewerLevel->percent ?? 15);

        // Sellers' levels (продавец = consultant контракта)
        $sellerIds = $contracts->pluck('consultant')->filter()->unique()->all();
        $sellerLevels = DB::table('consultant as c')
            ->leftJoin('status_levels as sl', 'sl.id', '=', 'c.status_and_lvl')
            ->whereIn('c.id', $sellerIds)
            ->get(['c.id', 'sl.percent'])
            ->keyBy('id');

        // dsCommission per продукт (берём первую активную)
        $productIds = $contracts->pluck('product')->filter()->unique()->all();
        $dsByProduct = $productIds
            ? DB::table('dsCommission')
                ->whereIn('product', $productIds)
                ->where('active', true)
                ->whereNull('dateDeleted')
                ->orderByDesc('comission')
                ->get(['product', 'comission'])
                ->groupBy('product')
                ->map(fn ($g) => (float) ($g[0]->comission ?? 0))
            : collect();

        // Currency rate (если не RUB)
        $currencyIds = $contracts->pluck('currency')->filter()->unique();
        $rates = $currencyIds->isNotEmpty()
            ? DB::table('currencyRate')
                ->whereIn('currency', $currencyIds)
                ->orderByDesc('date')
                ->get()
                ->groupBy('currency')
                ->map(fn ($g) => (float) ($g[0]->rate ?? 1))
            : collect();

        $result = [];
        foreach ($contracts as $c) {
            // Сценарий А: активирован и есть фактические комиссии
            if (isset($actuals[$c->id])) {
                $result[$c->id] = [
                    'gp' => round((float) $actuals[$c->id]->gp, 2),
                    'commission' => round((float) $actuals[$c->id]->commission, 2),
                    'isActual' => true,
                ];
                continue;
            }

            // Сценарий Б: прогноз
            $sellerPercent = (float) ($sellerLevels[$c->consultant]?->percent ?? 15);
            $marginPercent = max(0, $viewerPercent - $sellerPercent);
            $dsPercent = (float) ($dsByProduct[$c->product] ?? 0);

            if ($marginPercent <= 0 || $dsPercent <= 0) {
                $result[$c->id] = ['gp' => 0, 'commission' => 0, 'isActual' => false];
                continue;
            }

            $rate = $c->currency && $c->currency != 67
                ? (float) ($rates[$c->currency] ?? 1)
                : 1.0;
            $amountRub = (float) ($c->ammount ?? 0) * $rate;
            // Per spec ✅Калькулятор объёмов §2 шаг 2-3:
            // Выручка ДС без НДС = (база * %DS) / 105 * 100
            // ЛП = Выручка / 100 → база контракта в баллах
            $netRevenue = $amountRub * $dsPercent / 105;  // (×100/100)
            $points = $netRevenue / 100;
            $commission = round($points * $marginPercent, 2);  // points × margin = rub (1 балл = 100 руб)

            $result[$c->id] = [
                'gp' => round($points, 2),
                'commission' => $commission,
                'isActual' => false,
            ];
        }

        return $result;
    }
}
