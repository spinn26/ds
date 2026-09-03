<?php

namespace App\Services;

use App\Support\Gender;
use Illuminate\Support\Facades\DB;

/**
 * Заполнение пустого пола партнёров по отчеству + канонизация легаси-значений.
 *
 * Один код на два входа: команду `partners:backfill-gender` (ручной прогон с
 * предпросмотром) и миграцию, которая делает это на деплое. Разъехаться они
 * не должны — это правка персональных данных реальных людей.
 *
 * Две операции, считаются раздельно:
 *   1. ЗАПОЛНЕНИЕ — пол пуст, отчество распознано → пишем male/female;
 *   2. КАНОНИЗАЦИЯ — пол хранится по-русски («Мужской») → приводим к канону.
 *      Смысл не меняется, но фильтры и валидация ждут male/female.
 *
 * Чего сервис не делает никогда:
 *   - не трогает уже заполненный канонический пол;
 *   - не гадает по имени и фамилии (Женя, Саша, нерусские имена);
 *   - не выдумывает пол там, где отчества нет или оно не распознано.
 *
 * Пол хранится ТОЛЬКО в WebUser: у партнёров без логина такой колонки нет,
 * для них пол вычисляется на лету при построении отчёта.
 */
final class GenderBackfillService
{
    /**
     * Что будет сделано. Ничего не пишет.
     *
     * @return array{
     *     fill: array<int, string>,
     *     canonize: array<int, string>,
     *     unknown: array<int, string>,
     *     canonical: int,
     * }
     */
    public function plan(?int $limit = null): array
    {
        // Только партнёры: WebUser, на который ссылается живая карточка.
        $query = DB::table('WebUser as wu')
            ->join('consultant as c', 'c.webUser', '=', 'wu.id')
            ->whereNull('c.dateDeleted')
            ->select(['wu.id', 'wu.gender', 'wu.patronymic', 'c.personName']);

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        $fill = [];
        $canonize = [];
        $unknown = [];
        $canonical = 0;

        foreach ($query->get() as $u) {
            $id = (int) $u->id;
            $known = Gender::normalize($u->gender);
            $raw = trim((string) $u->gender);

            if ($known !== null) {
                if ($raw === $known) {
                    $canonical++;
                } else {
                    $canonize[$id] = $known;
                }

                continue;
            }

            $guess = Gender::resolve(null, $u->patronymic, $u->personName);
            if ($guess !== null) {
                $fill[$id] = $guess;
            } else {
                $unknown[$id] = (string) $u->personName;
            }
        }

        return ['fill' => $fill, 'canonize' => $canonize, 'unknown' => $unknown, 'canonical' => $canonical];
    }

    /**
     * Записать пол. Возвращает количество обновлённых записей.
     *
     * Пишем пачками по значению: два UPDATE вместо тысячи.
     *
     * @param array<int, string> $updates id → male|female
     */
    public function apply(array $updates): int
    {
        if (! $updates) {
            return 0;
        }

        DB::transaction(function () use ($updates) {
            foreach ([Gender::MALE, Gender::FEMALE] as $value) {
                $ids = array_keys(array_filter($updates, fn (string $v) => $v === $value));
                if ($ids) {
                    DB::table('WebUser')->whereIn('id', $ids)->update(['gender' => $value]);
                }
            }
        });

        return count($updates);
    }
}
