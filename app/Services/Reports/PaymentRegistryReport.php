<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\DB;

/** Per spec ✅Отчеты §3.3 — реестр выплат для бухгалтерии. */
class PaymentRegistryReport extends AbstractReportType
{
    public function key(): string { return 'payment_registry'; }
    public function headers(): array
    {
        return ['ФИО', 'Активность',
            'Сальдо', 'Начислено', 'Прочее', 'Пул',
            'Итого начислено', 'Итого к оплате', 'Оплачено',
            'ИП', 'ОГРН', 'ИНН', 'Адрес', 'Верифицировано',
            'Р/с', 'К/с', 'БИК', 'Банк'];
    }

    public function rows(string $from, string $to, array $filters): array
    {
        $consultants = DB::table('consultant')
            ->whereNull('dateDeleted')
            ->get(['id', 'personName', 'activity']);
        $names = DB::table('directory_of_activities')->pluck('name', 'id');

        $accruedByCons = DB::table('commission')
            ->whereNull('deletedAt')
            ->whereBetween('date', [$from, $to])
            ->select('consultant', DB::raw('SUM(COALESCE("amountRUB", 0)) as accrued'))
            ->groupBy('consultant')->pluck('accrued', 'consultant');

        $otherByCons = DB::table('other_accruals')
            ->whereBetween('accrual_date', [$from, $to])
            ->select('consultant', DB::raw('SUM(COALESCE(amount, 0)) as other'))
            ->groupBy('consultant')->pluck('other', 'consultant');

        $poolByCons = DB::table('poolLog')
            ->whereBetween('date', [$from, $to])
            ->select('consultant', DB::raw('SUM(COALESCE("poolBonus", 0)) as pool'))
            ->groupBy('consultant')->pluck('pool', 'consultant');

        $paidByCons = DB::table('consultantPayment as cp')
            ->join('consultantBalance as cb', 'cb.id', '=', 'cp.consultantBalance')
            ->whereBetween('cp.paymentDate', [$from, $to])
            ->where('cp.status', 1)
            ->select('cb.consultant', DB::raw('SUM(COALESCE(cp.amount, 0)) as paid'))
            ->groupBy('cb.consultant')->pluck('paid', 'consultant');

        // Сальдо = входящий остаток с прошлых периодов (per spec ✅Отчет Реестр Выплат):
        // последний consultantBalance.balance до начала отчётного периода.
        $balanceFromMonth = \Carbon\Carbon::parse($from)->format('Y-m');
        $balanceByCons = DB::table('consultantBalance as cb1')
            ->whereRaw('cb1.id = (SELECT cb2.id FROM "consultantBalance" cb2
                WHERE cb2.consultant = cb1.consultant
                  AND cb2."dateMonth" < ?
                ORDER BY cb2."dateMonth" DESC LIMIT 1)', [$balanceFromMonth])
            ->select('cb1.consultant', 'cb1.remaining')
            ->pluck('remaining', 'consultant');

        // Реальные имена таблиц в legacy-схеме: `requisites` (юр) + `bankrequisites` (банк).
        // bankrequisites привязаны к requisites через requisites.id (FK), не к consultant напрямую.
        $reqs = DB::table('requisites')
            ->whereNull('deletedAt')
            ->select(['id', 'consultant', 'individualEntrepreneur', 'ogrn', 'inn', 'address', 'verified'])
            ->get()->keyBy('consultant');

        $bankByReq = DB::table('bankrequisites')
            ->select(['requisites', 'accountNumber', 'correspondentAccount', 'bankBik', 'bankName'])
            ->get()->keyBy('requisites');

        $rows = [];
        foreach ($consultants as $c) {
            $accrued = (float) ($accruedByCons[$c->id] ?? 0);
            $other = (float) ($otherByCons[$c->id] ?? 0);
            $pool = (float) ($poolByCons[$c->id] ?? 0);
            $paid = (float) ($paidByCons[$c->id] ?? 0);
            $balance = (float) ($balanceByCons[$c->id] ?? 0);
            if (! $accrued && ! $other && ! $pool && ! $paid && ! $balance) continue;

            $totalAccrued = $accrued + $other + $pool;
            $totalPayable = $balance + $totalAccrued;
            $r = $reqs[$c->id] ?? null;
            $b = $r ? ($bankByReq[$r->id] ?? null) : null;
            $rows[] = [
                $c->personName,
                $c->activity ? ($names[$c->activity] ?? '') : '',
                $this->n($balance), $this->n($accrued), $this->n($other), $this->n($pool),
                $this->n($totalAccrued), $this->n($totalPayable), $this->n($paid),
                $r?->individualEntrepreneur ?? '', $r?->ogrn ?? '',
                $r?->inn ?? '', $r?->address ?? '',
                $r?->verified ? 'true' : 'false',
                $b?->accountNumber ?? '', $b?->correspondentAccount ?? '',
                $b?->bankBik ?? '', $b?->bankName ?? '',
            ];
        }
        usort($rows, fn ($a, $b) => strcmp($a[0] ?? '', $b[0] ?? ''));
        return $rows;
    }
}
