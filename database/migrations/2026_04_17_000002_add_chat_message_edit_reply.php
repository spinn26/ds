<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds edit/reply columns to chat_messages:
 * - edited_at: timestamp when message was last edited (null = never edited)
 * - reply_to_id: id of the message being replied to (null = not a reply)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            if (! Schema::hasColumn('chat_messages', 'edited_at')) {
                $table->timestamp('edited_at')->nullable()->after('created_at');
            }
            if (! Schema::hasColumn('chat_messages', 'reply_to_id')) {
                $table->unsignedBigInteger('reply_to_id')->nullable()->after('edited_at');
                $table->index('reply_to_id', 'chat_messages_reply_to_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            if (Schema::hasColumn('chat_messages', 'reply_to_id')) {
                $table->dropIndex('chat_messages_reply_to_idx');
                $table->dropColumn('reply_to_id');
            }
            if (Schema::hasColumn('chat_messages', 'edited_at')) {
                $table->dropColumn('edited_at');
            }
        });
    }
};
