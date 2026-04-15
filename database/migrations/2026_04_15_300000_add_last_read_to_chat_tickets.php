<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_read_status', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('ticket_id');
            $table->integer('user_id');
            $table->timestamp('last_read_at');
            $table->unique(['ticket_id', 'user_id']);
            $table->foreign('ticket_id')->references('id')->on('chat_tickets')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_read_status');
    }
};
