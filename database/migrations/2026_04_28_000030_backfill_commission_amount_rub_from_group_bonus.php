<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Дополнительный backfill: legacy commission.amountRUB ← groupBonusRub.
 *
 * В Directual-схеме рассчитанная комиссия в рублях писалась в
 * `groupBonusRub`, а `amountRUB` оставался NULL. Из-за этого все
 * finance-страницы (Реестр выплат, Журнал транзакций, Финрез-отчёты),
 * которые читают `commission.amountRUB`, показывают «0,00» для
 * 391127 строк (~73% commission-таблицы).
 *
 * Backfill заполняет amountRUB значением groupBonusRub для активных
 * (не soft-deleted) строк. Идемпотентно: WHERE amountRUB IS NULL
 * предотвращает повторное переписывание уже заполненных значений.
 */
return new class extends Migration
{
    public function up(): void
    {
        $updated = DB::table('commission')
            ->whereNull('amountRUB')
            ->whereNotNull('groupBonusRub')
            ->where('groupBonusRub', '>', 0)
            ->whereNull('deletedAt')
            ->update(['amountRUB' => DB::raw('"groupBonusRub"')]);

        echo "  Backfilled commission.amountRUB from groupBonusRub: {$updated} rows" . PHP_EOL;
    }

    public function down(): void
    {
        // Не откатываем — иначе пересчёты, опирающиеся на amountRUB, сломаются.
    }
};
