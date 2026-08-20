<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\NotificationController;
use App\Models\Requisite;
use App\Support\RequisiteSla;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Уведомление финменеджера о реквизитах, висящих «на проверке» дольше
 * 1 рабочего дня (см. App\Support\RequisiteSla).
 *
 * Идемпотентно: каждую просроченную запись уведомляем ОДИН раз
 * (overdue_notified_at стампится после отправки). Метка сбрасывается в null
 * при переотправке реквизитов партнёром (ProfileController::setRequisitesPending,
 * ProductController::setupRequisites) — тогда стартует новый цикл проверки.
 *
 * Получатель резолвится по email из config('services.requisites.overdue_notify_email')
 * (по умолчанию — Е. Богданова), поэтому смена ответственного не требует кода.
 */
class NotifyOverdueRequisites extends Command
{
    protected $signature = 'requisites:notify-overdue {--dry : Показать кандидатов без отправки и стампа}';

    protected $description = 'Notify the verification manager about requisites pending manual verification for > 1 business day';

    public function handle(): int
    {
        // Кандидаты: «на проверке» (verified=false, без причины отказа) ЛИБО
        // ИП подтверждён, а партнёр сменил банковский счёт и тот ждёт
        // перепроверки (баг 2026-08-20: такие висели месяцами незамеченными).
        // Не удалённые, ещё не уведомлённые в текущем цикле.
        $listing = app(\App\Services\RequisitesListingService::class);

        $candidates = Requisite::whereNull('deletedAt')
            ->whereNull('overdue_notified_at')
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
            ->get();

        // Для «ждёт только счёт» SLA считаем от смены счёта, а не от давней
        // верификации ИП — иначе строка просрочена уже в момент появления.
        $bankChangedAt = DB::table('bankrequisites')
            ->whereIn('requisites', $candidates->pluck('id')->all() ?: [-1])
            ->whereNull('deletedAt')
            ->whereRaw('verified IS NOT TRUE')
            ->pluck('dateChange', 'requisites');

        $overdue = $candidates->filter(function ($r) use ($bankChangedAt) {
            $submittedAt = ($r->verified ? ($bankChangedAt[$r->id] ?? null) : null)
                ?: $r->dateChange
                ?: $r->createdAt;

            return RequisiteSla::isOverdue(
                $submittedAt instanceof Carbon ? $submittedAt : ($submittedAt ? Carbon::parse($submittedAt) : null)
            );
        })->values();

        if ($overdue->isEmpty()) {
            $this->info('Нет просроченных реквизитов.');

            return self::SUCCESS;
        }

        $recipientId = $this->resolveRecipientId();
        if (! $recipientId) {
            $this->warn('Получатель уведомления не найден (services.requisites.overdue_notify_email).');

            return self::SUCCESS;
        }

        // Имена партнёров — одним запросом (без N+1).
        $names = DB::table('consultant')
            ->whereIn('id', $overdue->pluck('consultant')->filter()->unique()->all())
            ->pluck('personName', 'id');

        foreach ($overdue as $r) {
            $name = $r->consultant ? ($names[$r->consultant] ?? ('#'.$r->id)) : ('#'.$r->id);

            if ($this->option('dry')) {
                $this->line("DRY: {$name} (requisite #{$r->id}) — просрочено");

                continue;
            }

            $what = $r->verified
                ? 'сменил банковский счёт — счёт ждёт перепроверки более 1 рабочего дня (выплаты приостановлены)'
                : 'реквизиты на ручной верификации более 1 рабочего дня';

            NotificationController::create(
                $recipientId,
                'requisites',
                'Реквизиты ждут проверки больше суток',
                "«{$name}» — {$what}.",
                '/admin/requisites?status=pending',
            );

            $r->overdue_notified_at = now();
            $r->save();
        }

        $this->info("Уведомлений отправлено: {$overdue->count()} (получатель WebUser #{$recipientId}).");

        return self::SUCCESS;
    }

    private function resolveRecipientId(): ?int
    {
        // Приоритет — настройка из админки, фолбэк — config/services.
        $email = \App\Models\SystemSetting::value('notifications.requisites_overdue_email')
            ?: config('services.requisites.overdue_notify_email');
        if (! $email) {
            return null;
        }

        // Без фильтра по dateDeleted: это legacy-артефакт Directual, а не
        // блокировка (инцидент 2026-06-05). Берём staff-аккаунт по email.
        $id = DB::table('WebUser')
            ->whereRaw('lower(email) = ?', [mb_strtolower((string) $email)])
            ->orderByRaw('CASE WHEN "dateDeleted" IS NULL THEN 0 ELSE 1 END')
            ->value('id');

        return $id ? (int) $id : null;
    }
}
