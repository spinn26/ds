<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * SLA ручной верификации реквизитов партнёра.
 *
 * «Поступление на проверку» = момент последней отправки реквизитов
 * (requisites.dateChange). Норматив — 1 рабочий день: если с момента
 * поступления прошло БОЛЬШЕ одних рабочих суток (выходные не считаются),
 * запись считается просроченной → плашка в списке + уведомление
 * финменеджеру (см. App\Console\Commands\NotifyOverdueRequisites).
 *
 * NB: государственные праздники здесь не учитываются (нет производственного
 * календаря в проекте) — только Сб/Вс. При необходимости — отдельная задача.
 */
class RequisiteSla
{
    /** Дедлайн = поступление + 1 рабочий день (с пропуском выходных). */
    public static function deadline(CarbonInterface $submittedAt): Carbon
    {
        $deadline = Carbon::instance($submittedAt)->copy()->addDay();
        while ($deadline->isWeekend()) {
            $deadline->addDay();
        }

        return $deadline;
    }

    /** Прошло ли больше одних рабочих суток с момента поступления. */
    public static function isOverdue(?CarbonInterface $submittedAt): bool
    {
        if (! $submittedAt) {
            return false;
        }

        return Carbon::now()->greaterThan(self::deadline($submittedAt));
    }
}
