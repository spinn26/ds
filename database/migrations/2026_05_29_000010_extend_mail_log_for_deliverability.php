<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Расширяем mail_log так, чтобы покрыть три уровня feedback:
     *  1) реальный SMTP-статус (sent / failed после фактического handshake);
     *  2) bounce от провайдера получателя (sent → bounced + bounce_reason);
     *  3) tracking pixel / click-wrapper (opened_at / clicked_at).
     *
     * Старая колонка `status` (sent/failed) остаётся ради совместимости с
     * AdminMailController::broadcastProgress и существующими row'ами. Новая
     * `delivery_status` — расширенный жизненный цикл письма.
     */
    public function up(): void
    {
        Schema::table('mail_log', function (Blueprint $t) {
            // Уникальный per-email id — используется как корреляционный ключ
            // для tracking pixel, click-wrapper и для матчинга bounce-NDR.
            $t->uuid('tracking_id')->nullable()->after('id');

            // Уровень 1 — реальный feedback от SMTP-relay (Yandex).
            $t->string('mailer', 50)->nullable()->after('sender_id');
            $t->string('from_address', 255)->nullable()->after('mailer');
            $t->string('message_id', 255)->nullable()->after('from_address');
            $t->text('smtp_response')->nullable()->after('error');
            $t->smallInteger('attempts')->default(0)->after('smtp_response');

            // Расширенный жизненный цикл (старый `status` оставляем как был).
            // Возможные значения: pending / sent / failed / bounced / delivered.
            $t->string('delivery_status', 20)->nullable()->after('status');

            // Уровень 2 — bounce.
            $t->text('bounce_reason')->nullable()->after('delivery_status');
            $t->string('bounce_code', 16)->nullable()->after('bounce_reason');
            $t->timestamp('bounced_at')->nullable()->after('bounce_code');

            // Уровень 3 — tracking pixel + click-wrapper.
            $t->timestamp('opened_at')->nullable()->after('sent_at');
            $t->smallInteger('opens_count')->default(0)->after('opened_at');
            $t->timestamp('clicked_at')->nullable()->after('opens_count');
            $t->smallInteger('clicks_count')->default(0)->after('clicked_at');
            $t->text('last_click_url')->nullable()->after('clicks_count');

            // Тип письма для отчётности: password_reset / broadcast / test /
            // notification / ... Заполняется отправляющим кодом через
            // X-DS-Mail-Type header.
            $t->string('mail_type', 60)->nullable()->after('subject');
        });

        // Индексы:
        //  - tracking_id UNIQUE: открывает прямой lookup по URL пикселя/клика;
        //  - message_id: матчинг bounce-NDR (Yandex кладёт исходный
        //    Message-ID в References/In-Reply-To NDR-сообщения);
        //  - delivery_status,created_at: фильтр в admin-журнале по статусу +
        //    отчёт «доставляемость за день».
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS mail_log_tracking_id_unique
            ON mail_log (tracking_id) WHERE tracking_id IS NOT NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS mail_log_message_id_index
            ON mail_log (message_id) WHERE message_id IS NOT NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS mail_log_delivery_status_created_at_index
            ON mail_log (delivery_status, created_at DESC)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS mail_log_tracking_id_unique');
        DB::statement('DROP INDEX IF EXISTS mail_log_message_id_index');
        DB::statement('DROP INDEX IF EXISTS mail_log_delivery_status_created_at_index');

        Schema::table('mail_log', function (Blueprint $t) {
            $t->dropColumn([
                'tracking_id', 'mailer', 'from_address', 'message_id',
                'smtp_response', 'attempts', 'delivery_status',
                'bounce_reason', 'bounce_code', 'bounced_at',
                'opened_at', 'opens_count', 'clicked_at', 'clicks_count',
                'last_click_url', 'mail_type',
            ]);
        });
    }
};
