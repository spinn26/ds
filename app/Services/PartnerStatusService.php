<?php

namespace App\Services;

use App\Enums\PartnerActivity;
use App\Models\Consultant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PartnerStatusService
{
    /**
     * Регистрация нового партнёра: ставит статус «Зарегистрирован»
     * и рассчитывает дедлайн активации (90 дней).
     */
    public function register(Consultant $consultant): void
    {
        $consultant->activity = PartnerActivity::Registered;
        $consultant->activationDeadline = Carbon::now()->addDays(PartnerActivity::ACTIVATION_DAYS);
        $consultant->terminationCount = $consultant->terminationCount ?? 0;
        $consultant->save();

        $this->logStatusChange($consultant, null, PartnerActivity::Registered, 'Регистрация');
    }

    /**
     * Recompute consultant.personalVolume from transaction rows for the current
     * period, and auto-activate if the threshold is crossed. Safe to call after
     * every commission calculation — it only writes when the value changes,
     * and activate() is a no-op for non-Registered partners.
     *
     * Returns true if the partner was activated by this call.
     */
    public function recomputeVolumeAndActivate(int $consultantId): bool
    {
        $consultant = Consultant::find($consultantId);
        if (! $consultant) {
            return false;
        }

        // Sum personalVolume across all non-deleted transactions for contracts
        // owned by this consultant. For Active partners the period resets on
        // yearPeriodEnd, so we only count transactions after the previous
        // period end (= yearPeriodEnd - 1y); for Registered we count since
        // dateCreated (activation window).
        $periodStart = $consultant->activity === PartnerActivity::Active && $consultant->yearPeriodEnd
            ? Carbon::parse($consultant->yearPeriodEnd)->subYear()
            : ($consultant->dateCreated ?: Carbon::now()->subYears(10));

        $lp = (float) DB::table('transaction as t')
            ->join('contract as c', 'c.id', '=', 't.contract')
            ->where('c.consultant', $consultantId)
            ->whereNull('t.deletedAt')
            ->whereNull('c.deletedAt')
            ->where('t.date', '>=', $periodStart)
            ->sum('t.personalVolume');

        if ((float) ($consultant->personalVolume ?? 0) !== $lp) {
            $consultant->personalVolume = $lp;
            $consultant->save();
        }

        return $this->activate($consultant);
    }

    /**
     * Активация партнёра: проверяет ЛП >= 500 и переводит в «Активен».
     * Вызывается при достижении порога ЛП или по событию.
     */
    public function activate(Consultant $consultant): bool
    {
        if ($consultant->activity !== PartnerActivity::Registered) {
            return false;
        }

        $personalVolume = (float) ($consultant->personalVolume ?? 0);
        if ($personalVolume < PartnerActivity::ACTIVATION_POINTS) {
            return false;
        }

        $previousActivity = $consultant->activity;

        DB::transaction(function () use ($consultant) {
            $consultant->activity = PartnerActivity::Active;
            $consultant->active = true;
            $consultant->dateActivity = Carbon::now();
            $consultant->yearPeriodEnd = Carbon::now()->addYear();
            $consultant->save();
        });

        $this->logStatusChange($consultant, $previousActivity, PartnerActivity::Active, 'Активация: ЛП >= 500');

        return true;
    }

    /**
     * Терминация партнёра. Увеличивает счётчик, при 3-й — исключает.
     */
    public function terminate(Consultant $consultant, string $reason = ''): PartnerActivity
    {
        if (! $consultant->canBeTerminated()) {
            return $consultant->activity;
        }

        $previousActivity = $consultant->activity;
        $newCount = ($consultant->terminationCount ?? 0) + 1;

        return DB::transaction(function () use ($consultant, $previousActivity, $newCount, $reason) {
            $consultant->terminationCount = $newCount;
            $consultant->active = false;
            $consultant->dateDeactivity = Carbon::now();

            if ($newCount >= PartnerActivity::MAX_TERMINATIONS) {
                // 3-я терминация → Исключен
                $consultant->activity = PartnerActivity::Excluded;
                $consultant->save();

                $this->logStatusChange(
                    $consultant,
                    $previousActivity,
                    PartnerActivity::Excluded,
                    "Исключение после {$newCount} терминаций. {$reason}"
                );

                return PartnerActivity::Excluded;
            }

            $consultant->activity = PartnerActivity::Terminated;
            $consultant->save();

            $this->logStatusChange(
                $consultant,
                $previousActivity,
                PartnerActivity::Terminated,
                "Терминация #{$newCount}. {$reason}"
            );

            return PartnerActivity::Terminated;
        });
    }

    /**
     * Исключение вручную (за нарушение правил).
     */
    public function exclude(Consultant $consultant, string $reason = ''): void
    {
        $previousActivity = $consultant->activity;

        DB::transaction(function () use ($consultant) {
            $consultant->activity = PartnerActivity::Excluded;
            $consultant->active = false;
            $consultant->dateDeactivity = Carbon::now();
            $consultant->save();
        });

        $this->logStatusChange(
            $consultant,
            $previousActivity,
            PartnerActivity::Excluded,
            "Исключение вручную. {$reason}"
        );
    }

    /**
     * Повторная регистрация терминированного партнёра.
     * Обнуляет баллы, ставит статус «Зарегистрирован».
     */
    public function reRegister(Consultant $consultant): bool
    {
        if ($consultant->activity !== PartnerActivity::Terminated) {
            return false;
        }

        if ($consultant->hasReachedMaxTerminations()) {
            return false;
        }

        $previousActivity = $consultant->activity;

        DB::transaction(function () use ($consultant) {
            $consultant->activity = PartnerActivity::Registered;
            $consultant->personalVolume = 0;
            $consultant->groupVolume = 0;
            $consultant->groupVolumeCumulative = 0;
            $consultant->activationDeadline = Carbon::now()->addDays(PartnerActivity::ACTIVATION_DAYS);
            $consultant->yearPeriodEnd = null;
            $consultant->save();
        });

        $this->logStatusChange(
            $consultant,
            $previousActivity,
            PartnerActivity::Registered,
            "Повторная регистрация (терминаций: {$consultant->terminationCount})"
        );

        return true;
    }

    /**
     * Проверка просроченных дед��айнов — вызывается по крону.
     * Терминирует зарегистрированных, у которых истёк 90-дневный период.
     */
    public function checkExpiredRegistrations(): int
    {
        $expired = Consultant::registered()
            ->whereNotNull('activationDeadline')
            ->where('activationDeadline', '<', Carbon::now())
            ->get();

        $count = 0;
        foreach ($expired as $consultant) {
            $personalVolume = (float) ($consultant->personalVolume ?? 0);
            if ($personalVolume < PartnerActivity::ACTIVATION_POINTS) {
                $this->terminate($consultant, 'Не набрал ЛП=500 за 90 дней');
                $count++;
            }
        }

        return $count;
    }

    /**
     * Проверка годового периода активных партнёров — вызывается по крону.
     * Терминирует активных, у которых за год ЛП < 500.
     */
    public function checkExpiredActivePeriods(): int
    {
        $expired = Consultant::activePartners()
            ->whereNotNull('yearPeriodEnd')
            ->where('yearPeriodEnd', '<', Carbon::now())
            ->get();

        $count = 0;
        foreach ($expired as $consultant) {
            $personalVolume = (float) ($consultant->personalVolume ?? 0);
            if ($personalVolume < PartnerActivity::ACTIVATION_POINTS) {
                $this->terminate($consultant, 'ЛП < 500 за годовой период');
                $count++;
            } else {
                // Продлеваем на следующий год, обнуляем ЛП периода
                $consultant->yearPeriodEnd = Carbon::now()->addYear();
                $consultant->save();
            }
        }

        return $count;
    }

    /**
     * Получить информацию о статусе партнёра для отображения в кабинете.
     */
    public function getStatusInfo(Consultant $consultant): array
    {
        $activity = $consultant->activity ?? PartnerActivity::Registered;

        $info = [
            'activityId' => $activity->value,
            'activityName' => $activity->label(),
            'hasAccess' => $activity->hasAccess(),
            'canInvite' => $activity->canInvite(),
            'terminationCount' => $consultant->terminationCount ?? 0,
            'maxTerminations' => PartnerActivity::MAX_TERMINATIONS,
        ];

        // Обратный отсчёт
        if ($activity === PartnerActivity::Registered && $consultant->activationDeadline) {
            $info['activationDeadline'] = $consultant->activationDeadline->toIso8601String();
            $info['daysRemaining'] = max(0, (int) Carbon::now()->diffInDays($consultant->activationDeadline, false));
            $info['requiredPoints'] = PartnerActivity::ACTIVATION_POINTS;
            $info['currentPoints'] = (float) ($consultant->personalVolume ?? 0);
        }

        if ($activity === PartnerActivity::Active) {
            $endDate = $consultant->yearPeriodEnd;
            // Fallback: if yearPeriodEnd not set, calculate from dateActivity + 1 year
            if (!$endDate && $consultant->dateActivity) {
                $endDate = Carbon::parse($consultant->dateActivity)->addYear();
            }
            if ($endDate) {
                $info['yearPeriodEnd'] = $endDate instanceof Carbon ? $endDate->toIso8601String() : Carbon::parse($endDate)->toIso8601String();
                $info['daysRemaining'] = max(0, (int) Carbon::now()->diffInDays($endDate, false));
                $info['requiredPoints'] = PartnerActivity::ACTIVATION_POINTS;
                $info['currentPoints'] = (float) ($consultant->personalVolume ?? 0);
            }
        }

        return $info;
    }

    private function logStatusChange(
        Consultant $consultant,
        ?PartnerActivity $from,
        PartnerActivity $to,
        string $comment = ''
    ): void {
        DB::table('chageConsultanStatusLog')->insert([
            'consultant' => $consultant->id,
            'dateCreated' => Carbon::now(),
        ]);

        Log::info('Partner status change', [
            'consultant_id' => $consultant->id,
            'from' => $from?->label(),
            'to' => $to->label(),
            'comment' => $comment,
        ]);
    }
}
