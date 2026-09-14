<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Входящее сальдо партнёров на начало месяца — единый источник для реестра
 * выплат и бухгалтерского экспорта.
 *
 * Канон взят из кабинета партнёра (MyPaymentsController): цепочка
 * «остаток предыдущего периода + начислено − выплачено», где в начисления
 * входят и ручные корректировки из `other_accruals`.
 *
 * Почему не хватает одного `consultantBalance.remaining`: снимок собирается
 * ТОЛЬКО из `commission` (CommissionCalculator::rebuildBalance) и про
 * `other_accruals` не знает вовсе. Реестр показывал ручную правку живьём в
 * «Прочем» её собственного месяца, но в перенос между месяцами она не
 * попадала — и правка за июнь не гасила сальдо июля. Так вылезло у
 * Лунина (consultant 21): +7 500 «возврат за стратсессию» за 30.06.2026
 * стоял в «Прочих начислениях», а июль всё равно открывался с −7 500,
 * потому что июньский снимок про эти 7 500 не знал.
 *
 * Поэтому к остатку последнего снимка ДО запрошенного месяца добавляем
 * накопленную сумму ручных корректировок за все месяцы до него.
 *
 * ⚠ Если `other_accruals` когда-нибудь начнут агрегироваться в снимок
 * (`accruedNonTransactional`), эту добавку надо убрать — иначе двойной счёт.
 * Тем же правилом связаны PaymentRegistryService (колонка «Сальдо»),
 * PaymentRegistryReport (колонка «Сальдо» в выгрузке) и FinanceReportService
 * («Остаток на начало месяца» в личном отчёте партнёра и его Excel):
 * расхождение между ними уже разводило реестр с бухгалтерией, менять только
 * одно место нельзя.
 *
 * Личный отчёт в этот список попал последним. Он читал один снимок и за
 * август 2026 показал Зарипову (consultant 143) остаток 3 611,01 ₽ против
 * 743,56 ₽ в реестре — ровно на две корректировки: −257,97 ₽ за июнь (двойная
 * комиссия) и −2 609,48 ₽ за июль (удержание).
 */
class IncomingBalance
{
    /**
     * Сальдо на начало месяца `$ym` (формат YYYY-MM) по каждому партнёру.
     *
     * @return array<int, float> consultant id => входящее сальдо
     */
    public static function forMonth(string $ym): array
    {
        return self::compute($ym, null);
    }

    /**
     * Сальдо на начало месяца `$ym` одного партнёра — то же правило, что
     * {@see forMonth()}, без прохода по всей таблице: личный отчёт строится
     * на одного человека.
     */
    public static function forConsultant(int $consultantId, string $ym): float
    {
        return self::compute($ym, $consultantId)[$consultantId] ?? 0.0;
    }

    /**
     * @param  int|null  $only  один партнёр или null — все
     * @return array<int, float> consultant id => входящее сальдо
     */
    private static function compute(string $ym, ?int $only): array
    {
        // Остаток последнего снимка строго ДО запрошенного месяца.
        // DISTINCT ON — один проход вместо N коррелированных подзапросов
        // (прошлая версия отваливалась по таймауту на проде).
        $rows = DB::select(
            'SELECT DISTINCT ON (consultant) consultant, COALESCE(remaining, 0) AS remaining
               FROM "consultantBalance"
              WHERE "dateMonth" < ?' . ($only !== null ? ' AND consultant = ?' : '') . '
              ORDER BY consultant, "dateMonth" DESC',
            $only !== null ? [$ym, $only] : [$ym]
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->consultant] = (float) $row->remaining;
        }

        // Ручные корректировки прошлых месяцев — их в снимке нет.
        // Сравниваем по to_char, а не по границе даты: так же группирует
        // кабинет партнёра, и не надо угадывать таймзону accrual_date.
        $extras = DB::table('other_accruals')
            ->whereRaw("to_char(accrual_date, 'YYYY-MM') < ?", [$ym])
            ->when($only !== null, fn ($q) => $q->where('consultant', $only))
            ->groupBy('consultant')
            ->selectRaw('consultant, SUM(COALESCE(amount, 0)) AS extra')
            ->get();

        foreach ($extras as $extra) {
            $id = (int) $extra->consultant;
            $out[$id] = ($out[$id] ?? 0.0) + (float) $extra->extra;
        }

        return $out;
    }
}
