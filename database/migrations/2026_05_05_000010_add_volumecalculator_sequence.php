<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sequence для volumeCalculator.id — таблица была пропущена в миграции
 * 2026_05_04_000040_add_id_sequences_for_legacy_tables. На проде каждое
 * сохранение калькулятор-расчёта падает с UniqueConstraintViolation,
 * лог-уровень DEBUG (try/catch проглатывает) — но история не пишется.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tableExists = (bool) DB::scalar('
            SELECT 1 FROM information_schema.tables
            WHERE table_schema = current_schema() AND table_name = ?
        ', ['volumeCalculator']);
        if (! $tableExists) return;

        $seqRealName = DB::scalar('SELECT pg_get_serial_sequence(?, ?)', ['"volumeCalculator"', 'id']);
        if (! $seqRealName) {
            DB::statement('CREATE SEQUENCE IF NOT EXISTS "volumeCalculator_id_seq"');
            DB::statement('ALTER SEQUENCE "volumeCalculator_id_seq" OWNED BY "volumeCalculator".id');
            DB::statement('ALTER TABLE "volumeCalculator" ALTER COLUMN id SET DEFAULT nextval(\'"volumeCalculator_id_seq"\')');
            $seqRealName = '"volumeCalculator_id_seq"';
        }

        $maxId = (int) DB::scalar('SELECT COALESCE(MAX(id), 0) FROM "volumeCalculator"');
        DB::statement("SELECT setval('{$seqRealName}'::regclass, ?, true)", [max($maxId, 1)]);
    }

    public function down(): void
    {
        // No-op — снимать DEFAULT опасно, новые insert'ы перестанут работать.
    }
};
