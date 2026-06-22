<?php

namespace App\Services\Reports;

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
        // фолбэк на Directual-контакт (consultant.person → person.email) — у
        // legacy/терминированных webUser часто пуст.
        $webIds = $rows->pluck('webUser')->filter()->unique();
        $emailByWeb = $webIds->isNotEmpty()
            ? DB::table('WebUser')->whereIn('id', $webIds)->pluck('email', 'id')
            : collect();
        $personIds = $rows->pluck('person')->filter()->unique();
        $emailByPerson = $personIds->isNotEmpty()
            ? DB::table('person')->whereIn('id', $personIds)->pluck('email', 'id')
            : collect();

        return $rows->map(fn ($c) => [
            $c->personName,
            (($c->webUser ? ($emailByWeb[$c->webUser] ?? null) : null)
                ?: ($c->person ? ($emailByPerson[$c->person] ?? null) : null)) ?: '',
            $c->activity ? ($names[$c->activity] ?? '') : '',
            $c->dateActivity ?: $c->dateDeterministic ?: '',
            $c->dateDeterministicPlan ?: '',
        ])->all();
    }
}
