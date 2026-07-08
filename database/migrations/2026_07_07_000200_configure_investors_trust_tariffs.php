<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Тарифы %ДС для продукта 102 «Investors Trust» (унифицированный UI-продукт).
 *
 * Проблема: контракты Траста сидят на product=102 / программах 1631–1638,
 * у которых НЕТ строк dsCommission. Реальная прогрессивная матрица тарифов
 * (срок × год выплаты КВ) исторически лежит под legacy-продуктами
 * 12/13/14/18/19/20/86 с ДРУГИМИ id программ (Evolution → prog 747 «EVO» и
 * т.д.). Резолвер (resolveLegacyDsCommission / productRates) ищет по
 * contract.program (1634…) → ничего не находит → ручной ввод даёт 100% и
 * пустой «Изменить». (Импорт Траста берёт %ДС из листа и от этого не зависит.)
 *
 * Решение: зеркалим авторитетные строки тарифов на программы продукта 102
 * (1:1 по названию инструмента) и выставляем флаги продукта как у Evolution
 * (has_term + has_year_kv — тариф зависит от срока и года выплаты).
 *
 * Идемпотентно: пропускаем (program, term, property), для которых строка уже
 * есть. dsCommission.id без сиквенса → id от MAX(id).
 *
 * ⚠ Это КОПИЯ существующих ставок (не новые значения). Источник — те же
 * продукты 12/13/14/18/19/20/86, что и раньше; расчёт денег для уже
 * посчитанных Trust-транзакций не меняется (у них %ДС уже сохранён в самой
 * транзакции). Влияет только на ручной ввод и авто-резолв для новых.
 */
return new class extends Migration
{
    private const PRODUCT_ID = 102;
    private const PRODUCT_NAME = 'Investors Trust';

    /** target program (product 102) => [source product, source program]. */
    private const MAP = [
        1631 => [18, 128],  // Access Portfolio 5000 ← ACS5000
        1632 => [18, 129],  // Access Portfolio 8000 ← ACS8000
        1633 => [13, 772],  // Assurance SPX          ← SPX-X
        1634 => [12, 747],  // Evolution              ← EVO
        1635 => [86, 709],  // Fixed Income 03        ← FIFR03
        1636 => [14, 787],  // MSCI Index             ← MSCI-X
        1637 => [20, 131],  // Platinum PLUS          ← PLATP
        1638 => [19, 130],  // Platinum Select        ← PLATS
    ];

    public function up(): void
    {
        DB::transaction(function () {
            // Флаги как у Evolution (product 12): тариф зависит от срока
            // контракта и года выплаты КВ. Показывает срок/«Год КВ» в вводе.
            DB::table('product')->where('id', self::PRODUCT_ID)->update([
                'has_property' => true,
                'has_term' => true,
                'has_year_kv' => true,
            ]);

            $nextId = (int) DB::table('dsCommission')->max('id');
            $insert = [];

            foreach (self::MAP as $targetProgram => [$srcProduct, $srcProgram]) {
                $targetName = DB::table('program')->where('id', $targetProgram)->value('name');

                $src = DB::table('dsCommission')
                    ->where('product', $srcProduct)
                    ->where('program', $srcProgram)
                    ->where('active', true)
                    ->whereNull('dateDeleted')
                    ->get([
                        'termContract', 'commissionCalcProperty', 'comission',
                        'commissionAbsolute', 'date', 'dateFinish',
                    ]);

                foreach ($src as $r) {
                    $exists = DB::table('dsCommission')
                        ->where('product', self::PRODUCT_ID)
                        ->where('program', $targetProgram)
                        ->where('commissionCalcProperty', $r->commissionCalcProperty)
                        ->when($r->termContract !== null,
                            fn ($q) => $q->where('termContract', $r->termContract),
                            fn ($q) => $q->whereNull('termContract'))
                        ->whereNull('dateDeleted')
                        ->exists();
                    if ($exists) {
                        continue;
                    }

                    $insert[] = [
                        'id' => ++$nextId,
                        'product' => self::PRODUCT_ID,
                        'productName' => self::PRODUCT_NAME,
                        'program' => $targetProgram,
                        'programName' => $targetName,
                        'termContract' => $r->termContract,
                        'commissionCalcProperty' => $r->commissionCalcProperty,
                        'comission' => $r->comission,
                        'commissionAbsolute' => $r->commissionAbsolute ?? 0,
                        'active' => true,
                        'date' => $r->date ?? '2000-01-01 00:00:00',
                        'dateFinish' => $r->dateFinish ?? '2050-01-01 00:00:00',
                        'dateDeleted' => null,
                    ];
                }
            }

            if ($insert) {
                DB::table('dsCommission')->insert($insert);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            // До миграции у продукта 102 dsCommission не было — чистим по product.
            DB::table('dsCommission')->where('product', self::PRODUCT_ID)->delete();

            DB::table('product')->where('id', self::PRODUCT_ID)->update([
                'has_property' => false,
                'has_term' => false,
                'has_year_kv' => false,
            ]);
        });
    }
};
