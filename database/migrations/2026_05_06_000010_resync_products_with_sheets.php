<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ресинк продуктов с Google Sheets «Аудит Продукты и баллы без учета НДС»
 * (лист «ПРОДУКТЫ» от 2026-05-06).
 *
 * Source-of-truth — 21 продукт. У нас в БД сейчас 75 активных. Логически
 * удаляем все остальные (active=false, publish_status='draft') — НЕ DELETE,
 * чтобы не сломать FK от contract/program/dsCommission на product.
 *
 * Карта Sheets → DB id:
 *   Axevil                            → 36
 *   IAL                               → 23 (International Assurance Limited)
 *   Investor Trust Assurance SPX      → 13
 *   Investor Trust Evolution          → 12
 *   Investor Trust MSCI Index         → 14
 *   Investors Trust Access Portfolio  → 18
 *   Investors Trust Fixed Income      → 86
 *   Investors Trust Platinum PLUS     → 20
 *   Investors Trust Platinum Select   → 19
 *   Medlife                           → 15
 *   Альфа                             → 47
 *   БКС Страхование Жизни             → 60
 *   БРОКЕР+ (BROKER+)                 → 66
 *   ВНЖ                               → 87
 *   Зетта                             → 73
 *   Недвижимость Шефер                → 75
 *   Парус                             → 71
 *   РАНКС                             → 90
 *   Ренессанс                         → 42
 *   Совкомбанк                        → 40
 *   Робоэдвайзер                      → создаётся новым id
 *
 * Дополнительно: удаляем два umbrella-продукта, которые я создавал
 * 2026-05-05 (id 93 «ВНЖ Армении», id 94 «Investors Trust») — оба без
 * контрактов, безопасно DELETE.
 */
return new class extends Migration
{
    /** @var int[] */
    private array $keepIds = [36, 23, 13, 12, 14, 18, 86, 20, 19, 15, 47, 60, 66, 87, 73, 75, 71, 90, 42, 40];

    /** @var int[] */
    private array $umbrellasToDrop = [93, 94];

    public function up(): void
    {
        DB::transaction(function () {
            // 1. Снапшот предыдущего состояния — для down().
            //    Кладём в простую таблицу-резерв; миграция вторая её снесёт.
            DB::statement('
                CREATE TABLE IF NOT EXISTS product_resync_backup_2026_05_06 (
                    id INTEGER PRIMARY KEY,
                    active BOOLEAN,
                    publish_status VARCHAR(20)
                )
            ');
            DB::statement('
                INSERT INTO product_resync_backup_2026_05_06 (id, active, publish_status)
                SELECT id, active, publish_status FROM product
                ON CONFLICT (id) DO UPDATE SET active = EXCLUDED.active, publish_status = EXCLUDED.publish_status
            ');

            // 2. Деактивируем ВСЕ продукты — потом активируем только 20 совпавших.
            DB::table('product')->update([
                'active' => false,
                'publish_status' => 'draft',
            ]);

            // 3. Активируем 20 продуктов из Sheets.
            DB::table('product')->whereIn('id', $this->keepIds)->update([
                'active' => true,
                'publish_status' => 'published',
            ]);

            // 4. Удаляем umbrella-продукты которые я создал (без контрактов).
            //    Безопасно проверяем contract.product — если кто-то всё-таки
            //    использовал — оставляем как deactivated.
            $hasContracts = DB::table('contract')
                ->whereIn('product', $this->umbrellasToDrop)
                ->whereNotNull('product')
                ->pluck('product')
                ->unique()
                ->all();
            $deletable = array_diff($this->umbrellasToDrop, $hasContracts);
            if (! empty($deletable)) {
                // Программы под этим продуктом тоже удалим если они только
                // были привязаны к umbrella — иначе FK program.product упадёт.
                DB::table('program')->whereIn('product', $deletable)->delete();
                DB::table('product')->whereIn('id', $deletable)->delete();
            }

            // 5. Создаём «Робоэдвайзер» если его ещё нет.
            $exists = DB::table('product')->where('name', 'Робоэдвайзер')->exists();
            if (! $exists) {
                $nextId = (int) DB::scalar('SELECT GREATEST(COALESCE(MAX(id),0), 92) + 1 FROM product');
                DB::table('product')->insert([
                    'id' => $nextId,
                    'name' => 'Робоэдвайзер',
                    'active' => true,
                    'publish_status' => 'published',
                    'visibleToCalculator' => true,
                    'visibleToResident' => true,
                    'noComission' => false,
                ]);
            }

            // Лог для оператора.
            if (app()->runningInConsole()) {
                $stats = DB::selectOne('
                    SELECT
                        COUNT(*) FILTER (WHERE active=true) AS now_active,
                        COUNT(*) FILTER (WHERE active=false) AS now_inactive,
                        COUNT(*) AS total
                    FROM product
                ');
                echo "  product resync: active={$stats->now_active}, inactive={$stats->now_inactive}, total={$stats->total}\n";
            }
        });
    }

    public function down(): void
    {
        // Восстанавливаем active/publish_status из backup.
        if (! DB::getSchemaBuilder()->hasTable('product_resync_backup_2026_05_06')) {
            return;
        }
        DB::transaction(function () {
            // «Робоэдвайзер» удаляем, если был создан и без контрактов.
            DB::table('product')
                ->where('name', 'Робоэдвайзер')
                ->whereNotIn('id', function ($q) {
                    $q->select('product')->from('contract')->whereNotNull('product');
                })
                ->delete();

            // Возвращаем флаги по backup.
            $rows = DB::table('product_resync_backup_2026_05_06')->get();
            foreach ($rows as $r) {
                DB::table('product')->where('id', $r->id)->update([
                    'active' => (bool) $r->active,
                    'publish_status' => $r->publish_status ?? 'draft',
                ]);
            }
        });
    }
};
