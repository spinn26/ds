<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CSAT (customer satisfaction) inline после закрытия тикета — стандартная
 * метрика поддержки, как в Zendesk/Intercom: 5-звёздочная оценка + опциональный
 * комментарий. Хранится прямо на chat_tickets, чтобы не плодить таблицу.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_tickets', function (Blueprint $t) {
            if (! Schema::hasColumn('chat_tickets', 'csat_rating')) {
                $t->smallInteger('csat_rating')->nullable();
            }
            if (! Schema::hasColumn('chat_tickets', 'csat_comment')) {
                $t->string('csat_comment', 1000)->nullable();
            }
            if (! Schema::hasColumn('chat_tickets', 'csat_at')) {
                $t->timestamp('csat_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('chat_tickets', function (Blueprint $t) {
            foreach (['csat_rating', 'csat_comment', 'csat_at'] as $c) {
                if (Schema::hasColumn('chat_tickets', $c)) $t->dropColumn($c);
            }
        });
    }
};
