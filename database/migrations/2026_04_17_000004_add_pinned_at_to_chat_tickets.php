<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_tickets', function (Blueprint $table) {
            if (! Schema::hasColumn('chat_tickets', 'pinned_at')) {
                $table->timestamp('pinned_at')->nullable()->after('closed_at');
                $table->index('pinned_at', 'chat_tickets_pinned_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('chat_tickets', function (Blueprint $table) {
            if (Schema::hasColumn('chat_tickets', 'pinned_at')) {
                $table->dropIndex('chat_tickets_pinned_idx');
                $table->dropColumn('pinned_at');
            }
        });
    }
};
