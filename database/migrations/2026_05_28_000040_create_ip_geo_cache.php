<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Кэш гео-резолва IP-адресов. Источник — бесплатный ip-api.com (45 rps).
 *
 * Зачем кэшировать:
 *   - бесплатный лимит 45 rps легко выгребается при просмотре истории
 *     многоюзерной системы;
 *   - IP→регион меняется редко, ttl 30 дней с запасом;
 *   - неудачные резолвы (private IP, fail) тоже кэшируем (с короткий ttl),
 *     чтобы не долбить API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ip_geo_cache', function (Blueprint $table) {
            $table->string('ip', 45)->primary(); // IPv6 = 39 chars max
            $table->string('country_code', 2)->nullable();
            $table->string('country_name', 80)->nullable();
            $table->string('region', 120)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('isp', 200)->nullable();
            $table->string('status', 16)->default('ok'); // ok|fail|private
            $table->timestamp('resolved_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_geo_cache');
    }
};
