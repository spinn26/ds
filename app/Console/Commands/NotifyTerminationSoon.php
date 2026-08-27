<?php

namespace App\Console\Commands;

use App\Enums\PartnerActivity;
use App\Http\Controllers\Api\NotificationController;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Предупреждение партнёру за N дней до терминации — в кабинет и в Telegram.
 *
 * ⚠ Команда НИЧЕГО НЕ СЧИТАЕТ и не меняет статусы: только рассылает. Поэтому
 * она стоит в расписании, хотя авто-пересчёты на платформе отключены
 * (см. routes/console.php) — запрет касается расчётов, а не уведомлений.
 * Терминирует по-прежнему `partners:check-statuses` по кнопке.
 *
 * Почему не переиспользована готовая рассылка в `partners:check-statuses`
 * (`sendRegistrationDeadlineWarnings`): она пишет в `platformCommunication`,
 * а не в `notifications`, поэтому В TELEGRAM НЕ УХОДИТ ВООБЩЕ. И условие там
 * «дедлайн в пределах месяца» без отметки об отправке — при ежедневном запуске
 * это тридцать одинаковых сообщений подряд каждому.
 *
 * Терминация наступает по двум разным срокам, оба ведут к расторжению договора:
 *   • «Зарегистрирован» — не набрал ЛП за окно активации (`activationDeadline`);
 *   • «Активен» — не набрал ЛП за годовой период (`yearPeriodEnd`).
 * Предупреждаем по обоим.
 */
class NotifyTerminationSoon extends Command
{
    protected $signature = 'partners:notify-termination-soon
        {--days=30 : за сколько дней до срока предупреждать}
        {--dry-run : показать кому уйдёт, ничего не отправляя}';

    protected $description = 'Предупредить партнёров о скорой терминации (кабинет + Telegram)';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $dry = (bool) $this->option('dry-run');
        $points = PartnerActivity::activationPoints();

        $today = Carbon::today();
        $until = $today->copy()->addDays($days);

        $rows = DB::table('consultant as c')
            ->join('WebUser as wu', 'wu.id', '=', 'c.webUser')
            ->whereNull('c.dateDeleted')
            ->where(function ($q) {
                $q->where('c.personalVolume', '<', PartnerActivity::activationPoints())
                  ->orWhereNull('c.personalVolume');
            })
            ->where(function ($q) {
                // Оба срока, ведущих к терминации.
                $q->where(function ($r) {
                    $r->where('c.activity', PartnerActivity::Registered->value)
                      ->whereNotNull('c.activationDeadline');
                })->orWhere(function ($r) {
                    $r->where('c.activity', PartnerActivity::Active->value)
                      ->whereNotNull('c.yearPeriodEnd');
                });
            })
            ->selectRaw('c.id, c."personName", c.activity, c."webUser", c."personalVolume",
                c.termination_warning_for,
                CASE WHEN c.activity = ? THEN c."activationDeadline"::date
                     ELSE c."yearPeriodEnd"::date END AS deadline,
                wu.telegram_chat_id', [PartnerActivity::Registered->value])
            ->get()
            ->filter(fn ($r) => $r->deadline !== null
                && $r->deadline >= $today->toDateString()
                && $r->deadline <= $until->toDateString())
            // Уже предупреждали ПОД ЭТОТ дедлайн — молчим.
            ->filter(fn ($r) => (string) $r->termination_warning_for !== (string) $r->deadline)
            ->values();

        if ($rows->isEmpty()) {
            $this->info("Некого предупреждать: нет партнёров с терминацией в ближайшие {$days} дн.");

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'ФИО', 'статус', 'срок', 'дней', 'ЛП', 'Telegram'],
            $rows->map(fn ($r) => [
                $r->id,
                mb_substr($r->personName ?? '—', 0, 30),
                (int) $r->activity === PartnerActivity::Registered->value ? 'Зарегистрирован' : 'Активен',
                $r->deadline,
                Carbon::parse($r->deadline)->diffInDays($today),
                round((float) ($r->personalVolume ?? 0), 2),
                $r->telegram_chat_id ? 'да' : '—',
            ])->all()
        );

        if ($dry) {
            $this->warn('DRY-RUN — ничего не отправлено.');

            return self::SUCCESS;
        }

        $sent = 0;
        foreach ($rows as $r) {
            $left = Carbon::parse($r->deadline)->diffInDays($today);
            $lp = round((float) ($r->personalVolume ?? 0), 2);

            $title = "До терминации {$left} " . $this->plural($left, 'день', 'дня', 'дней');
            $message = (int) $r->activity === PartnerActivity::Registered->value
                ? "Срок активации истекает {$r->deadline}. Нужно набрать {$points} ЛП, сейчас у вас {$lp}. "
                    . 'Если срок истечёт, агентский договор будет расторгнут, а баллы обнулены.'
                : "Годовой период заканчивается {$r->deadline}. Нужно набрать {$points} ЛП, сейчас у вас {$lp}. "
                    . 'Если срок истечёт, агентский договор будет расторгнут, а баллы обнулены.';

            try {
                NotificationController::create((int) $r->webUser, 'status', $title, $message, '/dashboard');
                DB::table('consultant')->where('id', $r->id)
                    ->update(['termination_warning_for' => $r->deadline]);
                $sent++;
            } catch (\Throwable $e) {
                // Один упавший партнёр не должен ронять всю рассылку.
                Log::warning('Termination warning failed', ['consultant' => $r->id, 'error' => $e->getMessage()]);
                $this->error("ФК {$r->id}: {$e->getMessage()}");
            }
        }

        $withTg = $rows->where('telegram_chat_id', '!=', null)->count();
        $this->info("Отправлено: {$sent}. Из них дойдёт в Telegram: {$withTg} — у остальных он не привязан, "
            . 'им уведомление только в кабинет.');

        return self::SUCCESS;
    }

    private function plural(int $n, string $one, string $few, string $many): string
    {
        if ($n % 10 === 1 && $n % 100 !== 11) return $one;
        if (in_array($n % 10, [2, 3, 4], true) && ! in_array($n % 100, [12, 13, 14], true)) return $few;

        return $many;
    }
}
