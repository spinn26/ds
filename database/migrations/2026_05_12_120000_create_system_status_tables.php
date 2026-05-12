<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Статус доступности системы (по образцу Bitrix24 / Atlassian Statuspage):
 *  - system_components — список логических компонентов с текущим статусом.
 *  - system_incidents — инциденты, события и плановые работы; могут
 *    быть привязаны к компоненту или быть глобальными (component_id = NULL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_components', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->string('description', 500)->nullable();
            // operational | degraded | partial_outage | major_outage | maintenance
            $t->string('status', 30)->default('operational');
            $t->integer('sort_order')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->index(['active', 'sort_order']);
        });

        Schema::create('system_incidents', function (Blueprint $t) {
            $t->id();
            $t->string('title', 200);
            $t->text('description')->nullable();
            // investigating | identified | monitoring | resolved | scheduled | in_progress | completed
            $t->string('status', 30)->default('investigating');
            // minor | major | critical | maintenance
            $t->string('severity', 20)->default('minor');
            $t->foreignId('component_id')->nullable()->constrained('system_components')->nullOnDelete();
            $t->timestamp('started_at')->useCurrent();
            $t->timestamp('resolved_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->index(['status', 'started_at']);
            $t->index('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_incidents');
        Schema::dropIfExists('system_components');
    }
};
