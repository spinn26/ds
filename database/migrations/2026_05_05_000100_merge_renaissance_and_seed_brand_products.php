<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Объединение дубля «Ренессанс» (product 43 → 42) и создание двух
 * новых umbrella-продуктов под бренд-логотипы:
 *   - «ВНЖ Армении» (бренд Pragmatos Capital)
 *   - «Investors Trust» (бренд Investors Trust — вместо разрозненных
 *     8 sub-продуктов IT Evolution / SPX / MSCI / Platinum / Access / IAL).
 *
 * 43 «Ренессанс» в проде имеет 60 связанных строк по 5 таблицам — все
 * репоинтятся на 42, после чего 43 деактивируется (active=false,
 * publish_status='draft'). Пересечений по имени программ при текущих
 * данных нет, проверено вручную; если появятся — оператор разрулит
 * вручную в админке.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            // 1. Merge: product 43 → 42 (Ренессанс).
            // Repoint все ссылки и деактивируем 43.
            if (DB::table('product')->where('id', 43)->exists()) {
                DB::statement('UPDATE program SET product=42 WHERE product=43');
                DB::statement('UPDATE "dsCommission" SET product=42 WHERE product=43');
                DB::statement('UPDATE contract SET product=42 WHERE product=43');
                DB::statement('UPDATE "consultantProgramsData" SET product=42 WHERE product=43');
                DB::statement('UPDATE client SET products=42 WHERE products=43');
                DB::table('product')->where('id', 43)->update([
                    'active' => false,
                    'publish_status' => 'draft',
                ]);
            }

            // 2. Создать umbrella-продукты под бренд-логотипы. id вычисляем
            // через GREATEST (на проде MAX(id) может отличаться). Создаём
            // активные и опубликованные — оператор потом загрузит логотип.
            $insertIfMissing = function (string $name) {
                if (DB::table('product')->where('name', $name)->exists()) return;
                $nextId = (int) DB::scalar('SELECT GREATEST(COALESCE(MAX(id),0), 92) + 1 FROM product');
                DB::table('product')->insert([
                    'id' => $nextId,
                    'name' => $name,
                    'active' => true,
                    'publish_status' => 'published',
                    'visibleToCalculator' => true,
                    'visibleToResident' => true,
                    'noComission' => false,
                ]);
            };

            $insertIfMissing('ВНЖ Армении');
            $insertIfMissing('Investors Trust');
        });
    }

    public function down(): void
    {
        // Восстановить нельзя автоматически: после репоинта в 42 нет
        // признака «эта строка пришла из 43». Ставим 43 обратно active=true,
        // удаляем созданные umbrella-продукты по имени.
        DB::table('product')->where('id', 43)->update([
            'active' => true,
            'publish_status' => 'published',
        ]);
        DB::table('product')->whereIn('name', ['ВНЖ Армении', 'Investors Trust'])
            ->where('publish_status', 'published')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('contract')
                  ->whereColumn('contract.product', 'product.id');
            })
            ->delete();
    }
};
