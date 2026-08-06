<?php

use App\Enums\PartnerActivity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Бэкфилл дедлайна активации зарегистрированным партнёрам (2026-08-06).
 *
 * AuthController::register() собирал Consultant вручную и не выставлял
 * activationDeadline (PartnerStatusService::register() при регистрации не
 * вызывается). В результате у ВСЕХ платформенных регистраций поле было пустым,
 * а checkExpiredRegistrations() отбирает по whereNotNull('activationDeadline')
 * — 90-дневный счётчик не шёл ни по кому, автотерминация за ненабранные 500 ЛП
 * не сработала бы.
 *
 * Ставим дедлайн от ФАКТИЧЕСКОЙ даты регистрации (dateCreated + окно из
 * настроек), а не от сегодня: иначе партнёр, зарегистрированный в июне,
 * получил бы лишние три месяца. У кого dateCreated пуст — отсчитываем от
 * сегодня, это безопаснее, чем оставить NULL.
 *
 * Тем, у кого дедлайн уже задан, не трогаем.
 */
return new class extends Migration
{
    public function up(): void
    {
        $days = PartnerActivity::activationDays();

        $affected = DB::table('consultant')
            ->where('activity', PartnerActivity::Registered->value)
            ->whereNull('dateDeleted')
            ->whereNull('activationDeadline')
            ->update([
                'activationDeadline' => DB::raw(
                    "COALESCE(\"dateCreated\", NOW()) + INTERVAL '{$days} days'"
                ),
            ]);

        if (app()->runningInConsole()) {
            echo "  backfill activationDeadline: {$affected}\n";
        }
    }

    public function down(): void
    {
        // Обратно поле не чистим: отличить бэкфилл от штатно выставленного
        // дедлайна нечем, а зануление снова выключило бы автотерминацию.
    }
};
