<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Руководитель отдела видит чаты своих сотрудников (2026-08-10).
 *
 * Видимость staff-тикетов работает по правилу «взял — скрылось»: тикет отдела
 * виден коллегам только пока `assigned_to IS NULL`. Для рядовых сотрудников это
 * правильно (не разбирают одно и то же), но руководителю отдела оно закрывает
 * работу подчинённых: у бэк-офиса из 472 тикетов Алла Миняйлова видела 10.
 *
 * Флаг per-user, а не роль/группа прав: `permission_groups` резолвятся по РОЛИ,
 * и выдача права всей роли `backoffice` открыла бы полную видимость каждому
 * сотруднику отдела — то есть отменила бы claim & hide целиком.
 *
 * Ставим сразу Алле Миняйловой (WebUser 5) — руководитель бэк-офиса, ради неё
 * задача и заводилась. Остальных включают галочкой в карточке пользователя.
 */
return new class extends Migration
{
    private const ALLA_WEBUSER_ID = 5;

    public function up(): void
    {
        if (! Schema::hasColumn('WebUser', 'chat_department_lead')) {
            Schema::table('WebUser', function (Blueprint $table) {
                $table->boolean('chat_department_lead')->default(false);
            });
        }

        // Идемпотентно и только если это действительно она: на другой базе
        // (локаль/дев) id может принадлежать кому-то ещё.
        DB::table('WebUser')
            ->where('id', self::ALLA_WEBUSER_ID)
            ->where('role', 'ilike', '%backoffice%')
            ->update(['chat_department_lead' => true]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('WebUser', 'chat_department_lead')) {
            Schema::table('WebUser', function (Blueprint $table) {
                $table->dropColumn('chat_department_lead');
            });
        }
    }
};
