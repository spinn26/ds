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

    /**
     * Управленческий курс ТРАНЗАКЦИИ на месяц отчёта.
     *
     * Отличается от rateExpr() двумя вещами, и обе существенные:
     *   • валюта берётся у транзакции (t.currency), а не у контракта. Они
     *     совпадают не всегда: за III квартал 2026 из 3 724 транзакций у 56
     *     валюта отличалась от валюты контракта — например, долларовый платёж
     *     по контракту в евро;
     *   • месяц — тот, в который транзакция попала в отчёт (t."dateMonth"),
     *     потому что «Факт» раскладывается именно по нему.
     *
     * Зачем вообще пересчитывать. В транзакции лежит собственный курс
     * (t."currencyRate"), по которому посчитан amountRUB — это курс на момент
     * проведения платежа. Отчёт же должен считать по управленческому курсу
     * месяца, том самом, что заводится в разделе «Курсы валют для отчётов»:
     * иначе «Факт» живёт по одному курсу, а «В работе» и «Активировано» рядом —
     * по другому, и суммы вкладок не сходятся между собой.
     *
     * Лесенка фолбэков та же, что в rateExpr: точный месяц → ближайший более
     * ранний курс → единица. Схлопывать нельзя — валютные суммы посчитаются
     * по курсу 1.
     */
    public function transactionRateExpr(): string
    {
        $month = '((t."dateMonth" || \'-01\')::date)';

        return '(COALESCE('
            .'(SELECT m.rate FROM management_currency_rate m WHERE m.currency = t.currency AND m.date <= '.$month.' ORDER BY m.date DESC LIMIT 1),'
            .'(SELECT m.rate FROM management_currency_rate m WHERE m.currency = t.currency ORDER BY m.date ASC LIMIT 1),'
            .'1))';
    }

    /**
     * Объём транзакции в рублях по управленческому курсу.
     *
     * Источник — сумма в валюте (t.amount). Проверено на проде 23.09.2026:
     * amountRUB = amount × currencyRate с точностью до копейки на всех 3 724
     * транзакциях квартала, то есть amount действительно хранит валютную сумму.
     */
    public function transactionVolumeExpr(): string
    {
        return 'COALESCE(t.amount, 0) * '.$this->transactionRateExpr();
    }

    /**
     * Выручка транзакции в рублях по управленческому курсу.
     *
     * ⚠ Обходным путём: колонка commissionsAmount (выручка в валюте) не
     * заполнена НИ У ОДНОЙ транзакции — только commissionsAmountRUB. Поэтому
     * валютную сумму восстанавливаем делением на курс самой транзакции, а
     * затем переводим по управленческому. Деление точное: связь
     * amountRUB = amount × currencyRate выполняется без расхождений.
     *
     * NULLIF на случай нулевого курса: сейчас таких строк нет, но деление на
     * ноль уронило бы весь отчёт, а не одну ячейку.
     */
    public function transactionRevenueExpr(): string
    {
        return 'COALESCE(t."commissionsAmountRUB", 0) / NULLIF(COALESCE(t."currencyRate", 1), 0) * '.$this->transactionRateExpr();
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
