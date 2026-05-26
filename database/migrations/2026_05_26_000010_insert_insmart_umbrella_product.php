<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Добавляем umbrella-карточку «InSmart» в legacy-таблицу `product`.
 *
 * Виды страхования (ОСАГО / КАСКО / Ипотека / Медицинское / Имущество /
 * ВЗР / Мини КАСКО Inssmart) уже есть в БД как отдельные продукты,
 * но в статусе draft/inactive — у них исторические контракты и
 * программы, поэтому их не удаляем. Партнёру в каталоге показываем
 * одну карточку «InSmart», которая ведёт на `/insmart-widget` — там
 * подгружается b2c-frame-loader InSmart, и партнёр выбирает вид
 * страхования внутри самого виджета.
 *
 * Идемпотентно: если запись уже есть (name='InSmart' или url совпадает) —
 * ничего не делаем.
 */
return new class extends Migration {
    public function up(): void
    {
        $exists = DB::table('product')
            ->where(function ($q) {
                $q->where('name', 'InSmart')
                  ->orWhere('openProductUrl', '/insmart-widget');
            })
            ->exists();

        if ($exists) {
            return;
        }

        // Legacy Directual-таблица: `id` без sequence, считаем вручную.
        $nextId = (int) DB::table('product')->max('id') + 1;

        DB::table('product')->insert([
            'id' => $nextId,
            'name' => 'InSmart',
            // productType=12 = «Страховые продукты» (см. таблицу productType).
            // Все 7 существующих Inssmart-черновиков используют тот же тип.
            'productType' => 12,
            'active' => true,
            'publish_status' => 'published',
            'openProductUrl' => '/insmart-widget',
            'visibleToResident' => true,
            'visibleToCalculator' => false,
            'has_property' => false,
            'has_term' => false,
            'has_year_kv' => false,
        ]);
    }

    public function down(): void
    {
        // Удаляем только если за карточкой нет контрактов/программ —
        // на момент создания их быть не должно (это новая umbrella-карточка,
        // контракты в неё не идут — они привязаны к подпродуктам InSmart).
        $row = DB::table('product')
            ->where('name', 'InSmart')
            ->where('openProductUrl', '/insmart-widget')
            ->first();

        if (! $row) return;

        $hasContracts = DB::table('contract')->where('product', $row->id)->exists();
        $hasPrograms = DB::table('program')->where('product', $row->id)->exists();
        if ($hasContracts || $hasPrograms) {
            // Кто-то успел привязать данные — не сносим, чтобы не
            // нарушать FK. Откат в этом случае не нужен.
            return;
        }

        DB::table('product')->where('id', $row->id)->delete();
    }
};
