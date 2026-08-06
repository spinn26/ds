<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Самовосстановление партнёра после терминации (2026-08-06).
 *
 * Партнёр может САМ вернуться в работу после терминации — до 3 раз. Счётчик
 * восстановлений отдельный от terminationCount: последний остаётся
 * историческим показателем и уменьшается при отмене ОШИБОЧНОЙ терминации
 * (restoreFromTermination), а лимит попыток от этого зависеть не должен.
 *
 * Триггер исключения переносится с «терминаций стало N» на «терминация при
 * исчерпанных восстановлениях» — иначе третья попытка недостижима: партнёр
 * уходил бы в «Исключён» на 3-й терминации. activation.max_terminations
 * остаётся жёстким потолком и поднимается до 4 (3 восстановления + финальная
 * терминация).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultant', function (Blueprint $table) {
            if (! Schema::hasColumn('consultant', 'reinstatement_count')) {
                $table->integer('reinstatement_count')->default(0);
            }
            if (! Schema::hasColumn('consultant', 'reinstate_blocked')) {
                $table->boolean('reinstate_blocked')->default(false);
            }
            if (! Schema::hasColumn('consultant', 'last_reinstate_at')) {
                $table->timestamp('last_reinstate_at')->nullable();
            }
        });

        // NOT NULL выставляем после дефолта — на legacy-строках Directual
        // значение проставится дефолтом, отдельный backfill не нужен.
        DB::statement('UPDATE consultant SET reinstatement_count = 0 WHERE reinstatement_count IS NULL');
        DB::statement('UPDATE consultant SET reinstate_blocked = false WHERE reinstate_blocked IS NULL');

        if (! Schema::hasTable('system_settings')) {
            return;
        }
        $now = now();
        $rows = [
            ['activation.self_reinstate_enabled', '1', 'bool', 'business', 'Самовосстановление после терминации', 'Терминированный партнёр может вернуться в работу сам, из окна при входе.', 31],
            ['activation.self_reinstate_limit', '3', 'int', 'business', 'Макс. самовосстановлений', 'Сколько раз партнёр может восстановиться сам. Исчерпал — следующая терминация исключает.', 32],
        ];
        foreach ($rows as $r) {
            DB::table('system_settings')->updateOrInsert(
                ['key' => $r[0]],
                ['value' => $r[1], 'type' => $r[2], 'category' => $r[3], 'label' => $r[4], 'description' => $r[5], 'sort_order' => $r[6], 'updated_at' => $now, 'created_at' => $now]
            );
        }

        // Потолок терминаций: 3 восстановления + финальная терминация.
        DB::table('system_settings')->where('key', 'activation.max_terminations')->update([
            'value' => '4',
            'description' => 'Жёсткий потолок: столько терминаций партнёр не переживёт ни при каких условиях. Основной триггер исключения — исчерпанные самовосстановления.',
            'updated_at' => $now,
        ]);

        // SystemSetting кэширует всю карту rememberForever и сбрасывает её
        // только в put(). Пишем в таблицу напрямую → инвалидируем руками,
        // иначе новые значения не видны до перезапуска кэша.
        Cache::forget('system_settings:map');
    }

    public function down(): void
    {
        if (Schema::hasTable('system_settings')) {
            DB::table('system_settings')->whereIn('key', [
                'activation.self_reinstate_enabled',
                'activation.self_reinstate_limit',
            ])->delete();
            DB::table('system_settings')->where('key', 'activation.max_terminations')->update([
                'value' => '3',
                'description' => 'После стольких терминаций партнёр исключается.',
                'updated_at' => now(),
            ]);
            Cache::forget('system_settings:map');
        }

        Schema::table('consultant', function (Blueprint $table) {
            foreach (['reinstatement_count', 'reinstate_blocked', 'last_reinstate_at'] as $col) {
                if (Schema::hasColumn('consultant', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
