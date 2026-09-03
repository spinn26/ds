<?php

namespace App\Services;

use App\Support\Age;
use App\Support\Gender;
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
     * ⚠ Отдаём массив, а не Collection, намеренно: значение у Collection
     * инвариантно, и любое уточнение типа поля (non-empty-string вместо
     * string и подобное) делает вывод подтипом объявленного — анализатор
     * валит сборку, а сообщение обрезает как раз то поле, где расхождение.
     * У массивов такой ловушки нет. Кому нужен Collection API — collect().
     *
     * @param array<string, mixed> $filters поддерживается activity
     * @return list<array{
     *     personName: string,
     *     activityName: string,
     *     gender: string|null,
     *     genderSource: string,
     *     birthDate: string|null,
     *     years: int|null,
     *     bucket: string,
     *     dateCreated: string|null,
     *     email: string|null,
     * }>
     */
    public function records(array $filters = []): array
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
            $source = $this->genderSource($stored, $gender);

            $birthDate = $user->birthDate ?? $c->birthDate;
            $years = Age::years($birthDate);

            // Приведение к скалярам — не косметика: строки приходят из
            // DB::table() как mixed, и без него shape в @return не сходится.
            return [
                'personName' => (string) $c->personName,
                'activityName' => (string) ($c->activity ? ($activityNames[$c->activity] ?? '') : ''),
                'gender' => $gender,
                'genderSource' => $source,
                'birthDate' => Age::date($birthDate),
                'years' => $years,
                'bucket' => Age::bucket($years),
                'dateCreated' => $c->dateCreated ? substr((string) $c->dateCreated, 0, 10) : null,
                'email' => $this->email($user->email ?? null, $c->email),
            ];
        })->values()->all();
    }

    /**
     * Откуда взялся пол. Факт из профиля и вычисленное по отчеству смешивать
     * нельзя: в сводке доля вычисленных показывается отдельной строкой.
     *
     * Отдельным методом с явным `string`, а не match'ем по месту: иначе тип
     * значения — union из трёх литералов, и он не сходится с shape в @return
     * (у Collection значение инвариантно).
     */
    /**
     * Почта: логин партнёра, а если его нет — собственная колонка карточки.
     *
     * Тоже отдельным методом: «?:» сужает тип до non-empty-string, и он
     * перестаёт совпадать со `string|null` из shape в @return.
     */
    private function email(mixed $login, mixed $card): ?string
    {
        $email = ($login ?: null) ?: ($card ?: null);

        return $email !== null ? (string) $email : null;
    }

    private function genderSource(mixed $stored, ?string $resolved): string
    {
        if (Gender::normalize($stored) !== null) {
            return 'Профиль';
        }

        return $resolved !== null ? 'По отчеству' : 'Нет данных';
    }
}
