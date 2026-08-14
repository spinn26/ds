<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Удаление модуля «Задачи и Проекты» (2026-08-14, решение владельца).
 *
 * Из меню раздел убрали ещё 2026-07-16, бэкенд оставался живым. Теперь снят
 * целиком: контроллеры, модели, сервисы, Vue-страницы, роуты и крон-задачи —
 * вместе с данными.
 *
 * Заодно уходит `user_tasks` — персональный TODO-виджет рабочего стола. Это был
 * отдельный от модуля механизм, но удаляется по тому же решению.
 * ⚠ `user_notes` (заметка-scratchpad) НЕ трогаем: виджет остаётся.
 * ⚠ `departments` и оргструктура НЕ трогаем: на них держится «руководитель
 *   отдела видит чаты подчинённых» (ChatController + ChatTicketPolicy).
 *
 * Порядок дропа — от зависимых к родительским; CASCADE как страховка на случай
 * FK, добавленных вне миграций.
 *
 * ⛔ down() данные НЕ восстанавливает: пересоздавать пустые таблицы модуля,
 * которого больше нет в коде, смысла нет — откат вернул бы каркас без строк и
 * без единого читателя. Возврат модуля = revert коммита + восстановление БД из
 * бэкапа. Метод оставлен пустым осознанно.
 */
return new class extends Migration
{
    /** Порядок значим: сначала зависимые, потом родительские. */
    private const TABLES = [
        'task_favorites',
        'task_accomplices',
        'task_watchers',
        'task_timers',
        'task_links',
        'task_attachments',
        'task_comments',
        'task_templates',
        'tasks',
        'task_stages',
        'project_members',
        'projects',
        'user_tasks',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table)) {
                DB::statement('DROP TABLE IF EXISTS ' . '"' . $table . '" CASCADE');
            }
        }
    }

    public function down(): void
    {
        // Намеренно пусто — см. докблок.
    }
};
