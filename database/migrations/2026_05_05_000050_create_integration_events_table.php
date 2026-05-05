<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Единый журнал событий интеграций.
 *
 * Сюда пишут ВСЕ внешние вызовы (исходящие в Insmart / Google Sheets /
 * Telegram / SMTP / Socket.IO) и входящие webhook'и (Insmart paid и
 * будущие). Из этой таблицы строится:
 *   - страница «Интеграции» в админке (список сервисов с health-метриками)
 *   - журнал событий (с фильтрами по сервису / направлению / статусу)
 *   - replay webhook'а (повторно дернуть обработчик с тем же payload)
 *   - алерты на просадку success-rate
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_events', function (Blueprint $table) {
            $table->id();
            // Имя сервиса: insmart, google_sheets, telegram, smtp, socket_io.
            $table->string('service', 50)->index();
            // inbound (webhook от внешнего сервиса) / outbound (наш вызов наружу).
            $table->string('direction', 10);
            // Логическое действие: paid_webhook, send_message, read_sheet, ...
            $table->string('action', 80);
            // success / error / pending — текущий статус последнего шага.
            $table->string('status', 20)->index();
            // Короткое читаемое сообщение для UI (с резервированием на будущее).
            $table->text('summary')->nullable();
            // Полный payload запроса/ответа (зарезано до 1 МБ через текст).
            $table->jsonb('request')->nullable();
            $table->jsonb('response')->nullable();
            // Длительность мс — для метрики latency.
            $table->integer('duration_ms')->nullable();
            // Внешний идентификатор (например, externalOrderId webhook'а).
            $table->string('external_id', 120)->nullable()->index();
            // Кто инициировал (для исходящих) — id WebUser, NULL для CLI/scheduler.
            $table->bigInteger('actor_id')->nullable();
            // IP отправителя (для inbound).
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['service', 'created_at']);
            $table->index(['service', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_events');
    }
};
