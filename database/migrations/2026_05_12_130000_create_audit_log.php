<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('user_email', 255)->nullable();
            $t->string('user_role', 100)->nullable();
            $t->string('action', 100); // 'create' | 'update' | 'delete' | 'login' | 'role_change' | ...
            $t->string('entity', 100); // 'consultant' | 'client' | 'chat_ticket' | 'system_incident' | ...
            $t->string('entity_id', 100)->nullable();
            $t->jsonb('payload')->nullable();
            $t->string('ip', 64)->nullable();
            $t->string('user_agent', 500)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['entity', 'entity_id']);
            $t->index(['user_id', 'created_at']);
            $t->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
