<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_incident_updates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('incident_id')->constrained('system_incidents')->cascadeOnDelete();
            $t->string('status', 30); // снапшот статуса на момент апдейта
            $t->text('message');
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['incident_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_incident_updates');
    }
};
