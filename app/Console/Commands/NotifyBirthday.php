<?php

namespace App\Console\Commands;

use App\Enums\PartnerActivity;
use App\Http\Controllers\Api\NotificationController;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Поздравление партнёра с днём рождения — в кабинет, на почту и в Telegram.
 *
 * Канал не выбираем: NotificationController::create() сам разводит запись в
 * `notifications`, письмо через очередь (SendNotificationEmail) и сообщение в
 * Telegram, если чат привязан. Тот же путь, что у предупреждения о терминации.
 *
 * Источник даты — WebUser."birthDate" (timestamp). Легаси-колонку
 * consultant."birthDate" сознательно НЕ используем: там varchar со
 * свободным форматом из Directual, и разбирать его для сравнения по дню
 * значит гадать. У всех, кто регистрировался на платформе, дата лежит в
 * WebUser — её пишет AuthController::register.
 *
 * Кого не поздравляем: терминированных, исключённых и удалённых — расторжение
 * договора и поздравление в один адрес выглядят издевательством.
 */
class NotifyBirthday extends Command
{
    protected $signature = 'partners:notify-birthday
        {--dry-run : показать кому уйдёт, ничего не отправляя}
        {--date= : считать «сегодня» указанной датой (YYYY-MM-DD), для проверки}';

    protected $description = 'Поздравить партнёров с днём рождения (кабинет + почта + Telegram)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $today = $this->option('date')
            ? Carbon::parse((string) $this->option('date'))->startOfDay()
            : Carbon::today();

        $keys = [$today->format('m-d')];

        // 29 февраля: в невисокосный год такого дня нет, и родившиеся 29.02
        // не получили бы поздравление вообще. Поздравляем их 1 марта.
        if ($today->format('m-d') === '03-01' && ! $today->isLeapYear()) {
            $keys[] = '02-29';
        }

        $rows = DB::table('consultant as c')
            ->join('WebUser as wu', 'wu.id', '=', 'c.webUser')
            ->whereNull('c.dateDeleted')
            ->whereNotIn('c.activity', [
                PartnerActivity::Terminated->value,
                PartnerActivity::Excluded->value,
            ])
            ->whereNotNull('wu.birthDate')
            ->whereIn(DB::raw('to_char(wu."birthDate", \'MM-DD\')'), $keys)
            ->select(
                'c.id',
                'c.webUser',
                'c.personName',
                'wu.firstName',
                'wu.email',
                'wu.telegram_chat_id',
                'wu.birthDate'
            )
            ->orderBy('c.id')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('Именинников сегодня нет.');

            return self::SUCCESS;
        }

        // Защита от повторной отправки: команда ежедневная, но ручной повтор
        // или перезапуск планировщика не должны слать второе поздравление.
        $alreadyGreeted = DB::table('notifications')
            ->where('type', 'birthday')
            ->whereIn('user_id', $rows->pluck('webUser')->all())
            ->whereDate('created_at', $today->toDateString())
            ->pluck('user_id')
            ->flip();

        $this->table(
            ['ФК', 'Партнёр', 'Дата рождения', 'E-mail', 'Telegram', 'Уже поздравлен'],
            $rows->map(fn ($r) => [
                $r->id,
                $r->personName,
                Carbon::parse($r->birthDate)->format('d.m.Y'),
                filter_var($r->email ?? '', FILTER_VALIDATE_EMAIL) ? 'да' : '—',
                $r->telegram_chat_id ? 'да' : '—',
                $alreadyGreeted->has($r->webUser) ? 'да' : '—',
            ])->all()
        );

        if ($dry) {
            $this->warn('DRY-RUN — ничего не отправлено.');

            return self::SUCCESS;
        }

        $sent = 0;
        $skipped = 0;

        foreach ($rows as $r) {
            if ($alreadyGreeted->has($r->webUser)) {
                $skipped++;
                continue;
            }

            // Обращаемся по имени, если оно есть: «Поздравляем, Иван!» читается
            // человечнее безличного текста. Фолбэк — без имени, а не «null».
            $name = trim((string) ($r->firstName ?? ''));
            $title = 'С днём рождения!';
            $message = ($name !== '' ? "{$name}, поздравляем вас с днём рождения! " : 'Поздравляем вас с днём рождения! ')
                . 'Команда DS Consulting желает здоровья, энергии и уверенного роста — '
                . 'и личного, и вашей команды. Спасибо, что вы с нами.';

            try {
                NotificationController::create((int) $r->webUser, 'birthday', $title, $message, '/dashboard');
                $sent++;
            } catch (\Throwable $e) {
                // Один упавший партнёр не должен ронять всю рассылку.
                Log::warning('Birthday greeting failed', ['consultant' => $r->id, 'error' => $e->getMessage()]);
                $this->error("ФК {$r->id}: {$e->getMessage()}");
                continue;
            }
        }

        $this->info("Поздравлений отправлено: {$sent}" . ($skipped ? ", пропущено как уже отправленные: {$skipped}" : ''));
        $this->info('Почта и Telegram — через NotificationController::create(), см. SendNotificationEmail.');

        return self::SUCCESS;
    }
}
