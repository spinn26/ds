<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Legacy-таблицы (poolLog, qualificationLog, commission, transaction, ...)
 * были импортированы CSV-импортёром как `BIGINT PRIMARY KEY` без auto-
 * increment, поэтому INSERT'ы из application-кода (PoolRunner::persist,
 * ManualTransactionController, etc.) падают с UniqueConstraintViolation
 * на id=1.
 *
 * Создаём недостающие sequence'ы, владельцем делаем колонку id и
 * выставляем DEFAULT nextval(seq). setval — на текущий MAX(id), чтобы
 * следующий insert получил уникальный.
 *
 * Безопасно повторно: CREATE SEQUENCE IF NOT EXISTS, ALTER COLUMN
 * SET DEFAULT тоже идемпотентен.
 */
return new class extends Migration
{
    /** Quoted name → table_name (для legacy camelCase) */
    private array $tables = [
        '"poolLog"' => 'poolLog',
        '"qualificationLog"' => 'qualificationLog',
        'commission' => 'commission',
        'transaction' => 'transaction',
        'person' => 'person',
        'requisites' => 'requisites',
        'bankrequisites' => 'bankrequisites',
        '"WebUser"' => 'WebUser',
        'consultant' => 'consultant',
        'client' => 'client',
        'contract' => 'contract',
        '"consultantBalance"' => 'consultantBalance',
        '"consultantPayment"' => 'consultantPayment',
    ];

    public function up(): void
    {
        foreach ($this->tables as $quoted => $plain) {
            // Проверка существования таблицы.
            $tableExists = (bool) DB::scalar('
                SELECT 1 FROM information_schema.tables
                WHERE table_schema = current_schema() AND table_name = ?
            ', [$plain]);
            if (! $tableExists) continue;

            // Узнаём имя реальной sequence у колонки id (identity или serial).
            // pg_get_serial_sequence возвращает 'schema."seqName"' для camelCase.
            $seqRealName = DB::scalar('SELECT pg_get_serial_sequence(?, ?)', [$quoted, 'id']);
            if (! $seqRealName) {
                // Колонка id создана как BIGINT PRIMARY KEY без sequence —
                // создаём вручную и привязываем DEFAULT.
                $seqName = $plain . '_id_seq';
                $seqQuoted = '"' . $seqName . '"';
                DB::statement("CREATE SEQUENCE IF NOT EXISTS {$seqQuoted}");
                DB::statement("ALTER SEQUENCE {$seqQuoted} OWNED BY {$quoted}.id");
                DB::statement("ALTER TABLE {$quoted} ALTER COLUMN id SET DEFAULT nextval('{$seqQuoted}')");
                $seqRealName = $seqQuoted;  // используем quoted для setval
            }

            // setval(seq::regclass, max(id), true) ⇒ next nextval даст max+1.
            $maxId = (int) DB::scalar("SELECT COALESCE(MAX(id), 0) FROM {$quoted}");
            DB::statement("SELECT setval('{$seqRealName}'::regclass, ?, true)", [max($maxId, 1)]);
        }
    }

    public function down(): void
    {
        // Откат: сбросить DEFAULT и удалить sequence. ОПАСНО для прода
        // (новые insert не получат id) — поэтому делаем no-op.
    }
};
