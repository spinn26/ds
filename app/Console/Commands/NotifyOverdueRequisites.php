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
        // Кандидаты: «на проверке» (verified=false, без причины отказа),
        // не удалённые, ещё не уведомлённые в текущем цикле.
        $candidates = Requisite::whereNull('deletedAt')
            ->where('verified', false)
            ->where(function ($q) {
                $q->whereNull('rejection_reason')->orWhere('rejection_reason', '');
            })
            ->whereNull('overdue_notified_at')
            ->get();

        $overdue = $candidates->filter(function ($r) {
            $submittedAt = $r->dateChange
                ?: ($r->createdAt ? Carbon::parse($r->createdAt) : null);

            return RequisiteSla::isOverdue($submittedAt);
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

            NotificationController::create(
                $recipientId,
                'requisites',
                'Реквизиты ждут проверки больше суток',
                "«{$name}» — реквизиты на ручной верификации более 1 рабочего дня.",
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
