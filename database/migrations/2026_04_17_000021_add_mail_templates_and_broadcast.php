<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('mail_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('subject');
            $table->longText('body');
            $table->boolean('is_html')->default(true);
            $table->timestamps();
        });

        Schema::table('mail_log', function (Blueprint $table) {
            $table->string('broadcast_id', 36)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('mail_log', function (Blueprint $table) {
            $table->dropColumn('broadcast_id');
        });
        Schema::dropIfExists('mail_templates');
    }
};
