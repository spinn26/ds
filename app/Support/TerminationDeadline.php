<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Плановая дата терминации активного партнёра — ОДНО определение на всё
 * приложение: колонка «Будет терминирован», фильтр «План. терминация»,
 * сортировка по ней и отчёт по статусам.
 *
 * Per spec ✅Статусы партнеров.md §1 и §2 col.5 это ВЫЧИСЛЯЕМЫЙ дедлайн, а не
 * колонка в базе. До 03.09.2026 каждая точка считала его по-своему:
 *   - список показывал dateActivity + 1 год;
 *   - фильтр ходил в consultant.dateDeterministicPlan;
 *   - отчёт печатал ту же dateDeterministicPlan.
 * dateDeterministicPlan — это окно активации из самоактивации
 * (AuthController::activate, now + activation.window_days), к годовому
 * дедлайну отношения не имеющее. Отсюда и жалоба: фильтр «ноябрь 2026»
 * отбирал строки по одному полю, а колонка рисовала другое — июнь 2027.
 *
 * Источник истины — consultant.yearPeriodEnd: его выставляет активация и
 * двигает годовой раннер (PartnerStatusService), поэтому со второго года
 * dateActivity + 1 год лежит уже в прошлом. Фоллбэк на dateActivity + 1 год
 * оставлен для строк без проставленного периода — тот же фоллбэк, что и в
 * PartnerStatusService::yearPeriodInfo().
 *
 * Партнёры вне статуса «Активен» планового дедлайна не имеют: у
 * «Зарегистрирован» свой срок — activationDeadline (окно набора ЛП), и в эту
 * колонку он намеренно не подмешивается.
 */
final class TerminationDeadline
{
    /** consultant.activity = «Активен». */
    private const ACTIVE = 1;

    /**
     * SQL-выражение дедлайна для запросов по таблице consultant (без алиаса).
     * Отдаёт NULL для всех, кроме активных, — такие строки не попадают ни под
     * `>=`, ни под `<=`, то есть фильтр по плановой дате их отсекает.
     */
    public const SQL = '(CASE WHEN consultant.activity = '.self::ACTIVE.'
        THEN COALESCE(consultant."yearPeriodEnd", consultant."dateActivity" + interval \'1 year\')
        END)';

    /**
     * То же правило в PHP — для строк, уже вытащенных из consultant.
     * Возвращает дату в формате Y-m-d либо null.
     *
     * Принимает значения, а не строку целиком: stdClass из DB::table() для
     * анализатора — просто object, и обращение к его полям здесь было бы
     * ошибкой уровня 5 (в app/Support такие обращения не заглушены).
     *
     * @param mixed $yearPeriodEnd consultant.yearPeriodEnd
     * @param mixed $dateActivity consultant.dateActivity
     */
    public static function resolve(mixed $activity, mixed $yearPeriodEnd, mixed $dateActivity): ?string
    {
        if ((int) $activity !== self::ACTIVE) {
            return null;
        }

        $end = $yearPeriodEnd
            ?: ($dateActivity ? Carbon::parse($dateActivity)->addYear() : null);

        return $end ? Carbon::parse($end)->format('Y-m-d') : null;
    }
}
