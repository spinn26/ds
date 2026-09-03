<?php

namespace App\Services\Reports;

use App\Support\Age;
use App\Support\Gender;
use Illuminate\Support\Facades\DB;

/**
 * Демография партнёрской сети: пол и возраст по каждому партнёру.
 *
 * Выгрузка сделана списком, а не сводкой, намеренно: проценты и разбивку по
 * возрастным группам в Excel собирают сводной таблицей за минуту, а вот
 * обратно из готовых процентов данные не достанешь. Колонки «Пол» и
 * «Возрастная группа» уже нормализованы — по ним сводная строится сразу.
 *
 * Период фильтрует ДАТУ РЕГИСТРАЦИИ партнёра (consultant.dateCreated): чтобы
 * получить всю сеть, берётся заведомо широкий диапазон. Соответствующая
 * подпись есть в колонке «Зарегистрирован».
 *
 * Откуда берутся данные:
 *   - пол — WebUser.gender, а если он пуст (или логина нет вовсе) —
 *     определяется по отчеству, см. App\Support\Gender. В колонке
 *     «Источник пола» видно, что именно произошло: значение из профиля
 *     нельзя путать с вычисленным;
 *   - дата рождения — WebUser.birthDate, фоллбэк на consultant.birthDate
 *     (у партнёров без логина она только там, и там же varchar).
 */
class PartnerDemographicsReport extends AbstractReportType
{
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
        $query = DB::table('consultant')
            ->whereNull('dateDeleted')
            ->whereBetween('dateCreated', [$from, $to]);

        if (! empty($filters['activity'])) {
            $query->where('activity', $filters['activity']);
        }

        $partners = $query
            ->orderBy('personName')
            ->get(['id', 'personName', 'activity', 'webUser', 'birthDate', 'dateCreated', 'email']);

        $webUserIds = $partners->pluck('webUser')->filter()->unique();
        $users = $webUserIds->isNotEmpty()
            ? DB::table('WebUser')->whereIn('id', $webUserIds)
                ->get(['id', 'gender', 'birthDate', 'patronymic', 'email'])->keyBy('id')
            : collect();

        $activityNames = DB::table('directory_of_activities')->pluck('name', 'id');

        $rows = [];
        foreach ($partners as $c) {
            $user = $c->webUser ? ($users[$c->webUser] ?? null) : null;

            $stored = $user->gender ?? null;
            $gender = Gender::resolve($stored, $user->patronymic ?? null, $c->personName);
            $source = match (true) {
                Gender::normalize($stored) !== null => 'Профиль',
                $gender !== null => 'По отчеству',
                default => 'Нет данных',
            };

            $birthDate = $user->birthDate ?? $c->birthDate;
            $years = Age::years($birthDate);

            $rows[] = [
                $c->personName,
                $c->activity ? ($activityNames[$c->activity] ?? '') : '',
                Gender::label($gender),
                $source,
                Age::date($birthDate) ?? '',
                $years ?? '',
                Age::bucket($years),
                $c->dateCreated ? substr((string) $c->dateCreated, 0, 10) : '',
                (($user->email ?? null) ?: ($c->email ?: '')),
            ];
        }

        return $rows;
    }
}
