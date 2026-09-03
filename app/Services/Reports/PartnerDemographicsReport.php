<?php

namespace App\Services\Reports;

use App\Services\PartnerDemographicsService;
use App\Support\Gender;

/**
 * Демография сети — СПИСОК партнёров: по строке на человека.
 *
 * Нужен, чтобы посчитать срез, которого нет в сводке: демография по статусу,
 * по наставнику, по году регистрации. Сама аналитика — проценты и
 * распределение — во втором отчёте, PartnerDemographicsSummaryReport.
 *
 * ⚠ Период не участвует в выборке: демография — снимок всей сети на сегодня,
 * а не события за интервал. Границы всё равно приходят (в запросе и в
 * report_archive они NOT NULL, UI шлёт текущую дату) и игнорируются.
 */
class PartnerDemographicsReport extends AbstractReportType
{
    public function __construct(private readonly PartnerDemographicsService $data) {}

    public function key(): string { return 'partner_demographics'; }

    public function headers(): array
    {
        return [
            'Партнёр', 'Статус', 'Пол', 'Источник пола',
            'Дата рождения', 'Возраст', 'Возрастная группа',
            'Зарегистрирован', 'Email',
        ];
    }

    public function rows(string $from, string $to, array $filters): array
    {
        return array_map(fn (array $r) => [
            $r['personName'],
            $r['activityName'],
            Gender::label($r['gender']),
            $r['genderSource'],
            $r['birthDate'] ?? '',
            $r['years'] ?? '',
            $r['bucket'],
            $r['dateCreated'] ?? '',
            $r['email'] ?? '',
        ], $this->data->records($filters));
    }
}
