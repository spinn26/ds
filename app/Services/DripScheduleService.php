<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Расчёт времени открытия урока по drip-расписанию.
 *
 * Per ТЗ Жосан §10 (комментарий) + общая практика GetCourse:
 *   • lessons.drip_open_at — конкретная дата открытия (fixed mode)
 *   • lessons.drip_delay_hours — задержка в часах от старта курса (relative)
 *   • courses.drip_anchor — от чего считать relative-delay:
 *       'access_granted' — момент enrollment (granted_at)
 *       'first_login'    — момент первого открытия курса (started_at)
 *   • is_stop_lesson — если урок отмечен стоп-уроком и НЕ пройден,
 *     следующие уроки в курсе остаются закрытыми.
 *
 * Без drip-полей → урок открыт сразу (legacy-поведение).
 */
class DripScheduleService
{
    /**
     * Решить — открыт ли урок для пользователя СЕЙЧАС.
     * Возвращает массив:
     *   ['open' => bool, 'reason' => string|null, 'unlockAt' => Carbon|null]
     */
    public function lessonAvailability(object $lesson, ?int $userId, ?object $course = null): array
    {
        if (! Schema::hasColumn('education_lessons', 'drip_open_at')) {
            return ['open' => true, 'reason' => null, 'unlockAt' => null];
        }

        // 1) Фиксированная дата открытия
        if (! empty($lesson->drip_open_at)) {
            $dt = Carbon::parse($lesson->drip_open_at);
            if (now()->lt($dt)) {
                return [
                    'open' => false,
                    'reason' => "Откроется {$dt->format('d.m.Y H:i')}",
                    'unlockAt' => $dt,
                ];
            }
        }

        // 2) Relative delay
        if (! empty($lesson->drip_delay_hours) && $userId && $course) {
            $anchor = $this->anchorTime($course, $userId);
            if ($anchor) {
                $unlock = $anchor->copy()->addHours((int) $lesson->drip_delay_hours);
                if (now()->lt($unlock)) {
                    return [
                        'open' => false,
                        'reason' => "Откроется {$unlock->format('d.m.Y H:i')}",
                        'unlockAt' => $unlock,
                    ];
                }
            }
        }

        // 3) Стоп-урок: если в курсе есть более ранний стоп-урок, который
        //    не пройден — закрываем.
        if ($userId && Schema::hasColumn('education_lessons', 'is_stop_lesson')) {
            $earlierStop = DB::table('education_lessons as l')
                ->where('l.course_id', $lesson->course_id ?? null)
                ->where('l.is_stop_lesson', true)
                ->where('l.sort_order', '<', $lesson->sort_order ?? 0)
                ->whereNotExists(function ($q) use ($userId) {
                    $q->select(DB::raw(1))->from('education_lesson_views as v')
                      ->whereColumn('v.lesson_id', 'l.id')
                      ->where('v.user_id', $userId);
                })
                ->orderBy('l.sort_order')
                ->first(['l.id', 'l.title']);
            if ($earlierStop) {
                return [
                    'open' => false,
                    'reason' => "Сначала завершите урок «{$earlierStop->title}»",
                    'unlockAt' => null,
                ];
            }
        }

        return ['open' => true, 'reason' => null, 'unlockAt' => null];
    }

    /** Получить anchor-время для relative-drip. */
    private function anchorTime(object $course, int $userId): ?Carbon
    {
        if (! Schema::hasTable('education_course_enrollments')) return null;
        $enroll = DB::table('education_course_enrollments')
            ->where('user_id', $userId)
            ->where('course_id', $course->id)
            ->first();
        if (! $enroll) return null;

        $anchor = $course->drip_anchor ?? 'access_granted';
        if ($anchor === 'first_login' && $enroll->started_at) {
            return Carbon::parse($enroll->started_at);
        }
        return Carbon::parse($enroll->granted_at);
    }

    /**
     * Зарегистрировать первый вход партнёра в курс (нужно для relative-drip
     * с anchor='first_login'). Idempotent — повторный вызов не обнуляет.
     */
    public function markCourseStarted(int $userId, int $courseId): void
    {
        if (! Schema::hasTable('education_course_enrollments')) return;
        $now = now();
        DB::table('education_course_enrollments')->upsert([
            ['user_id' => $userId, 'course_id' => $courseId,
             'granted_at' => $now, 'started_at' => $now,
             'created_at' => $now, 'updated_at' => $now],
        ], ['user_id', 'course_id'], ['updated_at']);
        // Если started_at NULL — выставим сейчас. upsert update игнорирует
        // not-modified, поэтому отдельный UPDATE:
        DB::table('education_course_enrollments')
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->whereNull('started_at')
            ->update(['started_at' => $now, 'updated_at' => $now]);
    }
}
