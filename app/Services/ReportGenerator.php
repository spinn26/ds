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
     * Создать запись в архиве со статусом «generating» и вернуть ID.
     * Дальше контроллер диспатчит GenerateReportJob который вызовет
     * generateAsArchived($id) в воркере очереди.
     *
     * Per spec ✅Отчеты.md §2.1: «Администратор не должен ждать
     * загрузки страницы — он нажимает кнопку, отчет появляется в
     * нижней таблице со статусом Генерируем».
     */
    public function reserveArchive(string $type, string $dateFrom, string $dateTo, array $filters = [], ?int $userId = null): int
    {
        return DB::table('report_archive')->insertGetId([
            'type' => $type,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'filters' => json_encode($filters),
            'status' => 'generating',
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Выполнить генерацию для уже зарезервированной записи в архиве.
     * Вызывается из GenerateReportJob.
     */
    public function generateAsArchived(int $id): void
    {
        $row = DB::table('report_archive')->where('id', $id)->first();
        if (! $row) throw new \RuntimeException("Архив #{$id} не найден");

        $filters = json_decode($row->filters ?: '{}', true);
        $rows = $this->fetchRows($row->type, $row->date_from, $row->date_to, $filters);
        $headers = $this->headersFor($row->type);
        $path = "reports/{$id}.csv";
        $csv = $this->toCsv($headers, $rows);
        Storage::disk('local')->put($path, $csv);

        DB::table('report_archive')->where('id', $id)->update([
            'status' => 'ready',
            'file_path' => $path,
            'updated_at' => now(),
        ]);
    }

    /**
     * Синхронная генерация (для тестов / небольших отчётов).
     * Боевой путь — через reserveArchive() + GenerateReportJob.
     */
    public function generate(string $type, string $dateFrom, string $dateTo, array $filters = [], ?int $userId = null): int
    {
        $id = $this->reserveArchive($type, $dateFrom, $dateTo, $filters, $userId);
        try {
            $this->generateAsArchived($id);
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

    /**
     * Per spec ✅Отчеты §3.4: «База: Партнёр, Активность.
     * Прошлый период: Кв., ЛП, ГП, НГП. Выбранный период: Кв., ЛП, ГП, НГП».
     * Берём date_from..date_to как «выбранный период», дату сразу перед
     * date_from считаем «прошлым» (1 месяц назад).
     */
    private function qualificationsReport(string $from, string $to): array
    {
        $prevFrom = (new \DateTime($from))->modify('-1 month')->format('Y-m-01');
        $prevTo = (new \DateTime($prevFrom))->modify('last day of this month')->format('Y-m-d');

        $logs = DB::table('qualificationLog')
            ->whereNull('dateDeleted')
            ->where(function ($w) use ($from, $to, $prevFrom, $prevTo) {
                $w->whereBetween('date', [$from, $to])
                  ->orWhereBetween('date', [$prevFrom, $prevTo]);
            })
            ->limit(60000)
            ->get();

        $consultantIds = $logs->pluck('consultant')->filter()->unique()->all();
        $consultants = DB::table('consultant')->whereIn('id', $consultantIds)
            ->get(['id', 'personName', 'activity'])->keyBy('id');
        $activityNames = DB::table('directory_of_activities')->pluck('name', 'id');
        $levels = DB::table('status_levels')->get()->keyBy('id');

        $resolveLevel = function ($nominalId, $calcId) use ($levels) {
            $a = $nominalId ? ($levels[$nominalId] ?? null) : null;
            $b = $calcId ? ($levels[$calcId] ?? null) : null;
            if (! $a && ! $b) return null;
            if (! $a) return $b;
            if (! $b) return $a;
            return $a->level >= $b->level ? $a : $b;
        };

        $byConsultant = [];
        foreach ($logs as $l) {
            $isCurrent = $l->date >= $from && $l->date <= $to;
            $bucket = $isCurrent ? 'current' : 'previous';
            $level = $resolveLevel($l->nominalLevel, $l->calculationLevel);
            $byConsultant[$l->consultant][$bucket] = [
                'levelTitle' => $level?->title,
                'lp' => round((float) $l->personalVolume, 2),
                'gp' => round((float) $l->groupVolume, 2),
                'ngp' => round((float) $l->groupVolumeCumulative, 2),
            ];
        }

        $rows = [];
        foreach ($byConsultant as $cid => $data) {
            $cons = $consultants[$cid] ?? null;
            if (! $cons) continue;
            $prev = $data['previous'] ?? ['levelTitle' => '', 'lp' => 0, 'gp' => 0, 'ngp' => 0];
            $cur = $data['current'] ?? ['levelTitle' => '', 'lp' => 0, 'gp' => 0, 'ngp' => 0];
            $rows[] = [
                $cons->personName,
                $cons->activity ? ($activityNames[$cons->activity] ?? '') : '',
                $prev['levelTitle'], $prev['lp'], $prev['gp'], $prev['ngp'],
                $cur['levelTitle'], $cur['lp'], $cur['gp'], $cur['ngp'],
            ];
        }
        usort($rows, fn ($a, $b) => strcmp($a[0] ?? '', $b[0] ?? ''));
        return $rows;
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

    /**
     * Per spec ✅Отчеты §3.3 — финансовый срез для бухгалтерии:
     * ФИО / Активность / Сальдо / Начислено / Прочее / Пул / Итого
     * начислено / Итого к оплате / Оплачено / Реквизиты / Банк.
     *
     * Аггрегируем по партнёрам за период.
     */
    private function paymentRegistry(string $from, string $to): array
    {
        $consultants = DB::table('consultant')
            ->whereNull('dateDeleted')
            ->get(['id', 'personName', 'activity']);
        $activityNames = DB::table('directory_of_activities')->pluck('name', 'id');

        // Агрегаты по commission за период
        $accruedByCons = DB::table('commission')
            ->whereNull('deletedAt')
            ->whereBetween('date', [$from, $to])
            ->select('consultant', DB::raw('SUM(COALESCE("amountRUB", 0)) as accrued'))
            ->groupBy('consultant')
            ->pluck('accrued', 'consultant');

        // other_accruals
        $otherByCons = DB::table('other_accruals')
            ->whereBetween('accrual_date', [$from, $to])
            ->select('consultant', DB::raw('SUM(COALESCE(amount, 0)) as other'))
            ->groupBy('consultant')
            ->pluck('other', 'consultant');

        // poolLog
        $poolByCons = DB::table('poolLog')
            ->whereBetween('date', [$from, $to])
            ->select('consultant', DB::raw('SUM(COALESCE("poolBonus", 0)) as pool'))
            ->groupBy('consultant')
            ->pluck('pool', 'consultant');

        // payments via consultantBalance/consultantPayment
        $paidByCons = DB::table('consultantPayment as cp')
            ->join('consultantBalance as cb', 'cb.id', '=', 'cp.consultantBalance')
            ->whereBetween('cp.paymentDate', [$from, $to])
            ->where('cp.status', 1) // 1=Оплачено в legacy enum
            ->select('cb.consultant', DB::raw('SUM(COALESCE(cp.amount, 0)) as paid'))
            ->groupBy('cb.consultant')
            ->pluck('paid', 'consultant');

        // Реквизиты: partnerLegalRequisites / partnerBankRequisites (упрощённо)
        $requisitesByCons = DB::table('partnerLegalRequisites')
            ->select(['consultant', 'individualEntrepreneur', 'ogrnip', 'inn', 'addressIp', 'verificationStatus'])
            ->get()
            ->keyBy('consultant');

        $bankByCons = DB::table('partnerBankRequisites')
            ->select(['consultant', 'rs', 'ks', 'bik', 'bankName'])
            ->get()
            ->keyBy('consultant');

        $rows = [];
        foreach ($consultants as $c) {
            $accrued = (float) ($accruedByCons[$c->id] ?? 0);
            $other = (float) ($otherByCons[$c->id] ?? 0);
            $pool = (float) ($poolByCons[$c->id] ?? 0);
            $paid = (float) ($paidByCons[$c->id] ?? 0);
            $totalAccrued = $accrued + $other + $pool;
            // Сальдо = 0 в этом V1 (нужна логика consultantBalance)
            $totalToPay = $totalAccrued;

            $req = $requisitesByCons[$c->id] ?? null;
            $bank = $bankByCons[$c->id] ?? null;

            // Пропускаем партнёров без активности по всем колонкам
            if (! $accrued && ! $other && ! $pool && ! $paid) continue;

            $rows[] = [
                $c->personName,
                $c->activity ? ($activityNames[$c->activity] ?? '') : '',
                0, // Сальдо
                round($accrued, 2),
                round($other, 2),
                round($pool, 2),
                round($totalAccrued, 2),
                round($totalToPay, 2),
                round($paid, 2),
                $req?->individualEntrepreneur ?? '',
                $req?->ogrnip ?? '',
                $req?->inn ?? '',
                $req?->addressIp ?? '',
                $req?->verificationStatus === 'verified' ? 'true' : 'false',
                $bank?->rs ?? '',
                $bank?->ks ?? '',
                $bank?->bik ?? '',
                $bank?->bankName ?? '',
            ];
        }
        usort($rows, fn ($a, $b) => strcmp($a[0] ?? '', $b[0] ?? ''));
        return $rows;
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
