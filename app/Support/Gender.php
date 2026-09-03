<?php

namespace App\Support;

/**
 * Пол партнёра: канонизация хранимого значения и определение по отчеству.
 *
 * Канон в базе — «male» / «female» (WebUser.gender). Из Directual приехали
 * русские значения («Мужской» / «Женский») и однобуквенные коды, поэтому
 * читать колонку напрямую нельзя — только через normalize().
 *
 * ⚠ У партнёров БЕЗ логина пола нет вообще: колонка живёт только в WebUser,
 * у consultant её не существует. Для таких строк остаётся определение по
 * отчеству — оно же закрывает пробелы у тех, кто просто не заполнил профиль.
 *
 * Русское отчество даёт пол практически однозначно: -вич/-ыч → мужской,
 * -вна/-чна → женский. Исключения — нерусские отчества и их отсутствие
 * (у части партнёров отчества нет); в этих случаях возвращаем null, а не
 * догадку. Никаких выводов по имени или фамилии не делаем: «Женя», «Саша»,
 * «Кузьма» и нерусские имена ошибаются слишком часто, а это персональные
 * данные реальных людей.
 */
final class Gender
{
    public const MALE = 'male';
    public const FEMALE = 'female';

    /** Хранимое значение → канон. null, если пусто или не распознано. */
    public static function normalize(mixed $raw): ?string
    {
        $s = mb_strtolower(trim((string) $raw));
        if ($s === '') {
            return null;
        }
        if (in_array($s, ['male', 'm', 'м', 'муж', 'мужской'], true)) {
            return self::MALE;
        }
        if (in_array($s, ['female', 'f', 'ж', 'жен', 'женский'], true)) {
            return self::FEMALE;
        }

        return null;
    }

    /**
     * Пол по отчеству. null — если отчества нет или окончание не русское
     * («Ахмет оглы», «Нурлановна» распознаётся, а «Ким» — нет).
     */
    public static function fromPatronymic(?string $patronymic): ?string
    {
        $p = mb_strtolower(trim((string) $patronymic));
        if (mb_strlen($p) < 4) {
            return null;
        }

        // Женские окончания проверяем ПЕРВЫМИ: «-овна» содержит «-вна», но
        // не содержит мужских хвостов, а вот «-ична» и «-инична» иначе
        // рискуют разойтись при добавлении новых правил.
        foreach (['вна', 'чна', 'кызы'] as $suffix) {
            if (str_ends_with($p, $suffix)) {
                return self::FEMALE;
            }
        }
        foreach (['вич', 'ьич', 'ич', 'ыч', 'оглы', 'улы'] as $suffix) {
            if (str_ends_with($p, $suffix)) {
                return self::MALE;
            }
        }

        return null;
    }

    /**
     * Пол для аналитики: сначала заполненное значение, затем отчество.
     * Второй аргумент — денормализованное ФИО («Фамилия Имя Отчество»),
     * из которого берём третье слово: у партнёров без логина отдельного
     * поля отчества нет.
     */
    public static function resolve(mixed $stored, ?string $patronymic = null, ?string $personName = null): ?string
    {
        $known = self::normalize($stored);
        if ($known !== null) {
            return $known;
        }

        $byPatronymic = self::fromPatronymic($patronymic);
        if ($byPatronymic !== null) {
            return $byPatronymic;
        }

        $parts = preg_split('/\s+/u', trim((string) $personName)) ?: [];

        return self::fromPatronymic($parts[2] ?? null);
    }

    /** Человекочитаемая подпись для выгрузок. */
    public static function label(?string $canonical): string
    {
        return match ($canonical) {
            self::MALE => 'Мужской',
            self::FEMALE => 'Женский',
            default => 'Не определён',
        };
    }
}
