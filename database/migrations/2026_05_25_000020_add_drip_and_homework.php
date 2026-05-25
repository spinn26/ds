<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LMS этап 5: drip-feed уроков + домашние задания + сертификаты.
 *
 * Per ТЗ Жосан §10 (комментарий «было бы хорошо отслеживать») и общие
 * требования к LMS-системам уровня GetCourse:
 *   • drip-feed — урок открывается через N дней от старта курса или
 *     по конкретной дате (поля drip_delay_hours / drip_open_at).
 *   • is_stop_lesson — пока не пройден этот урок, следующий заблокирован.
 *   • Домашние задания (homework_submissions) — ученик присылает ответ
 *     (текст + file_url), куратор ставит approved / rejected / comment.
 *   • PDF-сертификаты при 100% прохождении курса (course_certificates).
 *
 * Все поля делаются опциональными — старые курсы продолжают работать
 * как есть (drip_* NULL → урок открыт сразу).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('education_lessons', function (Blueprint $table) {
            if (! Schema::hasColumn('education_lessons', 'drip_delay_hours')) {
                $table->integer('drip_delay_hours')->nullable();
            }
            if (! Schema::hasColumn('education_lessons', 'drip_open_at')) {
                $table->timestamp('drip_open_at')->nullable();
            }
            if (! Schema::hasColumn('education_lessons', 'is_stop_lesson')) {
                $table->boolean('is_stop_lesson')->default(false);
            }
            if (! Schema::hasColumn('education_lessons', 'requires_homework')) {
                $table->boolean('requires_homework')->default(false);
            }
            if (! Schema::hasColumn('education_lessons', 'homework_instructions')) {
                $table->text('homework_instructions')->nullable();
            }
        });

        Schema::table('education_courses', function (Blueprint $table) {
            if (! Schema::hasColumn('education_courses', 'drip_anchor')) {
                // 'access_granted' | 'first_login' — от чего отсчитывать
                // drip_delay_hours на уроке. NULL = не используем drip.
                $table->string('drip_anchor', 30)->nullable();
            }
            if (! Schema::hasColumn('education_courses', 'issues_certificate')) {
                $table->boolean('issues_certificate')->default(false);
            }
        });

        if (! Schema::hasTable('education_homework_submissions')) {
            Schema::create('education_homework_submissions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('lesson_id')->index();
                $table->foreign('lesson_id')->references('id')->on('education_lessons')->cascadeOnDelete();
                $table->unsignedBigInteger('user_id')->index();
                $table->text('answer_text')->nullable();
                $table->jsonb('attachments')->nullable();   // [{name, url, size}]
                $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending')->index();
                $table->text('reviewer_comment')->nullable();
                $table->unsignedBigInteger('reviewer_id')->nullable()->index();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'lesson_id']);
                $table->index(['status', 'created_at']);
            });
        }

        if (! Schema::hasTable('education_course_certificates')) {
            Schema::create('education_course_certificates', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedBigInteger('course_id')->index();
                $table->string('certificate_no', 60)->unique();
                $table->string('file_path', 500)->nullable();  // относительно storage/app/private/
                $table->timestamp('issued_at')->useCurrent();
                $table->timestamps();
            });
        }

        // Когда партнёр начал курс — для расчёта drip-расписания относительно
        // первого входа. Если строки нет — анкор 'first_login' эквивалентен
        // 'access_granted' (моменту создания доступа).
        if (! Schema::hasTable('education_course_enrollments')) {
            Schema::create('education_course_enrollments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedBigInteger('course_id')->index();
                $table->timestamp('granted_at')->useCurrent();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
                $table->unique(['user_id', 'course_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('education_course_enrollments');
        Schema::dropIfExists('education_course_certificates');
        Schema::dropIfExists('education_homework_submissions');

        Schema::table('education_courses', function (Blueprint $table) {
            foreach (['drip_anchor', 'issues_certificate'] as $c) {
                if (Schema::hasColumn('education_courses', $c)) $table->dropColumn($c);
            }
        });
        Schema::table('education_lessons', function (Blueprint $table) {
            foreach (['drip_delay_hours', 'drip_open_at', 'is_stop_lesson',
                'requires_homework', 'homework_instructions'] as $c) {
                if (Schema::hasColumn('education_lessons', $c)) $table->dropColumn($c);
            }
        });
    }
};
