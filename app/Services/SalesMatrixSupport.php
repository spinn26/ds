<?php

namespace App\Services;

/**
 * Общие помощники матриц продаж — резолв поставщика, курс, границы месяцев.
 *
 * Эти четыре метода жили двумя копиями: в матрице по продуктам и в матрице по
 * партнёрам. Копии были написаны по-разному (разные циклы, разная расстановка
 * пробелов внутри SQL), но давали одно и то же — что и зафиксировано в
 * SalesMatrixSupportTest перед сведением. Взята версия из матрицы по
 * продуктам, вторая удалена.
 *
 * ⚠ Правки здесь задевают ОБЕ матрицы сразу — в этом и смысл, но сверяться
 * теперь надо с сеткой обеих.
 */
class SalesMatrixSupport
{
    /**
     * Поставщик строки: у Insmart-продуктов это всегда «Insmart», у прочих —
     * провайдер программы. Прочерк — чтобы строки без провайдера не
     * схлопывались в NULL и не выпадали из группировки.
     */
    public function resolvedSupplierSql(): string
    {
        return "CASE WHEN (SELECT pr.name FROM product pr WHERE pr.id = co.product) ~* 'ins+mart'"
            . " THEN 'Insmart' ELSE COALESCE(pg.\"providerName\", '—') END";
    }

    /**
     * Управленческий курс контракта на месяц указанной даты.
     *
     * Лесенка фолбэков значима: точный месяц → ближайший более ранний курс →
     * единица. Схлопнуть её нельзя — валютные контракты посчитаются по курсу 1.
     */
    public function rateExpr(string $dateCol): string
    {
        $month = 'DATE_TRUNC(\'month\', co."'.$dateCol.'"::date)::date';

        return '(COALESCE('
            .'(SELECT m.rate FROM management_currency_rate m WHERE m.currency = co.currency AND m.date <= '.$month.' ORDER BY m.date DESC LIMIT 1),'
            .'(SELECT m.rate FROM management_currency_rate m WHERE m.currency = co.currency ORDER BY m.date ASC LIMIT 1),'
            .'1))';
    }

    /** Первое число месяца, СЛЕДУЮЩЕГО за указанным — правая граница периода. */
    public function monthExclusiveStart(string $ym): string
    {
        [$y, $m] = explode('-', $ym);
        $m = (int) $m + 1;
        if ($m > 12) { $m = 1; $y = (int) $y + 1; }

        return sprintf('%04d-%02d-01', (int) $y, $m);
    }

    /**
     * Список месяцев периода включительно с обеих сторон.
     *
     * @return list<string> вида 2026-03
     */
    public function monthRange(string $from, string $to): array
    {
        $months = [];
        $cur    = $from;
        while ($cur <= $to) {
            $months[] = $cur;
            [$y, $m]  = explode('-', $cur);
            $m = (int) $m + 1;
            if ($m > 12) { $m = 1; $y = (int) $y + 1; }
            $cur = sprintf('%04d-%02d', (int) $y, $m);
        }

        return $months;
    }
}
