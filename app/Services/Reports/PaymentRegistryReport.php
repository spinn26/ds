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

        $reqs = DB::table('partnerLegalRequisites')
            ->select(['consultant', 'individualEntrepreneur', 'ogrnip', 'inn', 'addressIp', 'verificationStatus'])
            ->get()->keyBy('consultant');

        $bank = DB::table('partnerBankRequisites')
            ->select(['consultant', 'rs', 'ks', 'bik', 'bankName'])
            ->get()->keyBy('consultant');

        $rows = [];
        foreach ($consultants as $c) {
            $accrued = (float) ($accruedByCons[$c->id] ?? 0);
            $other = (float) ($otherByCons[$c->id] ?? 0);
            $pool = (float) ($poolByCons[$c->id] ?? 0);
            $paid = (float) ($paidByCons[$c->id] ?? 0);
            if (! $accrued && ! $other && ! $pool && ! $paid) continue;

            $totalAccrued = $accrued + $other + $pool;
            $r = $reqs[$c->id] ?? null;
            $b = $bank[$c->id] ?? null;
            $rows[] = [
                $c->personName,
                $c->activity ? ($names[$c->activity] ?? '') : '',
                0, $this->n($accrued), $this->n($other), $this->n($pool),
                $this->n($totalAccrued), $this->n($totalAccrued), $this->n($paid),
                $r?->individualEntrepreneur ?? '', $r?->ogrnip ?? '',
                $r?->inn ?? '', $r?->addressIp ?? '',
                $r?->verificationStatus === 'verified' ? 'true' : 'false',
                $b?->rs ?? '', $b?->ks ?? '', $b?->bik ?? '', $b?->bankName ?? '',
            ];
        }
        usort($rows, fn ($a, $b) => strcmp($a[0] ?? '', $b[0] ?? ''));
        return $rows;
    }
}
