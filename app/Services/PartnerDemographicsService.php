<?php

namespace App\Services;

use App\Support\Age;
use App\Support\Gender;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Демография партнёрской сети: пол и возраст по каждому живому партнёру.
 *
 * Один сборщик на два отчёта — сводку (проценты, распределение) и список.
 * Иначе цифры в них разъедутся: пол в половине случаев вычисляется, и любое
 * расхождение в правиле сразу даёт разные проценты в двух файлах.
 *
 * ⚠ Периода здесь нет намеренно: демография — снимок сети на сегодня.
 *
 * Откуда данные:
 *   - пол — WebUser.gender, а если пусто (или логина нет вовсе: колонка
 *     живёт только в WebUser) — по отчеству, см. App\Support\Gender;
 *   - дата рождения — WebUser.birthDate, фоллбэк на consultant.birthDate
 *     (у партнёров без логина она только там, и там же varchar).
 */
class PartnerDemographicsService
{
    /**
     * @param array<string, mixed> $filters поддерживается activity
     * @return Collection<int, array<string, mixed>>
     */
    public function records(array $filters = []): Collection
    {
        $query = DB::table('consultant')->whereNull('dateDeleted');

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

        return $partners->map(function ($c) use ($users, $activityNames) {
            $user = $c->webUser ? ($users[$c->webUser] ?? null) : null;

            $stored = $user->gender ?? null;
            $gender = Gender::resolve($stored, $user->patronymic ?? null, $c->personName);
            // Факт из профиля и вычисленное по отчеству нельзя смешивать:
            // в сводке доля вычисленных показывается отдельной строкой.
            $source = match (true) {
                Gender::normalize($stored) !== null => 'Профиль',
                $gender !== null => 'По отчеству',
                default => 'Нет данных',
            };

            $birthDate = $user->birthDate ?? $c->birthDate;
            $years = Age::years($birthDate);

            return [
                'personName' => $c->personName,
                'activityName' => $c->activity ? ($activityNames[$c->activity] ?? '') : '',
                'gender' => $gender,
                'genderSource' => $source,
                'birthDate' => Age::date($birthDate),
                'years' => $years,
                'bucket' => Age::bucket($years),
                'dateCreated' => $c->dateCreated ? substr((string) $c->dateCreated, 0, 10) : null,
                'email' => (($user->email ?? null) ?: ($c->email ?: null)),
            ];
        });
    }
}
