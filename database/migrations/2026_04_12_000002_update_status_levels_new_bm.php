<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Обновляет таблицу status_levels по новой бизнес-модели:
 * - Добавляет столбцы personalVolume (ОП) и dsShare (Доля в DS)
 * - Обновляет значения НГП, ОП, Отрыв, Пул в соответствии с новой таблицей квалификаций
 */
return new class extends Migration
{
    public function up(): void
    {
        // Добавляем новые столбцы если их нет
        if (! Schema::hasColumn('status_levels', 'personalVolume')) {
            Schema::table('status_levels', function (Blueprint $table) {
                $table->decimal('personalVolume', 15, 2)->default(0)->after('percent');
            });
        }

        if (! Schema::hasColumn('status_levels', 'dsShare')) {
            Schema::table('status_levels', function (Blueprint $table) {
                $table->decimal('dsShare', 5, 2)->default(0)->after('pool');
            });
        }

        // Обновляем значения по новой БМ
        $levels = [
            1  => ['title' => 'Start',         'percent' => 15, 'groupVolumeCumulative' => 0,       'personalVolume' => 0,      'groupVolume' => 0,      'otrif' => 0,    'pool' => 0, 'dsShare' => 0],
            2  => ['title' => 'Pro',            'percent' => 20, 'groupVolumeCumulative' => 2000,    'personalVolume' => 0,      'groupVolume' => 0,      'otrif' => 0,    'pool' => 0, 'dsShare' => 0],
            3  => ['title' => 'Expert',         'percent' => 25, 'groupVolumeCumulative' => 7000,    'personalVolume' => 1000,   'groupVolume' => 0,      'otrif' => 0,    'pool' => 0, 'dsShare' => 0],
            4  => ['title' => 'FC',             'percent' => 30, 'groupVolumeCumulative' => 30000,   'personalVolume' => 5000,   'groupVolume' => 0,      'otrif' => 0,    'pool' => 0, 'dsShare' => 0],
            5  => ['title' => 'Master FC',      'percent' => 35, 'groupVolumeCumulative' => 150000,  'personalVolume' => 10000,  'groupVolume' => 0,      'otrif' => 0,    'pool' => 0, 'dsShare' => 0],
            6  => ['title' => 'TOP FC',         'percent' => 40, 'groupVolumeCumulative' => 350000,  'personalVolume' => 20000,  'groupVolume' => 0,      'otrif' => 70,   'pool' => 1, 'dsShare' => 0],
            7  => ['title' => 'Silver DS',      'percent' => 45, 'groupVolumeCumulative' => 600000,  'personalVolume' => 40000,  'groupVolume' => 0,      'otrif' => 70,   'pool' => 1, 'dsShare' => 0.25],
            8  => ['title' => 'Gold DS',        'percent' => 49, 'groupVolumeCumulative' => 1000000, 'personalVolume' => 100000, 'groupVolume' => 0,      'otrif' => 70,   'pool' => 1, 'dsShare' => 0.25],
            9  => ['title' => 'Platinum DS',    'percent' => 52, 'groupVolumeCumulative' => 2000000, 'personalVolume' => 200000, 'groupVolume' => 0,      'otrif' => 70,   'pool' => 1, 'dsShare' => 0.25],
            10 => ['title' => 'Co-founder DS',  'percent' => 55, 'groupVolumeCumulative' => 4000000, 'personalVolume' => 400000, 'groupVolume' => 0,      'otrif' => 70,   'pool' => 1, 'dsShare' => 0.25],
        ];

        foreach ($levels as $id => $data) {
            DB::table('status_levels')->where('id', $id)->update($data);
        }
    }

    public function down(): void
    {
        // Откатываем к старым значениям
        $oldLevels = [
            1  => ['title' => 'Старт',       'percent' => 15, 'groupVolumeCumulative' => 0,       'groupVolume' => 0,      'otrif' => 0,    'pool' => 0],
            2  => ['title' => 'Про',          'percent' => 20, 'groupVolumeCumulative' => 2000,    'groupVolume' => 0,      'otrif' => 0,    'pool' => 0],
            3  => ['title' => 'Эксперт',      'percent' => 25, 'groupVolumeCumulative' => 7000,    'groupVolume' => 0,      'otrif' => 0,    'pool' => 0],
            4  => ['title' => 'ФК',           'percent' => 30, 'groupVolumeCumulative' => 50000,   'groupVolume' => 5000,   'otrif' => 0,    'pool' => 0],
            5  => ['title' => 'Мастер ФК',    'percent' => 35, 'groupVolumeCumulative' => 200000,  'groupVolume' => 10000,  'otrif' => 0,    'pool' => 0],
            6  => ['title' => 'Топ ФК',       'percent' => 40, 'groupVolumeCumulative' => 350000,  'groupVolume' => 16000,  'otrif' => 4800, 'pool' => 1],
            7  => ['title' => 'Сильвер ДС',   'percent' => 45, 'groupVolumeCumulative' => 600000,  'groupVolume' => 30000,  'otrif' => 6600, 'pool' => 1],
            8  => ['title' => 'Голд ДС',      'percent' => 49, 'groupVolumeCumulative' => 1000000, 'groupVolume' => 80000,  'otrif' => 9000, 'pool' => 1],
            9  => ['title' => 'Платинум ДС',  'percent' => 52, 'groupVolumeCumulative' => 2000000, 'groupVolume' => 200000, 'otrif' => 24000,'pool' => 1],
            10 => ['title' => 'Кофаундер ДС', 'percent' => 55, 'groupVolumeCumulative' => 4000000, 'groupVolume' => 400000, 'otrif' => 60000,'pool' => 1],
        ];

        foreach ($oldLevels as $id => $data) {
            DB::table('status_levels')->where('id', $id)->update($data);
        }

        Schema::table('status_levels', function (Blueprint $table) {
            if (Schema::hasColumn('status_levels', 'dsShare')) {
                $table->dropColumn('dsShare');
            }
            if (Schema::hasColumn('status_levels', 'personalVolume')) {
                $table->dropColumn('personalVolume');
            }
        });
    }
};
