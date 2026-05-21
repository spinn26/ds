<?php

namespace App\Support;

/**
 * Резолвер «Поставщика» для отчётных колонок.
 *
 * В legacy-данных у Insmart-продуктов program.providerName хранит конечного
 * страховщика-партнёра (Зетта, Пари, Ренессанс, Сбербанк Страхование и т.п.),
 * а не сам канал «Insmart». В бизнес-логике поставщик для таких продуктов —
 * Insmart, а страховщик — субпоставщик. UI-колонка «Поставщик» (в Контрактах,
 * Комиссиях, отчётах) должна показывать «Insmart» вне зависимости от того,
 * что лежит в program.providerName.
 *
 * Данные в БД не трогаем — фактический страховщик остаётся в providerName
 * и доступен через subProvider() для тултипа / детального вью.
 */
class SupplierResolver
{
    /**
     * В product.name «Insmart» исторически написан с двумя «s» («Inssmart»),
     * но также встречается с одной — фильтруем оба варианта.
     */
    public static function isInsmartProduct(?string $productName): bool
    {
        if (! $productName) {
            return false;
        }
        return preg_match('/ins+mart/i', $productName) === 1;
    }

    /**
     * Главный поставщик для UI-колонки.
     * Insmart-продукты → «Insmart». Остальные → program.providerName as-is.
     */
    public static function resolve(?string $productName, ?string $rawProvider): ?string
    {
        if (self::isInsmartProduct($productName)) {
            return 'Insmart';
        }
        return $rawProvider;
    }

    /**
     * Субпоставщик (конечный страховщик у Insmart-продуктов).
     * Для не-Insmart — null (поставщик и так совпадает).
     */
    public static function subProvider(?string $productName, ?string $rawProvider): ?string
    {
        return self::isInsmartProduct($productName) ? $rawProvider : null;
    }
}
