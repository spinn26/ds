<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Категории курсов — отдел продуктов хочет произвольное группирование
 * учебного контента (вместо/вдобавок к жёстким 9 семантическим «блокам»
 * из education_courses.block).
 *
 * Курсы получают необязательный FK на категорию: legacy-курсы остаются
 * без категории и продолжают группироваться по block на витрине партнёра
 * (бакауорд-совместимый fallback).
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('education_course_categories')) {
            Schema::create('education_course_categories', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name', 200);
                $table->integer('sort_order')->default(0);
                $table->boolean('active')->default(true);
                $table->timestamp('deleted_at')->nullable();
                $table->timestamps();
                $table->index('active');
                $table->index('sort_order');
            });
        }

        if (! Schema::hasColumn('education_courses', 'category_id')) {
            Schema::table('education_courses', function (Blueprint $table) {
                $table->unsignedBigInteger('category_id')->nullable()->after('product_id');
                $table->index('category_id');
                $table->foreign('category_id')
                    ->references('id')->on('education_course_categories')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('education_courses', 'category_id')) {
            Schema::table('education_courses', function (Blueprint $table) {
                $table->dropForeign(['category_id']);
                $table->dropIndex(['category_id']);
                $table->dropColumn('category_id');
            });
        }
        Schema::dropIfExists('education_course_categories');
    }
};
