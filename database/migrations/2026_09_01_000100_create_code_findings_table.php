<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Реестр находок аудита кода (страница «Качество кода» в админке).
 *
 * До этой миграции 74 находки лежали массивами прямо в
 * resources/js/pages/Admin/CodeQuality.vue — 301 строка данных из 471.
 * Чтобы закрыть находку, требовался коммит во фронтенд и полный релиз
 * (composer, npm build, миграции, перезапуск сервисов). Из-за этой цены
 * реестр не обновлялся с 09.07.2026: половина «high» была давно
 * исправлена, но в интерфейсе висела открытой.
 *
 * Стартовое наполнение берём из database/data/code-findings.json —
 * это снимок того же массива на момент переезда. Дальше источник
 * истины — таблица, JSON нужен только для первого прогона и для
 * восстановления на чистой базе.
 *
 * Идемпотентно: сид выполняется только если таблица пуста, поэтому
 * повторный `migrate` (или накат на среду, где строки уже правились
 * руками) ничего не перезатрёт.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('code_findings', function (Blueprint $table) {
            $table->id();
            // Человеческий код находки: SEC-1, BIZ-12, FX-ENV. Уникален —
            // на него ссылаются коммиты и обсуждения.
            $table->string('code', 32)->unique();
            $table->string('severity', 16)->default('low'); // critical|high|medium|low
            $table->string('category', 160);
            $table->string('title', 500);
            // Где именно: путь и строки. Не у всех находок есть точный адрес.
            $table->string('file', 500)->nullable();
            $table->text('problem');
            $table->text('recommendation');
            $table->string('status', 16)->default('open'); // open|fixed
            $table->integer('sort_order')->default(0);
            $table->timestamp('closed_at')->nullable();
            $table->integer('closed_by')->nullable(); // WebUser.id
            $table->timestamps();

            $table->index(['status', 'severity']);
            $table->index('category');
        });

        $this->seedFromJson();
    }

    public function down(): void
    {
        Schema::dropIfExists('code_findings');
    }

    /**
     * Первичное наполнение из снимка. Молча пропускаем, если файла нет
     * или таблица уже не пуста — миграция не должна падать из-за данных.
     */
    private function seedFromJson(): void
    {
        if (DB::table('code_findings')->exists()) {
            return;
        }

        $path = database_path('data/code-findings.json');
        if (! is_file($path)) {
            return;
        }

        $rows = json_decode((string) file_get_contents($path), true);
        if (! is_array($rows)) {
            return;
        }

        $now = now();
        $payload = [];

        foreach ($rows as $r) {
            if (empty($r['id'])) {
                continue;
            }

            $status = ($r['status'] ?? 'open') === 'fixed' ? 'fixed' : 'open';

            $payload[] = [
                'code' => (string) $r['id'],
                'severity' => (string) ($r['severity'] ?? 'low'),
                'category' => (string) ($r['category'] ?? 'Прочее'),
                'title' => (string) ($r['title'] ?? ''),
                'file' => $r['file'] ?? null,
                'problem' => (string) ($r['problem'] ?? ''),
                'recommendation' => (string) ($r['recommendation'] ?? ''),
                'status' => $status,
                'sort_order' => (int) ($r['sort_order'] ?? 0),
                // Дату закрытия исторических находок мы не знаем — оставляем
                // пустой, чтобы не выдумывать. Для новых её проставит API.
                'closed_at' => null,
                'closed_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($payload, 100) as $chunk) {
            DB::table('code_findings')->insert($chunk);
        }
    }
};
