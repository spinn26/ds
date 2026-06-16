<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Исходящие вебхуки: реестр (url, события, секрет, активность) + лог доставок.
 * WebhookService::dispatch($event, $payload) шлёт POST подписчикам события.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('webhooks')) {
            Schema::create('webhooks', function (Blueprint $t) {
                $t->id();
                $t->string('name');
                $t->string('url', 1000);
                $t->jsonb('events')->nullable();   // null/пусто = все события
                $t->string('secret', 191)->nullable();
                $t->boolean('active')->default(true);
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('webhook_deliveries')) {
            Schema::create('webhook_deliveries', function (Blueprint $t) {
                $t->id();
                $t->foreignId('webhook_id')->constrained('webhooks')->cascadeOnDelete();
                $t->string('event', 100);
                $t->integer('status_code')->nullable();
                $t->boolean('success')->default(false);
                $t->text('response')->nullable();
                $t->timestamp('created_at')->useCurrent();
                $t->index(['webhook_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhooks');
    }
};
