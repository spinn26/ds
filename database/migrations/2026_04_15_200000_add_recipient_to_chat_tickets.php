<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('chat_tickets', 'recipient_id')) {
            Schema::table('chat_tickets', function (Blueprint $table) {
                $table->integer('recipient_id')->nullable()->after('customer_email');
                $table->string('recipient_name')->nullable()->after('recipient_id');
                $table->string('context_type', 50)->nullable()->after('tags');
                $table->string('context_id', 50)->nullable()->after('context_type');
            });
        }
    }

    public function down(): void
    {
        Schema::table('chat_tickets', function (Blueprint $table) {
            $table->dropColumn(['recipient_id', 'recipient_name', 'context_type', 'context_id']);
        });
    }
};
