<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill commission.amountRUB ← commission.amount для legacy-строк,
 * у которых amountRUB пуст. В Directual amount хранили без указания
 * валюты, но фактически 99% строк — рублёвые (домашняя валюта DS).
 *
 * Это закрывает баг отображения «0,00» во всех finance-страницах
 * (Реестр выплат, Комиссии, Транзакции, Финрез-отчёты), которые
 * читают commission.amountRUB напрямую.
 *
 * Идемпотентно: WHERE amountRUB IS NULL гарантирует, что если миграция
 * уже отработала, повторный запуск ничего не меняет.
 */
return new class extends Migration
{
    public function up(): void
    {
        $updated = DB::table('commission')
            ->whereNull('amountRUB')
            ->whereNotNull('amount')
            ->update(['amountRUB' => DB::raw('amount')]);

        // Логируем сколько строк апдейтнули — будет в migrate-выводе.
        echo "  Backfilled commission.amountRUB from amount: {$updated} rows" . PHP_EOL;
    }

    public function down(): void
    {
        // Откат не делаем — иначе сломаем работающие после миграции
        // расчёты, которые уже опираются на заполненный amountRUB.
    }
};
