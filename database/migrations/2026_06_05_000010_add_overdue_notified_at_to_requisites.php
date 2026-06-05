<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Метка «уже уведомили об просрочке верификации» на requisites.
 *
 * Команда requisites:notify-overdue шлёт уведомление финменеджеру, когда
 * реквизиты висят «на проверке» дольше 1 рабочего дня. Чтобы не слать одно
 * и то же каждый прогон планировщика, стампим overdue_notified_at и больше
 * не трогаем эту запись до следующей переотправки реквизитов (где метка
 * сбрасывается в null — setRequisitesPending / setupRequisites).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisites', function (Blueprint $table) {
            if (! Schema::hasColumn('requisites', 'overdue_notified_at')) {
                $table->timestamp('overdue_notified_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('requisites', function (Blueprint $table) {
            if (Schema::hasColumn('requisites', 'overdue_notified_at')) {
                $table->dropColumn('overdue_notified_at');
            }
        });
    }
};
