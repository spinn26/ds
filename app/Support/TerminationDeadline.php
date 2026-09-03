<?php

namespace App\Support;

use App\Enums\PartnerActivity;
use Illuminate\Support\Carbon;

/**
 * Плановая дата терминации партнёра — ОДНО определение на всё приложение:
 * колонка «Будет терминирован», фильтр «План. терминация», сортировка по ней
 * и отчёт по статусам.
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
 * Сроков, ведущих к терминации, два — как в рассылке
 * partners:notify-termination-soon (App\Console\Commands\NotifyTerminationSoon):
 *
 *   «Активен»          — конец годового периода. Источник истины
 *                        consultant.yearPeriodEnd: его выставляет активация и
 *                        двигает годовой раннер (PartnerStatusService),
 *                        поэтому со второго года dateActivity + 1 год лежит
 *                        уже в прошлом. Фоллбэк на dateActivity + 1 год — для
 *                        строк без периода, тот же, что в
 *                        PartnerStatusService::yearPeriodInfo().
 *
 *   «Зарегистрирован»  — окно набора ЛП: consultant.activationDeadline (его
 *                        могли продлить вручную), фоллбэк — dateCreated +
 *                        activation.window_days. Та же пара, что в
 *                        PartnerListingService::statusChangeDate().
 *
 * У терминированных и исключённых планового дедлайна нет: он уже наступил.
 */
final class TerminationDeadline
{
    /** consultant.activity: «Активен» и «Зарегистрирован». */
    private const ACTIVE = 1;
    private const REGISTERED = 4;

    /**
     * SQL-выражение дедлайна для запросов по таблице consultant (без алиаса).
     * Для статусов без дедлайна отдаёт NULL — такие строки не проходят ни
     * `>=`, ни `<=`, то есть фильтр по плановой дате их отсекает.
     *
     * Окно активации — настройка (activation.window_days), поэтому выражение
     * собирается методом, а не константой. Значение приводится к int: оно
     * попадает в SQL текстом.
     */
    public static function sql(): string
    {
        $days = (int) PartnerActivity::activationDays();

        return '(CASE
            WHEN consultant.activity = ' . self::ACTIVE . '
                THEN COALESCE(consultant."yearPeriodEnd",
                              consultant."dateActivity" + interval \'1 year\')
            WHEN consultant.activity = ' . self::REGISTERED . '
                THEN COALESCE(consultant."activationDeadline",
                              consultant."dateCreated" + make_interval(days => ' . $days . '))
        END)';
    }

    /**
     * То же правило в PHP — для строк, уже вытащенных из consultant.
     * Возвращает дату в формате Y-m-d либо null.
     *
     * Принимает значения, а не строку целиком: stdClass из DB::table() для
     * анализатора — просто object, и обращение к его полям здесь было бы
     * ошибкой уровня 5 (в app/Support такие обращения не заглушены).
     *
     * $activationDays передаётся снаружи и резолвится один раз на выборку —
     * иначе настройка читалась бы на каждой строке списка.
     *
     * @param mixed $yearPeriodEnd consultant.yearPeriodEnd
     * @param mixed $dateActivity consultant.dateActivity
     * @param mixed $activationDeadline consultant.activationDeadline
     * @param mixed $dateCreated consultant.dateCreated
     */
    public static function resolve(
        mixed $activity,
        mixed $yearPeriodEnd,
        mixed $dateActivity,
        mixed $activationDeadline = null,
        mixed $dateCreated = null,
        ?int $activationDays = null,
    ): ?string {
        $end = match ((int) $activity) {
            self::ACTIVE => $yearPeriodEnd
                ?: ($dateActivity ? Carbon::parse($dateActivity)->addYear() : null),
            self::REGISTERED => $activationDeadline
                ?: ($dateCreated
                    ? Carbon::parse($dateCreated)
                        ->addDays($activationDays ?? PartnerActivity::activationDays())
                    : null),
            default => null,
        };

        return $end ? Carbon::parse($end)->format('Y-m-d') : null;
    }
}
