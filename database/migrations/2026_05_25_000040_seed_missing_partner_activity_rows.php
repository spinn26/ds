<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * В `directory_of_activities` (legacy Directual-справочник) исторически
 * лежат только 3 строки: 1 «Активный», 2 «Неактивный», 3 «Терминирован».
 *
 * Но в enum App\Enums\PartnerActivity описаны ещё два статуса:
 *   4 — Зарегистрирован, 5 — Исключён.
 *
 * AuthController::register() при создании consultant ставит activity=4
 * и падает на consultant_activity_fkey, потому что строки 4 в справочнике
 * нет. Добавляем недостающие строки idempotent-вставкой.
 */
return new class extends Migration {
    public function up(): void
    {
        $rows = [
            ['id' => 4, 'name' => 'Зарегистрирован'],
            ['id' => 5, 'name' => 'Исключён'],
        ];
        foreach ($rows as $row) {
            $exists = DB::table('directory_of_activities')->where('id', $row['id'])->exists();
            if (! $exists) {
                DB::table('directory_of_activities')->insert($row);
            }
        }
    }

    public function down(): void
    {
        // Не откатываем: эти строки могут уже использоваться в consultant.activity,
        // удаление вызовет FK-violation. Безопаснее оставить.
    }
};
