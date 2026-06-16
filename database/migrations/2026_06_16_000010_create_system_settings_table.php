<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Универсальный слой настроек платформы (system_settings).
 *
 * Ключ-значение с типом и категорией — основа редактируемых из админки
 * настроек (раздел «Настройки»). Сервисы читают значения через
 * App\Models\SystemSetting::value('key', $fallback), всегда с фолбэком на
 * прежнюю константу — поэтому даже до сидирования поведение не меняется.
 *
 * Фаза 1: только безопасные, НЕфинансовые настройки (пагинация, лимиты
 * поиска обучения, retention очисток, адреса уведомлений). Денежные правила
 * (комиссии/пул/штрафы/активация) сюда НЕ выносятся в этой фазе.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_settings')) {
            Schema::create('system_settings', function ($t) {
                $t->id();
                $t->string('key', 191)->unique();
                $t->text('value')->nullable();
                $t->string('type', 20)->default('string'); // string|int|float|bool|json
                $t->string('category', 50)->default('general');
                $t->string('label')->nullable();
                $t->text('description')->nullable();
                $t->boolean('is_secret')->default(false);
                $t->integer('sort_order')->default(0);
                $t->timestamps();
            });
        }

        $now = now();
        $rows = [
            // ── Производительность ───────────────────────────────────────
            ['pagination.default_per_page', '25', 'int', 'performance', 'Строк на странице по умолчанию', 'Начальный размер страницы в админ-таблицах.', 10],
            ['pagination.max_per_page', '100', 'int', 'performance', 'Максимум строк на странице', 'Верхний предел per_page, который примет API.', 20],

            // ── Обучение ─────────────────────────────────────────────────
            ['education.search_limit', '30', 'int', 'education', 'Лимит результатов поиска', 'Сколько курсов/уроков/статей вернуть в поиске обучения.', 10],
            ['education.search_min_chars', '2', 'int', 'education', 'Мин. длина поискового запроса', 'Короче — поиск не выполняется.', 20],

            // ── Обслуживание / очистки ───────────────────────────────────
            ['maintenance.integration_events_retention_days', '90', 'int', 'maintenance', 'Хранение integration_events (дни)', 'Старше — ежедневная очистка журнала интеграций.', 10],
            ['maintenance.mail_log_retention_days', '365', 'int', 'maintenance', 'Хранение mail_log (дни)', 'Старше — ежемесячная очистка лога писем.', 20],
            ['maintenance.failed_jobs_retention_days', '30', 'int', 'maintenance', 'Хранение упавших задач (дни)', 'Старше — ежедневная очистка failed_jobs.', 30],

            // ── Уведомления ──────────────────────────────────────────────
            ['notifications.requisites_overdue_email', 'ekaterina.bogdanova@ds-finance.ru', 'string', 'notifications', 'E-mail для алертов по реквизитам', 'Куда слать уведомления о реквизитах, зависших на верификации.', 10],
        ];

        foreach ($rows as $r) {
            // upsert, чтобы повторный прогон/откат-накат не падал на unique.
            DB::table('system_settings')->updateOrInsert(
                ['key' => $r[0]],
                [
                    'value' => $r[1], 'type' => $r[2], 'category' => $r[3],
                    'label' => $r[4], 'description' => $r[5], 'sort_order' => $r[6],
                    'updated_at' => $now, 'created_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
