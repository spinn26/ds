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

    /**
     * Фильтр «Поставщик» для списков (Комиссии / Менеджер контрактов / Ручной ввод).
     *
     * Фильтр ОБЯЗАН резолвить поставщика тем же выражением, что и колонка своей
     * страницы, иначе выдача не совпадает с фильтром. Раньше фильтр везде смотрел
     * в legacy program.providerName/vendorName, а колонка «Комиссий» — в
     * products_catalog: после ремапа продуктов источники разъехались, и по
     * фильтру «ГГА» приезжали строки, показывающие «Ренессанс».
     *
     * Единого выражения тут быть не может — страницы исторически считают
     * «поставщика» по-разному (каталог-первым / vendor-первым / только provider),
     * поэтому SQL-выражения передаёт вызывающий, а общим остаётся правило Insmart:
     * у Insmart-продуктов в providerName лежит конечный страховщик, а поставщик —
     * сам канал.
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @param string $nameExpr     SQL-выражение имени продукта (для Insmart-детекта)
     * @param string $providerExpr SQL-выражение поставщика — как в колонке страницы
     */
    public static function applyFilter($query, string $supplier, string $nameExpr, string $providerExpr): void
    {
        $query->where(function ($w) use ($supplier, $nameExpr, $providerExpr) {
            if (self::isInsmartProduct($supplier)) {
                $w->whereRaw("COALESCE($nameExpr, '') ILIKE '%insmart%'")
                  ->orWhereRaw("COALESCE($nameExpr, '') ILIKE '%inssmart%'");
                return;
            }

            $w->whereRaw("COALESCE($nameExpr, '') NOT ILIKE '%insmart%'")
              ->whereRaw("COALESCE($nameExpr, '') NOT ILIKE '%inssmart%'")
              ->whereRaw("$providerExpr ILIKE ?", ['%' . $supplier . '%']);
        });
    }
}
