<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Робо-программы «ТВ Рынок облигации» (1626) и «Финам рынок облигации» (1628)
 * имели в тарифной сетке только строку «МФ» (0,5%) — строки «Апфронт» не было.
 * Из-за этого при ручном заведении транзакции дропдаун «Свойство» не появлялся
 * (фронт рисует select только когда доступных свойств > 1), и оператор не мог
 * выбрать Апфронт.
 *
 * У всех соседних робо-программ того же продукта (101 «Инвест платформа
 * Кономи») пара тарифов одинакова: МФ 0,5% + Апфронт 2%. Достраиваем недостающие
 * строки по образцу «ТВ Рынок» (1625) / «Финам рынок» (1627).
 *
 * Идемпотентна: вставляет только если строки ещё нет.
 */
return new class extends Migration
{
    /** commissionCalcProperty.id = 10 — «Апфронт». */
    private const PROP_UPFRONT = 10;

    private const RATE = 2;

    /** program.id => programName (для денормализованной колонки dsCommission). */
    private const PROGRAMS = [
        1626 => 'ТВ Рынок облигации',
        1628 => 'Финам рынок облигации',
    ];

    public function up(): void
    {
        foreach (self::PROGRAMS as $programId => $programName) {
            $exists = DB::table('dsCommission')
                ->where('program', $programId)
                ->where('commissionCalcProperty', self::PROP_UPFRONT)
                ->whereNull('dateDeleted')
                ->exists();
            if ($exists) {
                continue;
            }

            // Продукт/окно действия берём у самой программы (строка «МФ»),
            // чтобы не хардкодить и не разъехаться с legacy-данными.
            $mf = DB::table('dsCommission')
                ->where('program', $programId)
                ->whereNull('dateDeleted')
                ->orderBy('id')
                ->first();
            if (! $mf) {
                continue;
            }

            // Legacy-таблица из Directual: у id нет сиквенса/дефолта, значение
            // задаём явно.
            $nextId = (int) DB::table('dsCommission')->max('id') + 1;

            DB::table('dsCommission')->insert([
                'id' => $nextId,
                'product' => $mf->product,
                'program' => $programId,
                'programName' => $programName,
                'commissionCalcProperty' => self::PROP_UPFRONT,
                'comission' => self::RATE,
                'termContract' => $mf->termContract,
                'date' => $mf->date,
                'dateFinish' => $mf->dateFinish,
                'active' => true,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('dsCommission')
            ->whereIn('program', array_keys(self::PROGRAMS))
            ->where('commissionCalcProperty', self::PROP_UPFRONT)
            ->where('comission', self::RATE)
            ->delete();
    }
};
