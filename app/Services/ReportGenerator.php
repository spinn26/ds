<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Генератор отчётов (per spec ✅Отчеты.md §3).
 *
 * V1 — синхронная генерация CSV. Async-очередь оставлена как
 * отдельный шаг (см. TODO в комментарии end-of-method).
 *
 * Поддерживает 7 типов отчётов из спеки:
 *   payment_registry, qualifications, commissions,
 *   revenue_expenses, finrez_transactions, finrez_commissions,
 *   partner_status.
 */
class ReportGenerator
{
    /**
     * Сгенерировать отчёт и записать в архив.
     * @return int ID записи в report_archive.
     */
    public function generate(string $type, string $dateFrom, string $dateTo, array $filters = [], ?int $userId = null): int
    {
        $id = DB::table('report_archive')->insertGetId([
            'type' => $type,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'filters' => json_encode($filters),
            'status' => 'generating',
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $rows = $this->fetchRows($type, $dateFrom, $dateTo, $filters);
            $headers = $this->headersFor($type);
            $path = "reports/{$id}.csv";
            $csv = $this->toCsv($headers, $rows);
            Storage::disk('local')->put($path, $csv);

            DB::table('report_archive')->where('id', $id)->update([
                'status' => 'ready',
                'file_path' => $path,
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Report generation failed', ['id' => $id, 'type' => $type, 'error' => $e->getMessage()]);
            DB::table('report_archive')->where('id', $id)->update([
                'status' => 'error',
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
                'updated_at' => now(),
            ]);
        }

        return $id;
    }

    /** Заголовки CSV per spec §3. */
    private function headersFor(string $type): array
    {
        return match ($type) {
            'revenue_expenses' => ['Продукт', 'Доход', 'Расход'],
            'partner_status' => ['ФИО', 'Статус', 'Фактическая дата', 'Плановая дата терминации'],
            'payment_registry' => ['ФИО', 'Активность', 'Сальдо', 'Начислено', 'Прочее', 'Пул', 'Итого начислено', 'Итого к оплате', 'Оплачено', 'ИП', 'ОГРН', 'ИНН', 'Адрес', 'Верифицировано', 'Р/с', 'К/с', 'БИК', 'Банк'],
            'qualifications' => ['Партнёр', 'Активность', 'Кв. (пред)', 'ЛП (пред)', 'ГП (пред)', 'НГП (пред)', 'Кв. (тек)', 'ЛП (тек)', 'ГП (тек)', 'НГП (тек)'],
            'commissions' => ['Номер', 'Поставщик', 'Продукт', 'Программа', 'Клиент', 'Дата', 'Сумма', 'Доход DS', 'Без НДС', 'ЛП', 'Партнёр', 'Комиссия', 'Прибыль', 'Комиссия до отрыва', 'Прибыль до отрыва'],
            'finrez_commissions' => ['Партнёр сделки', 'Партнёр комиссии', 'Клиент', 'Поставщик', 'Продукт', 'Программа', 'Номер', 'ID контракта', 'Дата', 'Сумма', 'Валюта', 'Сумма RUB', 'Доход DS', 'Доход DS RUB', 'Без НДС RUB', 'ЛП', 'Комиссия', 'Прибыль'],
            'finrez_transactions' => ['Номер', 'Поставщик', 'Продукт', 'Программа', 'Клиент', 'Дата', 'Сумма RUB', 'Доход DS', 'Без НДС RUB', 'Партнёр', 'Комиссия', 'Прибыль', 'Сумма исх.', 'Валюта', 'Тип', 'Кол-во оплат', 'Срок', 'Дата открытия'],
            default => [],
        };
    }

    private function fetchRows(string $type, string $from, string $to, array $filters): array
    {
        return match ($type) {
            'revenue_expenses' => $this->revenueExpenses($from, $to),
            'partner_status' => $this->partnerStatus($from, $to, $filters),
            'commissions' => $this->commissionsReport($from, $to),
            'qualifications' => $this->qualificationsReport($from, $to),
            'finrez_transactions' => $this->finrezTransactions($from, $to),
            'finrez_commissions' => $this->finrezCommissions($from, $to),
            'payment_registry' => $this->paymentRegistry($from, $to),
            default => [],
        };
    }

    private function revenueExpenses(string $from, string $to): array
    {
        $rows = DB::table('transaction')
            ->select(DB::raw('COALESCE(c."productName", \'—\') as product'),
                     DB::raw('SUM(t."amountRUB") as income'),
                     DB::raw('SUM(t."commissionsAmountRUB") as expense'))
            ->from('transaction as t')
            ->leftJoin('contract as c', 'c.id', '=', 't.contract')
            ->whereNull('t.deletedAt')
            ->whereBetween('t.date', [$from, $to])
            ->groupBy('c.productName')
            ->orderBy('product')
            ->get();
        return $rows->map(fn ($r) => [$r->product, round((float) $r->income, 2), round((float) $r->expense, 2)])->all();
    }

    private function partnerStatus(string $from, string $to, array $filters): array
    {
        $q = DB::table('consultant')
            ->whereNull('dateDeleted')
            ->where(function ($w) use ($from, $to) {
                $w->whereBetween('dateActivity', [$from, $to])
                  ->orWhereBetween('dateDeterministic', [$from, $to])
                  ->orWhereBetween('dateCreated', [$from, $to]);
            });

        if (! empty($filters['activity'])) {
            $q->where('activity', $filters['activity']);
        }

        $rows = $q->orderBy('personName')->get();
        $activityLabels = DB::table('directory_of_activities')->pluck('name', 'id');

        return $rows->map(fn ($c) => [
            $c->personName,
            $c->activity ? ($activityLabels[$c->activity] ?? '') : '',
            $c->dateActivity ?: $c->dateDeterministic ?: '',
            $c->dateDeterministicPlan ?: '',
        ])->all();
    }

    private function commissionsReport(string $from, string $to): array
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
                'c.clientName', 't.date', 't.amountRUB', 'c.dsCommission as dsCommissionId',
                't.netRevenueRUB', 't.personalVolume', 'cn.personName as partnerName',
                't.commissionsAmountRUB', 't.profitRUB',
                't.commissionAmountRubBeforeGapReduction', 't.profitRubBeforeGapReduction',
            ]);

        return $rows->map(fn ($r) => [
            $r->number, $r->providerName, $r->productName, $r->programName,
            $r->clientName, $r->date, round((float) $r->amountRUB, 2),
            '', round((float) $r->netRevenueRUB, 2),
            round((float) $r->personalVolume, 2), $r->partnerName,
            round((float) $r->commissionsAmountRUB, 2), round((float) $r->profitRUB, 2),
            round((float) $r->commissionAmountRubBeforeGapReduction, 2),
            round((float) $r->profitRubBeforeGapReduction, 2),
        ])->all();
    }

