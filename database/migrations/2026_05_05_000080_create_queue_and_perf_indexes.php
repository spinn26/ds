<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Релиз-блокеры из аудита платформы:
 *
 * 1. Таблицы `jobs` / `failed_jobs` / `job_batches` — на случай fallback
 *    с `QUEUE_CONNECTION=redis` на `database` (например, при недоступности
 *    Redis). Без этих таблиц Laravel упадёт при попытке отдать job.
 *
 * 2. UNIQUE индекс на `WebUser.email` — основной критерий login-запроса.
 *    Без него на каждый login делается sequential scan ~1k строк.
 *    UNIQUE даёт двойную пользу: индекс + защита от двух WebUser с одним
 *    e-mail (сейчас ничто не запрещает).
 *
 * Все операции идемпотентные через hasTable / hasIndex.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ==== queue tables (database driver fallback) ====
        if (! Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }

        if (! Schema::hasTable('job_batches')) {
            Schema::create('job_batches', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('name');
                $table->integer('total_jobs');
                $table->integer('pending_jobs');
                $table->integer('failed_jobs');
                $table->longText('failed_job_ids');
                $table->mediumText('options')->nullable();
                $table->integer('cancelled_at')->nullable();
                $table->integer('created_at');
                $table->integer('finished_at')->nullable();
            });
        }

        if (! Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
        }

        // ==== WebUser.email UNIQUE INDEX ====
        // Сначала ищем дубли (legacy-данные могли пропустить уникальность);
        // если нашли — лог и пропускаем создание индекса, иначе — создаём.
        $duplicates = DB::table('WebUser')
            ->select('email')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->groupBy('email')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('email');

        if ($duplicates->isNotEmpty()) {
            // На проде — резолвить вручную, миграция не должна стирать данные.
            if (app()->runningInConsole()) {
                echo "  [skip] WebUser.email UNIQUE: найдено " . $duplicates->count()
                    . " дублей. Резолвите вручную, потом перезапустите миграцию.\n";
            }
            return;
        }

        // Проверяем что индекса ещё нет (имя нестандартное из-за camelCase).
        $exists = DB::selectOne("
            SELECT 1 FROM pg_indexes
            WHERE tablename = 'WebUser'
              AND indexname = 'webuser_email_unique'
        ");
        if (! $exists) {
            DB::statement('CREATE UNIQUE INDEX webuser_email_unique ON "WebUser" (email) WHERE email IS NOT NULL AND email <> \'\'');
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS webuser_email_unique');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('jobs');
    }
};
