<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-06-02 (revision): единое окно акцепта при входе.
 *
 * 1) Откатываем «тихую» авто-подпись (2026_06_02_130000): удаляем backfill-строки
 *    акцепта и сбрасываем consultant.acceptance=false для ВСЕХ консультантов —
 *    чтобы при следующем входе показалось блокирующее окно, где партнёр сам
 *    примет все документы (юридически корректный явный акцепт).
 *
 * 2) Продукты: проставляем education_exempt=true ВСЕМ текущим консультантам —
 *    витрина продуктов открыта без прохождения курсов (для текущих партнёров).
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) откат авто-подписи
        if (Schema::hasTable('partnerAcceptance')) {
            DB::table('partnerAcceptance')->where('sourse', 'backfill')->delete();
        }
        if (Schema::hasTable('logAcceptance')) {
            DB::table('logAcceptance')->where('source', 'backfill')->delete();
        }
        if (Schema::hasColumn('consultant', 'acceptance')) {
            DB::table('consultant')->update(['acceptance' => false]);
        }

        // 2) grandfather продуктов для всех текущих консультантов
        if (Schema::hasColumn('consultant', 'education_exempt')) {
            DB::table('consultant')->update(['education_exempt' => true]);
        }
    }

    public function down(): void
    {
        // Необратимый bulk-сброс флагов; восстановление невозможно.
    }
};
