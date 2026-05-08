<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permission groups — управление правами кабинетов через UI/БД.
 *
 * Группа = роль из WebUser.role (admin/backoffice/support/...). Ключ
 * группы должен совпадать с тем, что записано в comma-separated
 * WebUser.role, чтобы права применились реальному пользователю.
 *
 * permissions — JSONB карта: { section: 'view'|'edit'|'full' }.
 * Семантика та же что в resources/js/config/cabinetPermissions.js
 * (этот файл остаётся как fallback / seed-данные).
 *
 * is_system — системные группы (admin) нельзя удалить через UI.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('permission_groups', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();        // 'backoffice'
            $table->string('name', 150);                 // 'Кабинет БЭК-офиса'
            $table->string('description', 500)->nullable();
            $table->jsonb('permissions')->default('{}'); // { section: level }
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_groups');
    }
};
