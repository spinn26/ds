<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Финансовая фаза: вынос бизнес-констант в настройки (категория business,
 * раздел «Настройки → Бизнес-правила / Расчёты»). ЗНАЧЕНИЯ ПО УМОЛЧАНИЮ
 * РАВНЫ текущим константам в коде — поведение расчётов НЕ меняется до явного
 * редактирования. Сервисы читают их с фолбэком на ту же константу.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_settings')) {
            return;
        }
        $now = now();
        $rows = [
            ['activation.min_lp', '500', 'int', 'business', 'Порог активации (ЛП)', 'Минимальные личные баллы для перехода в «Активен».', 10],
            ['activation.window_days', '90', 'int', 'business', 'Окно активации (дни)', 'Срок для набора порога ЛП с момента регистрации.', 20],
            ['activation.max_terminations', '3', 'int', 'business', 'Макс. терминаций до исключения', 'После стольких терминаций партнёр исключается.', 30],
            ['pool.percent', '0.01', 'float', 'business', 'Процент пула', 'Доля выручки без НДС, отчисляемая в лидерский пул (0.01 = 1%).', 40],
            ['detachment.threshold', '0.70', 'float', 'business', 'Порог отрыва', 'Доля группового объёма в одной ветке, выше которой — штраф (0.70 = 70%).', 50],
            ['detachment.penalty', '0.50', 'float', 'business', 'Множитель штрафа за отрыв', 'Комиссия ветки умножается на это (0.50 = ×0.5).', 60],
            ['op.penalty', '0.20', 'float', 'business', 'Штраф ОП-недобор', 'Доля, на которую снижается комиссия группы при недоборе ОП (0.20 = −20%).', 70],
        ];
        foreach ($rows as $r) {
            DB::table('system_settings')->updateOrInsert(
                ['key' => $r[0]],
                ['value' => $r[1], 'type' => $r[2], 'category' => $r[3], 'label' => $r[4], 'description' => $r[5], 'sort_order' => $r[6], 'updated_at' => $now, 'created_at' => $now]
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('system_settings')) {
            DB::table('system_settings')->whereIn('key', [
                'activation.min_lp', 'activation.window_days', 'activation.max_terminations',
                'pool.percent', 'detachment.threshold', 'detachment.penalty', 'op.penalty',
            ])->delete();
        }
    }
};
