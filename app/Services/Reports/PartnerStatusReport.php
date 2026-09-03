<?php

namespace App\Services\Reports;

use App\Support\TerminationDeadline;
use Illuminate\Support\Facades\DB;

/** Per spec ✅Отчеты §3.2 — кадровые изменения и терминация. */
class PartnerStatusReport extends AbstractReportType
{
    public function key(): string { return 'partner_status'; }
    public function headers(): array
    {
        return ['ФИО', 'Email', 'Статус', 'Фактическая дата', 'Плановая дата терминации'];
    }

    public function rows(string $from, string $to, array $filters): array
    {
        $q = DB::table('consultant')
            ->whereNull('dateDeleted')
            ->where(function ($w) use ($from, $to) {
                $w->whereBetween('dateActivity', [$from, $to])
                  ->orWhereBetween('dateDeterministic', [$from, $to])
                  ->orWhereBetween('dateCreated', [$from, $to]);
            });

        if (! empty($filters['activity'])) $q->where('activity', $filters['activity']);

        $rows = $q->orderBy('personName')->get();
        $names = DB::table('directory_of_activities')->pluck('name', 'id');

        // Email: основной источник WebUser (consultant.webUser → WebUser.email),
        // фолбэк — собственная колонка партнёра (перенесена из person
        // 13.08.2026): у legacy/терминированных логина часто нет.
        $webIds = $rows->pluck('webUser')->filter()->unique();
        $emailByWeb = $webIds->isNotEmpty()
            ? DB::table('WebUser')->whereIn('id', $webIds)->pluck('email', 'id')
            : collect();

        return $rows->map(fn ($c) => [
            $c->personName,
            (($c->webUser ? ($emailByWeb[$c->webUser] ?? null) : null)
                ?: ($c->email ?: null)) ?: '',
            $c->activity ? ($names[$c->activity] ?? '') : '',
            $c->dateActivity ?: $c->dateDeterministic ?: '',
            // Плановая дата — тем же правилом, что в разделе «Статусы
            // партнёров» (App\Support\TerminationDeadline). Раньше печаталась
            // колонка dateDeterministicPlan — это окно активации из
            // самоактивации, а не годовой дедлайн, и отчёт расходился с UI.
            TerminationDeadline::resolve(
                $c->activity, $c->yearPeriodEnd ?? null, $c->dateActivity
            ) ?: '',
        ])->all();
    }
}
