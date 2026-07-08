<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Настройка свойства МФ/Апфронт для продукта 101 «Инвест платформа Кономи»
 * (все программы поставщика RG.HT, «Робо»).
 *
 * Проблема: продукт 101 имел has_property=false и НОЛЬ строк dsCommission,
 * поэтому ручной ввод не показывал дропдаун «Свойство», а резолвер %ДС
 * (resolveLegacyDsCommission / productRates) не находил ставок. Импорт при
 * этом уже мог нести свойство и %ДС per-row (см. фикс SheetProfiles), но
 * ручной ввод и авто-подстановка %ДС были сломаны.
 *
 * Матрица ставок (подтверждена владельцем 2026-07-07, единая для всех программ):
 *   - МФ (commissionCalcProperty=9)      → 0.5 %  — для ВСЕХ программ
 *   - Апфронт (commissionCalcProperty=10) → 2.0 % — кроме программ-облигаций
 *     (в названии «облигаци»): у них только МФ.
 *
 * Эталон структуры строки — рабочий продукт 31 «Тинькофф портфель»
 * (dsCommission id 454/455): date=2000-01-01, dateFinish=2050-01-01,
 * commissionAbsolute=0, active=true.
 *
 * Идемпотентно: пропускаем (program, property), для которых строка уже есть.
 * dsCommission.id без сиквенса → генерируем от MAX(id) на момент миграции.
 */
return new class extends Migration
{
    private const PRODUCT_ID = 101;
    private const PRODUCT_NAME = 'Инвест платформа Кономи';
    private const PROP_MF = 9;       // МФ
    private const PROP_UPFRONT = 10; // Апфронт

    public function up(): void
    {
        DB::transaction(function () {
            // 1) Показать колонку «Свойство» в ручном вводе для этого продукта.
            DB::table('product')->where('id', self::PRODUCT_ID)->update(['has_property' => true]);

            // 2) Строки dsCommission по матрице.
            $programs = DB::table('program')
                ->where('product', self::PRODUCT_ID)
                ->whereNull('dateDeleted')
                ->orderBy('id')
                ->get(['id', 'name']);

            $nextId = (int) DB::table('dsCommission')->max('id');
            $rows = [];

            foreach ($programs as $p) {
                $isBond = mb_stripos((string) $p->name, 'облигаци') !== false;

                $wanted = [[self::PROP_MF, 0.5]];
                if (! $isBond) {
                    $wanted[] = [self::PROP_UPFRONT, 2.0];
                }

                foreach ($wanted as [$propertyId, $comission]) {
                    // Идемпотентность: не дублируем уже существующую ставку.
                    $exists = DB::table('dsCommission')
                        ->where('product', self::PRODUCT_ID)
                        ->where('program', $p->id)
                        ->where('commissionCalcProperty', $propertyId)
                        ->whereNull('dateDeleted')
                        ->exists();
                    if ($exists) {
                        continue;
                    }

                    $rows[] = [
                        'id' => ++$nextId,
                        'product' => self::PRODUCT_ID,
                        'productName' => self::PRODUCT_NAME,
                        'program' => $p->id,
                        'programName' => $p->name,
                        'commissionCalcProperty' => $propertyId,
                        'comission' => $comission,
                        'commissionAbsolute' => 0,
                        'active' => true,
                        'date' => '2000-01-01 00:00:00',
                        'dateFinish' => '2050-01-01 00:00:00',
                        'dateDeleted' => null,
                        'termContract' => null,
                    ];
                }
            }

            if ($rows) {
                DB::table('dsCommission')->insert($rows);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            // Удаляем только строки, созданные этой миграцией (сигнатура:
            // продукт 101, свойства МФ/Апфронт, характерное окно дат). До
            // миграции у продукта 101 dsCommission не было вовсе.
            DB::table('dsCommission')
                ->where('product', self::PRODUCT_ID)
                ->whereIn('commissionCalcProperty', [self::PROP_MF, self::PROP_UPFRONT])
                ->where('date', '2000-01-01 00:00:00')
                ->where('dateFinish', '2050-01-01 00:00:00')
                ->delete();

            DB::table('product')->where('id', self::PRODUCT_ID)->update(['has_property' => false]);
        });
    }
};