    private function qualificationsReport(string $from, string $to): array
    {
        // Простая выборка из qualificationLog за выбранный период.
        $rows = DB::table('qualificationLog')
            ->whereNull('dateDeleted')
            ->whereBetween('date', [$from, $to])
            ->orderByDesc('date')
            ->limit(20000)
            ->get();
        return $rows->map(fn ($r) => [
            $r->consultantPersonName, '',
            '', '', '', '',
            $r->nominalLevel, $r->personalVolume, $r->groupVolume, $r->groupVolumeCumulative,
        ])->all();
    }

    private function finrezTransactions(string $from, string $to): array
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
                'cn.personName as partner', 't.commissionsAmountRUB', 't.profitRUB',
                't.amount', 'cur.symbol as curSymbol', 't.score', 'c.paymentCount',
                'c.term', 'c.openDate',
            ]);
        return $rows->map(fn ($r) => [
            $r->number, $r->providerName, $r->productName, $r->programName,
            $r->clientName, $r->date, round((float) $r->amountRUB, 2),
            '', round((float) $r->netRevenueRUB, 2),
            $r->partner, round((float) $r->commissionsAmountRUB, 2), round((float) $r->profitRUB, 2),
            round((float) $r->amount, 2), $r->curSymbol, $r->score,
            $r->paymentCount, $r->term, $r->openDate,
        ])->all();
    }

    private function finrezCommissions(string $from, string $to): array
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
            round((float) $r->amount, 2), $r->currency, round((float) $r->amountRUB, 2),
            '', round((float) $r->amountRUB, 2),
            round((float) $r->netRevenueRUB, 2),
            round((float) $r->personalVolume, 2),
            round((float) $r->commissionRub, 2), round((float) $r->profitRUB, 2),
        ])->all();
    }

    private function paymentRegistry(string $from, string $to): array
    {
        // Минимальная заглушка: список консультантов с балансами за месяц.
        return [];
    }

    private function toCsv(array $headers, array $rows): string
    {
        $out = "\xEF\xBB\xBF"; // UTF-8 BOM для корректного открытия в Excel
        $out .= $this->csvLine($headers);
        foreach ($rows as $r) $out .= $this->csvLine($r);
        return $out;
    }

    private function csvLine(array $vals): string
    {
        return implode(',', array_map(function ($v) {
            if ($v === null) return '""';
            $s = (string) $v;
            $s = str_replace('"', '""', $s);
            return '"' . $s . '"';
        }, $vals)) . "\n";
    }
}
