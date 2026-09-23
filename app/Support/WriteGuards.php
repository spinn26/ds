<?php

namespace App\Support;

/**
 * Роли, закрытые на запись поверх матрицы «Группы и права», и разделы, где
 * запись им всё-таки доходит до сервера.
 *
 * Зачем: пять ролей (head, support, corrections, education, invest) идут через
 * middleware Restrict*Writes — сплошной read-only по всей admin-группе с
 * узким списком исключений. Матрица об этом не знала: можно было поставить
 * роли «Полный» на любой раздел, чип показывался, а запись всё равно падала
 * с 403 — и человек узнавал об этом только из ошибки.
 *
 * Этот список — то, что матрица показывает в подсказке: «уровень выше
 * "Просмотра" здесь не сработает».
 *
 * ⚠ Зеркало, а не источник истины. Решают по-прежнему сами middleware, и они
 * сверяют ПУТИ (api/v1/admin/kb/ и т.п.), а не секции. Меняешь список
 * исключений в гарде — поправь и здесь, иначе подсказка начнёт врать.
 * Соответствие путей и секций взято из routes/v1/admin.php после того, как
 * пишущие маршруты этих разделов закрыли гейтами permission:<секция>.
 */
class WriteGuards
{
    /**
     * Роль => секции, где запись разрешена. Пустой массив — запись закрыта
     * полностью.
     *
     * @var array<string, list<string>>
     */
    public const SECTIONS = [
        // RestrictHeadWrites::SECTION_EXCEPTIONS
        'head' => ['news', 'reports'],
        // RestrictSupportWrites::WRITE_ALLOW — products покрывает и каталог
        // продуктов, и программы (общий префикс api/v1/admin/products).
        'support' => ['products', 'instructions'],
        // RestrictCorrectionsWrites::WRITE_ALLOW
        'corrections' => ['instructions'],
        // RestrictEducationWrites::ALLOWED_WRITE_PREFIXES — префикс
        // api/v1/admin/education/ покрывает и категории курсов,
        // api/v1/admin/kb/ — и проверку домашних заданий.
        'education' => ['education', 'education-categories', 'kb', 'homework', 'instructions'],
        // RestrictInvestWrites — чтение и ничего больше.
        'invest' => [],
    ];

    /** Роль идёт через read-only гард? */
    public static function isReadOnly(string $role): bool
    {
        return array_key_exists($role, self::SECTIONS);
    }

    /** Дойдёт ли запись этой роли до сервера в этом разделе. */
    public static function allows(string $role, string $section): bool
    {
        if (! self::isReadOnly($role)) {
            return true;
        }

        return in_array($section, self::SECTIONS[$role], true);
    }
}
