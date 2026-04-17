<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('mail_settings', function (Blueprint $table) {
            $table->id();
            $table->string('host')->nullable();
            $table->unsignedSmallInteger('port')->default(587);
            $table->string('username')->nullable();
            $table->string('password')->nullable(); // stored as-is (admin-only)
            $table->string('encryption', 16)->nullable(); // tls | ssl | null
            $table->string('from_address')->nullable();
            $table->string('from_name')->nullable();
            $table->timestamps();
        });

        Schema::create('mail_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sender_id')->nullable();
            $table->string('recipient_email');
            $table->unsignedBigInteger('recipient_user_id')->nullable();
            $table->string('subject');
            $table->longText('body')->nullable();
            $table->string('status', 16)->default('sent'); // sent | failed
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['sender_id', 'created_at']);
            $table->index(['recipient_user_id']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_log');
        Schema::dropIfExists('mail_settings');
    }
};
