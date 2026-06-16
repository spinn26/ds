<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Фиче-флаги: вкл/выкл функционала из админки, опционально по ролям.
 * Бэкенд/фронт проверяют FeatureFlag::enabled('key', $user).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('feature_flags')) {
            Schema::create('feature_flags', function (Blueprint $t) {
                $t->id();
                $t->string('key', 64)->unique();
                $t->string('label');
                $t->text('description')->nullable();
                $t->boolean('enabled')->default(false);
                $t->jsonb('roles')->nullable(); // null = всем (у кого enabled)
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_flags');
    }
};
