<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Группа прав «Контент-менеджер» (content) — раздел «Новости и объявления».
 *
 * Заведена как ДОПОЛНИТЕЛЬНАЯ роль к основной staff-роли: права в проекте
 * привязаны к ролям, а не к людям, и выдать новости одной Дарье Угаровой
 * иначе нельзя — секция в группе `head` открыла бы раздел ещё двум
 * руководителям.
 *
 * Роль признаётся staff (User::isStaff), запись в новости пропускает
 * RestrictHeadWrites через SECTION_EXCEPTIONS, уровень проверяет
 * CheckPermission на самих маршрутах (permission:news,edit|full).
 */
return new class extends Migration
{
    /** Кому выдаём роль дополнительно к текущей (WebUser.id). */
    private const GRANT_TO = [1058]; // Угарова Дарья

    public function up(): void
    {
        if (! Schema::hasTable('permission_groups')) {
            return;
        }

        $now = now();
        DB::table('permission_groups')->updateOrInsert(
            ['key' => 'content'],
            [
                'name' => 'Контент-менеджер',
                'description' => 'Новости и объявления: создание, правка, удаление. '
                    . 'Выдаётся дополнительно к основной staff-роли.',
                'permissions' => json_encode(['news' => 'full'], JSON_UNESCAPED_UNICODE),
                'is_system' => false,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        // Дописываем роль к существующим, не затирая: роль — CSV-строка,
        // и потерять основную (head) значило бы выкинуть человека из кабинета.
        foreach (self::GRANT_TO as $userId) {
            $user = DB::table('WebUser')->where('id', $userId)->first(['id', 'role']);
            if (! $user) continue;

            $roles = array_values(array_filter(array_map(
                'trim',
                explode(',', strtolower((string) $user->role))
            )));
            if (in_array('content', $roles, true)) continue;

            $roles[] = 'content';
            DB::table('WebUser')->where('id', $userId)->update(['role' => implode(',', $roles)]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permission_groups')) {
            return;
        }

        foreach (self::GRANT_TO as $userId) {
            $user = DB::table('WebUser')->where('id', $userId)->first(['id', 'role']);
            if (! $user) continue;

            $roles = array_values(array_filter(
                array_map('trim', explode(',', strtolower((string) $user->role))),
                fn ($r) => $r !== '' && $r !== 'content'
            ));
            DB::table('WebUser')->where('id', $userId)->update(['role' => implode(',', $roles)]);
        }

        DB::table('permission_groups')->where('key', 'content')->delete();
    }
};
