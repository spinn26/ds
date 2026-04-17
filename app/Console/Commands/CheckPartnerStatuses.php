<?php

namespace App\Console\Commands;

use App\Enums\PartnerActivity;
use App\Services\PartnerStatusService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckPartnerStatuses extends Command
{
    protected $signature = 'partners:check-statuses';
    protected $description = 'Проверить дедлайны активации и годовые периоды, автоматически терминировать/уведомить';

    public function handle(PartnerStatusService $statusService): int
    {
        $this->info('Проверка статусов партнёров...');

        // 0. Safety sweep: Registered partners that already crossed 500 LP
        //    but weren't auto-activated (e.g. import ran before
        //    CommissionCalculator wired in the trigger).
        $activated = $this->sweepRegisteredToActive($statusService);
        $this->info("Авто-активировано: {$activated}");

        // 1. Зарегистрированные: 90 дней истекло → терминация
        $expiredRegistrations = $statusService->checkExpiredRegistrations();
        $this->info("Терминировано зарегистрированных (90 дней): {$expiredRegistrations}");

        // 2. Активные: год истёк, ЛП < 500 → терминация
        $expiredActive = $statusService->checkExpiredActivePeriods();
        $this->info("Терминировано активных (год): {$expiredActive}");

        // 3. Уведомления: за 2 месяца до окончания годового периода
        $this->sendUpcomingExpirationWarnings();

        // 4. Уведомления: за 1 месяц до терминации зарегистрированных
        $this->sendRegistrationDeadlineWarnings();

        // 5. 3-я терминация → исключение
        $this->checkTripleTerminations();

        $this->info('Проверка завершена.');
        return 0;
    }

    /**
     * For every Registered partner whose LP may have already crossed the
     * activation threshold, recompute the LP from transactions and activate.
     * Safety net for partners missed by the real-time trigger in
     * CommissionCalculator.
     */
    private function sweepRegisteredToActive(PartnerStatusService $statusService): int
    {
        $registeredIds = DB::table('consultant')
            ->where('activity', PartnerActivity::Registered->value)
            ->whereNull('dateDeleted')
            ->pluck('id');

        $count = 0;
        foreach ($registeredIds as $id) {
            if ($statusService->recomputeVolumeAndActivate((int) $id)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Уведомления за 2 месяца до конца годового периода (активные, ЛП < 500).
     */
    private function sendUpcomingExpirationWarnings(): void
    {
        $twoMonthsFromNow = Carbon::now()->addMonths(2);

        $partners = DB::table('consultant')
            ->where('activity', PartnerActivity::Active->value)
            ->whereNotNull('yearPeriodEnd')
            ->where('yearPeriodEnd', '<=', $twoMonthsFromNow)
            ->where('yearPeriodEnd', '>', Carbon::now())
            ->where(function ($q) {
                $q->where('personalVolume', '<', PartnerActivity::ACTIVATION_POINTS)
                  ->orWhereNull('personalVolume');
            })
            ->get();

        foreach ($partners as $p) {
            $daysLeft = Carbon::now()->diffInDays(Carbon::parse($p->yearPeriodEnd), false);

            // Создать уведомление через коммуникацию
            try {
                DB::table('platformCommunication')->insert([
                    'consultant' => $p->id,
                    'category' => 2, // Техподдержка
                    'message' => "Внимание! До окончания годового периода осталось {$daysLeft} дней. "
                        . "Для сохранения статуса «Активен» необходимо набрать ЛП = "
                        . PartnerActivity::ACTIVATION_POINTS . " баллов. "
                        . "Текущий ЛП: " . round((float) ($p->personalVolume ?? 0), 2),
                    'date' => now(),
                    'direction' => 'ds2p',
                    'read' => false,
                ]);
            } catch (\Exception $e) {
                Log::warning("Failed to send warning to consultant {$p->id}: " . $e->getMessage());
            }
        }

        $this->info("Уведомлений о скором истечении (2 мес): " . $partners->count());
    }

    /**
     * Уведомления за 1 месяц до конца 90-дневного периода.
     */
    private function sendRegistrationDeadlineWarnings(): void
    {
        $oneMonthFromNow = Carbon::now()->addMonth();

        $partners = DB::table('consultant')
            ->where('activity', PartnerActivity::Registered->value)
            ->whereNotNull('activationDeadline')
            ->where('activationDeadline', '<=', $oneMonthFromNow)
            ->where('activationDeadline', '>', Carbon::now())
            ->where(function ($q) {
                $q->where('personalVolume', '<', PartnerActivity::ACTIVATION_POINTS)
                  ->orWhereNull('personalVolume');
            })
            ->get();

        foreach ($partners as $p) {
            $daysLeft = Carbon::now()->diffInDays(Carbon::parse($p->activationDeadline), false);

            try {
                DB::table('platformCommunication')->insert([
                    'consultant' => $p->id,
                    'category' => 2,
                    'message' => "Внимание! До окончания срока активации осталось {$daysLeft} дней. "
                        . "Необходимо набрать ЛП = " . PartnerActivity::ACTIVATION_POINTS . " баллов. "
                        . "Текущий ЛП: " . round((float) ($p->personalVolume ?? 0), 2),
                    'date' => now(),
                    'direction' => 'ds2p',
                    'read' => false,
                ]);
            } catch (\Exception $e) {
                Log::warning("Failed to send registration warning to consultant {$p->id}: " . $e->getMessage());
            }
        }

        $this->info("Уведомлений о сроке активации (1 мес): " . $partners->count());
    }

    /**
     * Проверить 3-ю терминацию → исключить.
     */
    private function checkTripleTerminations(): void
    {
        $count = DB::table('consultant')
            ->where('activity', PartnerActivity::Terminated->value)
            ->where('terminationCount', '>=', PartnerActivity::MAX_TERMINATIONS)
            ->whereNull('dateDeleted')
            ->count();

        if ($count > 0) {
            DB::table('consultant')
                ->where('activity', PartnerActivity::Terminated->value)
                ->where('terminationCount', '>=', PartnerActivity::MAX_TERMINATIONS)
                ->whereNull('dateDeleted')
                ->update([
                    'activity' => PartnerActivity::Excluded->value,
                    'active' => false,
                ]);

            $this->info("Исключено после 3-х терминаций: {$count}");
        }
    }
}
