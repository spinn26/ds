<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\NotificationController;
use App\Services\RequisitesListingService;
use App\Support\RequisiteSla;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Утренний дайджест «что ждёт действий финменеджера» (роль calculations).
 *
 * Отличие от App\Console\Commands\NotifyOverdueRequisites: тот шлёт разовое
 * уведомление на КАЖДЫЙ просроченный реквизит и после отправки стампит
 * overdue_notified_at — то есть напоминает ОДИН раз. Если задачу не разобрали,
 * она больше о себе не заявляет. Этот дайджест наоборот: пока работа висит,
 * он напоминает каждое утро, и одним сообщением, а не пачкой.
 *
 * Пункты — только то, что решается руками финменеджера:
 *   • курсы валют за текущий месяц не заведены (заводятся ТОЛЬКО вручную:
 *     авто-копия сломана с апреля 2026, вместо неё кнопка «Добавить курсы»);
 *   • реквизиты ждут проверки дольше 1 рабочего дня (включая случай, когда
 *     ИП подтверждён, а партнёр сменил банковский счёт — см. pendingScope);
 *   • заявки на смену банковских реквизитов висят дольше 1 рабочего дня;
 *   • выплаты приостановлены вручную дольше 14 дней — похоже, забыли снять.
 *
 * Пусто → молчим. Уведомление одно, со ссылкой на первый непустой раздел.
 *
 *   php artisan alerts:pending-actions          # разослать
 *   php artisan alerts:pending-actions --dry    # показать, ничего не слать
 */
class NotifyPendingActions extends Command
{
    protected $signature = 'alerts:pending-actions {--dry : показать дайджест без отправки}';

    protected $description = 'Утренний дайджест задач финменеджера: реквизиты, заявки, курсы, приостановки';

    /** Кому: роль в WebUser.role (CSV, матчится по ilike). */
    private const ROLE = 'calculations';

    /** Приостановка выплат дольше этого срока выглядит забытой. */
    private const SUSPEND_STALE_DAYS = 14;

    public function handle(RequisitesListingService $listing): int
    {
        $items = array_values(array_filter([
            $this->currencyRates(),
            $this->requisitesWaiting($listing),
            $this->bankChangeRequests(),
            $this->staleSuspensions(),
        ]));

        if (! $items) {
            $this->info('Задач, требующих действий, нет.');

            return self::SUCCESS;
        }

        $message = collect($items)->map(fn ($i) => '• '.$i['text'])->implode("\n");

        if ($this->option('dry')) {
            $this->line($message);
            $this->comment('(--dry: не отправлено)');

            return self::SUCCESS;
        }

        $sent = NotificationController::notifyRoles(
            [self::ROLE],
            'requisites',
            'Задачи, ожидающие вашего решения',
            $message,
            $items[0]['link'],
        );

        $this->info('Дайджест отправлен: пунктов '.count($items).", получателей {$sent}.");

        return self::SUCCESS;
    }

    /**
     * Курсы валют за текущий месяц. Заводятся вручную; без них валютные
     * контракты месяца считаются не по чему.
     *
     * @return array{text: string, link: string}|null
     */
    private function currencyRates(): ?array
    {
        $exists = DB::table('currencyRate')
            ->whereRaw("date_trunc('month', date) = date_trunc('month', now())")
            ->exists();

        if ($exists) {
            return null;
        }

        return [
            'text' => 'Курсы валют за '.Carbon::now()->translatedFormat('F Y').' не заведены — расчёты по валютным контрактам месяца встанут.',
            'link' => '/manage/currencies',
        ];
    }

    /**
     * Реквизиты «на проверке» дольше 1 рабочего дня. Считаем тем же способом,
     * что и список /admin/requisites: ИП не подтверждён ЛИБО подтверждён, но
     * банковский счёт сменили и он ждёт перепроверки.
     *
     * @return array{text: string, link: string}|null
     */
    private function requisitesWaiting(RequisitesListingService $listing): ?array
    {
        // ⚠ Без алиаса таблицы: whereBankPending коррелирует подзапрос по
        // "requisites"."id", и под алиасом ссылка не разрешится.
        $rows = DB::table('requisites')
            ->whereNull('deletedAt')
            ->where(function ($outer) use ($listing) {
                $outer->where(function ($q) {
                    $q->where('verified', false)->where(function ($q2) {
                        $q2->whereNull('rejection_reason')->orWhere('rejection_reason', '');
                    });
                })->orWhere(function ($q) use ($listing) {
                    $q->where('verified', true);
                    $listing->whereBankPending($q);
                });
            })
            ->select('id', 'verified', 'dateChange')
            ->get();

        // Для «ждёт только счёт» отсчёт SLA идёт от смены счёта.
        $bankChangedAt = DB::table('bankrequisites')
            ->whereIn('requisites', $rows->pluck('id')->all() ?: [-1])
            ->whereNull('deletedAt')
            ->whereRaw('verified IS NOT TRUE')
            ->pluck('dateChange', 'requisites');

        $overdue = $rows->filter(function ($r) use ($bankChangedAt) {
            $at = ($r->verified ? ($bankChangedAt[$r->id] ?? null) : null) ?: $r->dateChange;

            return $at && RequisiteSla::isOverdue(Carbon::parse($at));
        })->count();

        if (! $overdue) {
            return null;
        }

        return [
            'text' => "Реквизиты ждут проверки дольше 1 рабочего дня: {$overdue}. Пока не подтвердите — выплаты партнёру закрыты.",
            'link' => '/admin/requisites?status=pending',
        ];
    }

    /** @return array{text: string, link: string}|null */
    private function bankChangeRequests(): ?array
    {
        $count = DB::table('bank_requisite_change_requests')
            ->where('status', 'pending')
            ->get(['created_at'])
            ->filter(fn ($r) => $r->created_at && RequisiteSla::isOverdue(Carbon::parse($r->created_at)))
            ->count();

        if (! $count) {
            return null;
        }

        return [
            'text' => "Заявки на смену банковских реквизитов без ответа дольше 1 рабочего дня: {$count}.",
            'link' => '/manage/bank-changes',
        ];
    }

    /** @return array{text: string, link: string}|null */
    private function staleSuspensions(): ?array
    {
        $pendingChange = DB::table('bank_requisite_change_requests')
            ->where('status', 'pending')->distinct()->pluck('consultant')->all();

        $count = DB::table('consultant')
            ->whereNull('dateDeleted')
            ->where('payments_suspended', true)
            ->whereNotNull('payments_suspended_at')
            ->where('payments_suspended_at', '<', now()->subDays(self::SUSPEND_STALE_DAYS))
            // Приостановка по активной заявке — не «забытая», она снимется
            // при разборе заявки.
            ->when($pendingChange, fn ($q) => $q->whereNotIn('id', $pendingChange))
            ->count();

        if (! $count) {
            return null;
        }

        return [
            'text' => 'Выплаты приостановлены вручную дольше '
                .self::SUSPEND_STALE_DAYS." дней у партнёров: {$count}. Похоже, снять забыли.",
            'link' => '/admin/requisites?suspend=manual',
        ];
    }
}
