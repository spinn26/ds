<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Сборщик дерева курсов LMS с прогрессом партнёра.
 *
 * Per ТЗ Жосан (25.05.2026): структура «Курс → Модуль → Подмодуль → Урок»
 * реализована через рекурсивный parent_id в education_courses. Этот
 * сервис достаёт всё одним запросом, складывает в дерево и обогащает
 * статистикой прогресса (количество уроков, изученных, наличие теста).
 */
class EducationTreeService
{
    /**
     * Полное дерево всех курсов с прогрессом конкретного пользователя.
     *
     * Возвращает массив корневых курсов с вложенными children, в каждом
     * узле — lessons (только листовые, не агрегируются из под-узлов).
     */
    public function fullTree(?int $userId): array
    {
        $courses = DB::table('education_courses')
            ->where('active', true)
            ->select([
                'id', 'title', 'description', 'parent_id', 'product_id',
                'category_id', 'block', 'sort_order', 'is_container',
                'cover_url', 'slug',
            ])
            ->orderBy('parent_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $lessonCounts = $this->lessonCountsByCourse();
        $viewedCounts = $userId ? $this->viewedCountsByCourse($userId) : collect();
        $testStatuses = $userId ? $this->testStatusesByCourse($userId) : collect();
        $hasTest = $this->hasTestByCourse();

        $byParent = [];
        foreach ($courses as $c) {
            $node = [
                'id' => $c->id,
                'title' => $c->title,
                'description' => $c->description,
                'parent_id' => $c->parent_id ? (int) $c->parent_id : null,
                'product_id' => $c->product_id,
                'category_id' => $c->category_id,
                'block' => $c->block,
                'sortOrder' => $c->sort_order,
                'isContainer' => (bool) $c->is_container,
                'coverUrl' => $c->cover_url,
                'slug' => $c->slug,
                'lessonCount' => $lessonCounts[$c->id] ?? 0,
                'lessonViewed' => $viewedCounts[$c->id] ?? 0,
                'hasTest' => (bool) ($hasTest[$c->id] ?? false),
                'testPassed' => (bool) ($testStatuses[$c->id] ?? false),
                'children' => [],
            ];
            $byParent[$c->parent_id ?? 0][] = $node;
        }

        $build = function (int $parentId) use (&$build, &$byParent) {
            $nodes = $byParent[$parentId] ?? [];
            foreach ($nodes as &$n) {
                $n['children'] = $build($n['id']);
            }
            return $nodes;
        };

        return $build(0);
    }

    /** Один курс с полной структурой уроков и блоков. */
    public function courseDetails(int $courseId, ?int $userId): ?array
    {
        $course = DB::table('education_courses')
            ->where('id', $courseId)
            ->where('active', true)
            ->first();
        if (! $course) return null;

        $lessons = DB::table('education_lessons')
            ->where('course_id', $courseId)
            ->where('active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $viewedSet = $userId
            ? array_flip(DB::table('education_lesson_views')
                ->where('user_id', $userId)
                ->whereIn('lesson_id', $lessons->pluck('id'))
                ->pluck('lesson_id')->all())
            : [];

        // Drip-расписание: для каждого урока считаем доступность
        // (учитываем drip_open_at / drip_delay_hours / стоп-уроки).
        // Если миграция drip-полей не накатилась — сервис вернёт open=true
        // для всего, что эквивалентно legacy-поведению.
        $drip = app(\App\Services\DripScheduleService::class);

        return [
            'id' => $course->id,
            'title' => $course->title,
            'description' => $course->description,
            'parent_id' => $course->parent_id ? (int) $course->parent_id : null,
            'isContainer' => (bool) $course->is_container,
            'coverUrl' => $course->cover_url,
            'productId' => $course->product_id,
            'lessons' => $lessons->map(function ($l) use ($viewedSet, $drip, $userId, $course) {
                $av = $drip->lessonAvailability($l, $userId, $course);
                return [
                    'id' => $l->id,
                    'title' => $l->title,
                    'description' => $l->content,    // legacy short-desc
                    'body' => $l->body ? (is_string($l->body) ? json_decode($l->body, true) : $l->body) : null,
                    'sortOrder' => $l->sort_order,
                    'videoUrls' => $l->video_urls ? (is_string($l->video_urls) ? json_decode($l->video_urls, true) : $l->video_urls) : [],
                    'documentUrls' => $l->document_urls ? (is_string($l->document_urls) ? json_decode($l->document_urls, true) : $l->document_urls) : [],
                    'viewed' => isset($viewedSet[$l->id]),
                    'isStopLesson' => (bool) ($l->is_stop_lesson ?? false),
                    'isTest' => (bool) ($l->is_test ?? false),
                    'requiresHomework' => (bool) ($l->requires_homework ?? false),
                    'homeworkInstructions' => $l->homework_instructions ?? null,
                    'available' => $av['open'],
                    'unavailableReason' => $av['reason'],
                    'unlockAt' => $av['unlockAt']?->toIso8601String(),
                ];
            })->values(),
            'breadcrumbs' => $this->breadcrumbs($courseId),
        ];
    }

    /** Хлебные крошки от корня до текущего курса. */
    public function breadcrumbs(int $courseId): array
    {
        $crumbs = [];
        $visited = [];
        $currentId = $courseId;
        for ($i = 0; $i < 6; $i++) {
            if (in_array($currentId, $visited, true)) break;
            $visited[] = $currentId;
            $row = DB::table('education_courses')
                ->where('id', $currentId)
                ->select('id', 'title', 'parent_id')->first();
            if (! $row) break;
            array_unshift($crumbs, ['id' => $row->id, 'title' => $row->title]);
            if (! $row->parent_id) break;
            $currentId = (int) $row->parent_id;
        }
        return $crumbs;
    }

    private function lessonCountsByCourse(): Collection
    {
        return DB::table('education_lessons')
            ->where('active', true)
            ->select('course_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('course_id')
            ->pluck('cnt', 'course_id');
    }

    private function viewedCountsByCourse(int $userId): Collection
    {
        return DB::table('education_lesson_views as v')
            ->join('education_lessons as l', 'l.id', '=', 'v.lesson_id')
            ->where('v.user_id', $userId)
            ->where('l.active', true)
            ->select('l.course_id', DB::raw('COUNT(DISTINCT v.lesson_id) as cnt'))
            ->groupBy('l.course_id')
            ->pluck('cnt', 'l.course_id');
    }

    private function hasTestByCourse(): Collection
    {
        return DB::table('education_tests')
            ->select('course_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('course_id')
            ->pluck('cnt', 'course_id');
    }

    private function testStatusesByCourse(int $userId): Collection
    {
        return DB::table('education_course_completions')
            ->where('user_id', $userId)
            ->pluck('course_id', 'course_id');
    }
}
