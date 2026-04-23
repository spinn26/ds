<?php

namespace App\Services;

use App\Enums\PartnerActivity;
use App\Http\Controllers\Api\NotificationController;
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
     * Сгенерировать уникальный participantCode. 6 символов A-Z0-9,
     * исключая легко путающиеся (0, O, 1, I, L). Проверяется уникальность.
     */
    private function generateUniqueCode(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $exists = DB::table('consultant')->where('participantCode', $code)->exists();
            if (! $exists) return $code;
        }
        throw new \RuntimeException('Не удалось сгенерировать уникальный participantCode за 20 попыток');
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

            // Генерируем participantCode если ещё нет. Код нужен активному
            // партнёру для выдачи реф-ссылки; без него /register?ref=... не
            // сработает. 6 символов A-Z0-9 даёт 2.1 млрд комбинаций —
            // collision-проверка защищает даже при экстремально больших
            // выборках.
            if (empty($consultant->participantCode)) {
                $consultant->participantCode = $this->generateUniqueCode();
            }

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

    /**
     * Логируем смену статуса в трёх местах:
     *   1. Spatie activity_log — единый системный аудит (искать по subject).
     *   2. Legacy `chageConsultanStatusLog` — для старых отчётов. После
     *      миграции 000080 у нас есть from/to/comment/source/changed_by.
     *   3. Laravel logs — для инцидент-разборок.
     */
    private function logStatusChange(
        Consultant $consultant,
        ?PartnerActivity $from,
        PartnerActivity $to,
        string $comment = '',
        string $source = 'system',
    ): void {
        $changedBy = auth()->id();

        // 1. Spatie activity_log — это «нормальный» аудит.
        if (function_exists('activity')) {
            try {
                activity('partner_status')
                    ->performedOn($consultant)
                    ->causedBy($changedBy ? \App\Models\User::find($changedBy) : null)
                    ->withProperties([
                        'from' => $from?->value,
                        'from_label' => $from?->label(),
                        'to' => $to->value,
                        'to_label' => $to->label(),
                        'comment' => $comment,
                        'source' => $source,
                    ])
                    ->log(sprintf('%s → %s', $from?->label() ?? '—', $to->label()));
            } catch (\Throwable $e) {
                Log::warning('activity() failed', ['error' => $e->getMessage()]);
            }
        }

        // 2. Legacy таблица — пишем новые поля если миграция 000080 применилась.
        $row = [
            'consultant' => $consultant->id,
            'dateCreated' => Carbon::now(),
            'webUser' => $consultant->webUser,
        ];
        if (\Illuminate\Support\Facades\Schema::hasColumn('chageConsultanStatusLog', 'from_status')) {
            $row['from_status'] = $from?->label();
            $row['to_status'] = $to->label();
            $row['comment'] = $comment ?: null;
            $row['source'] = $source;
            $row['changed_by'] = $changedBy;
        }
        DB::table('chageConsultanStatusLog')->insert($row);

        // 3. Laravel log для разборок.
        Log::info('Partner status change', [
            'consultant_id' => $consultant->id,
            'from' => $from?->label(),
            'to' => $to->label(),
            'comment' => $comment,
            'source' => $source,
            'changed_by' => $changedBy,
        ]);

        if ($from !== null && $consultant->webUser) {
            $this->notifyStatusChange($consultant->webUser, $to, $comment);
        }
    }

    private function notifyStatusChange(int $userId, PartnerActivity $to, string $comment): void
    {
        [$title, $message] = match ($to) {
            PartnerActivity::Active => [
                'Статус: Активен',
                'Партнёрский аккаунт активирован. Теперь вам доступны реферальные ссылки.',
            ],
            PartnerActivity::Terminated => [
                'Статус: Терминация',
                $comment ?: 'Начислена терминация. Подробности — в личном кабинете.',
            ],
            PartnerActivity::Excluded => [
                'Статус: Исключён',
                $comment ?: 'Аккаунт переведён в статус «Исключён».',
            ],
            PartnerActivity::Registered => [
                'Статус: Зарегистрирован',
                $comment ?: 'Статус возвращён к «Зарегистрирован».',
            ],
        };

        NotificationController::create($userId, 'status', $title, $message, '/profile');
    }
}
