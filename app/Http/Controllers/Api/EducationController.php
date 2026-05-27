<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EducationController extends Controller
{
    /**
     * GET /education/tree — рекурсивное дерево курсов с прогрессом
     * текущего пользователя (per ТЗ Жосан 25.05.2026: Курс → Модуль →
     * Подмодуль → Урок). Заменяет плоский /education/courses для нового
     * UI; старый endpoint оставлен для обратной совместимости.
     */
    public function tree(Request $request, \App\Services\EducationTreeService $svc): JsonResponse
    {
        $tree = $svc->fullTree($request->user()->id);
        return response()->json(['tree' => $tree]);
    }

    /**
     * GET /education/courses/{id}/full — курс с полной структурой уроков
     * (включая body-конструктор) + хлебные крошки. Используется страницей
     * урока в новом UI.
     */
    public function courseFull(Request $request, int $id, \App\Services\EducationTreeService $svc): JsonResponse
    {
        $data = $svc->courseDetails($id, $request->user()->id);
        if (! $data) return response()->json(['message' => 'Курс не найден'], 404);
        return response()->json($data);
    }

    /**
     * GET /education/search?q=… — общий поиск по курсам/урокам/тегам
     * (per ТЗ Жосан §19, для MVP — только по названиям). Возвращает
     * не более 30 результатов с типом (course/lesson/kb).
     */
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) return response()->json(['items' => []]);
        $like = '%' . mb_strtolower($q) . '%';

        $courses = DB::table('education_courses')
            ->where('active', true)
            ->whereRaw('LOWER(title) LIKE ?', [$like])
            ->limit(15)
            ->get(['id', 'title', 'parent_id'])
            ->map(fn ($c) => [
                'type' => 'course', 'id' => $c->id, 'title' => $c->title,
                'parent_id' => $c->parent_id,
            ]);

        $lessons = DB::table('education_lessons')
            ->where('active', true)
            ->whereRaw('LOWER(title) LIKE ?', [$like])
            ->limit(15)
            ->get(['id', 'title', 'course_id'])
            ->map(fn ($l) => [
                'type' => 'lesson', 'id' => $l->id, 'title' => $l->title,
                'courseId' => $l->course_id,
            ]);

        $kb = Schema::hasTable('education_kb_articles')
            ? DB::table('education_kb_articles')
                ->whereNull('deleted_at')
                ->where('published', true)
                ->whereRaw('LOWER(title) LIKE ?', [$like])
                ->limit(15)
                ->get(['id', 'title', 'section_id'])
                ->map(fn ($a) => [
                    'type' => 'kb_article', 'id' => $a->id, 'title' => $a->title,
                    'sectionId' => $a->section_id,
                ])
            : collect();

        $items = $courses->concat($lessons)->concat($kb)->take(30)->values();
        return response()->json(['items' => $items]);
    }

    /**
     * GET /education/kb — дерево разделов и подразделов базы знаний.
     * Сами материалы тянутся отдельно по разделу через kb/sections/{id}.
     */
    public function kbTree(): JsonResponse
    {
        if (! Schema::hasTable('education_kb_sections')) {
            return response()->json(['sections' => []]);
        }
        $rows = DB::table('education_kb_sections')
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->get();
        $counts = Schema::hasTable('education_kb_articles')
            ? DB::table('education_kb_articles')
                ->whereNull('deleted_at')
                ->where('published', true)
                ->select('section_id', DB::raw('COUNT(*) as cnt'))
                ->groupBy('section_id')
                ->pluck('cnt', 'section_id')
            : collect();

        $byParent = [];
        foreach ($rows as $r) {
            $byParent[$r->parent_id ?? 0][] = [
                'id' => $r->id, 'title' => $r->title, 'icon' => $r->icon,
                'description' => $r->description, 'coverUrl' => $r->cover_url,
                'slug' => $r->slug,
                'articleCount' => (int) ($counts[$r->id] ?? 0),
                'children' => [],
            ];
        }
        $build = function (int $p) use (&$build, &$byParent) {
            $out = $byParent[$p] ?? [];
            foreach ($out as &$n) $n['children'] = $build($n['id']);
            return $out;
        };
        return response()->json(['sections' => $build(0)]);
    }

    /**
     * GET /education/kb/sections/{id} — раздел: материалы + подразделы +
     * хлебные крошки. Подразделы добавлены потому, что без них партнёр,
     * провалившись в раздел с детьми, упирается в пустой список.
     */
    public function kbSection(int $id): JsonResponse
    {
        if (! Schema::hasTable('education_kb_sections')) {
            return response()->json(['section' => null, 'subsections' => [], 'articles' => [], 'breadcrumbs' => []]);
        }

        $section = DB::table('education_kb_sections')
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->first();
        if (! $section) {
            return response()->json(['message' => 'Раздел не найден'], 404);
        }

        $childSections = DB::table('education_kb_sections')
            ->where('parent_id', $id)
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->get();

        $articleCounts = Schema::hasTable('education_kb_articles')
            ? DB::table('education_kb_articles')
                ->whereNull('deleted_at')
                ->where('published', true)
                ->select('section_id', DB::raw('COUNT(*) as cnt'))
                ->groupBy('section_id')
                ->pluck('cnt', 'section_id')
            : collect();

        $subChildCounts = DB::table('education_kb_sections')
            ->whereNull('deleted_at')
            ->whereIn('parent_id', $childSections->pluck('id'))
            ->select('parent_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('parent_id')
            ->pluck('cnt', 'parent_id');

        $subsections = $childSections->map(fn ($s) => [
            'id' => $s->id,
            'title' => $s->title,
            'icon' => $s->icon,
            'description' => $s->description,
            'coverUrl' => $s->cover_url,
            'slug' => $s->slug,
            'articleCount' => (int) ($articleCounts[$s->id] ?? 0),
            'childCount' => (int) ($subChildCounts[$s->id] ?? 0),
        ])->values();

        $articles = Schema::hasTable('education_kb_articles')
            ? DB::table('education_kb_articles')
                ->where('section_id', $id)
                ->whereNull('deleted_at')
                ->where('published', true)
                ->orderBy('sort_order')
                ->get(['id', 'title', 'description', 'tags', 'sort_order'])
                ->map(fn ($a) => [
                    'id' => $a->id, 'title' => $a->title, 'description' => $a->description,
                    'tags' => $a->tags ? (is_string($a->tags) ? json_decode($a->tags, true) : $a->tags) : [],
                ])
            : collect();

        $breadcrumbs = [];
        $cursor = $section;
        $guard = 0;
        while ($cursor && $guard++ < 16) {
            array_unshift($breadcrumbs, ['id' => $cursor->id, 'title' => $cursor->title]);
            if (! $cursor->parent_id) break;
            $cursor = DB::table('education_kb_sections')
                ->where('id', $cursor->parent_id)
                ->whereNull('deleted_at')
                ->first();
        }

        return response()->json([
            'section' => [
                'id' => $section->id,
                'title' => $section->title,
                'description' => $section->description,
                'icon' => $section->icon,
            ],
            'subsections' => $subsections,
            'articles' => $articles,
            'breadcrumbs' => $breadcrumbs,
        ]);
    }

    /**
     * GET /education/kb/articles/{id} — материал с полным body для просмотра.
     */
    public function kbArticle(int $id): JsonResponse
    {
        if (! Schema::hasTable('education_kb_articles')) abort(404);
        $a = DB::table('education_kb_articles')
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->where('published', true)
            ->first();
        if (! $a) return response()->json(['message' => 'Материал не найден'], 404);

        return response()->json([
            'id' => $a->id,
            'title' => $a->title,
            'description' => $a->description,
            'body' => $a->body ? (is_string($a->body) ? json_decode($a->body, true) : $a->body) : null,
            'tags' => $a->tags ? (is_string($a->tags) ? json_decode($a->tags, true) : $a->tags) : [],
            'sectionId' => $a->section_id,
        ]);
    }

    /**
     * List of active courses with per-user progress.
     * A course is "completed" when every active lesson has a view record
     * and there is a course-completion entry from a passed test.
     */
    public function courses(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $courses = DB::table('education_courses')
            ->where('active', true)
            ->orderBy('sort_order')
            ->get();

        if ($courses->isEmpty()) {
            return response()->json(['data' => []]);
        }

        $courseIds = $courses->pluck('id')->all();

        $lessonTotals = DB::table('education_lessons')
            ->select('course_id', DB::raw('COUNT(*) AS total'))
            ->where('active', true)
            ->whereIn('course_id', $courseIds)
            ->groupBy('course_id')
            ->pluck('total', 'course_id');

        $lessonViewed = DB::table('education_lesson_views AS v')
            ->join('education_lessons AS l', 'l.id', '=', 'v.lesson_id')
            ->select('l.course_id', DB::raw('COUNT(*) AS viewed'))
            ->where('v.user_id', $userId)
            ->where('l.active', true)
            ->whereIn('l.course_id', $courseIds)
            ->groupBy('l.course_id')
            ->pluck('viewed', 'course_id');

        $completions = DB::table('education_course_completions')
            ->where('user_id', $userId)
            ->whereIn('course_id', $courseIds)
            ->get()
            ->keyBy('course_id');

        // Категории (миграция 2026_05_21_000020) — отдаём id+name, чтобы
        // витрина группировала курсы по ним вместо легаси-блоков.
        $hasCategory = Schema::hasColumn('education_courses', 'category_id');
        $categories = $hasCategory && Schema::hasTable('education_course_categories')
            ? DB::table('education_course_categories')
                ->whereNull('deleted_at')
                ->where('active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name', 'sort_order'])
            : collect();
        $categoryNameById = $categories->pluck('name', 'id');

        $data = $courses->map(function ($c) use ($lessonTotals, $lessonViewed, $completions, $hasCategory, $categoryNameById) {
            $total = (int) ($lessonTotals[$c->id] ?? 0);
            $viewed = (int) ($lessonViewed[$c->id] ?? 0);
            $completion = $completions[$c->id] ?? null;
            $testPassed = (bool) $completion;
            $allLessonsViewed = $total > 0 && $viewed >= $total;
            $categoryId = $hasCategory ? ($c->category_id ?? null) : null;

            return [
                'id' => $c->id,
                'title' => $c->title,
                'description' => $c->description,
                'product_id' => $c->product_id,
                // Per spec ✅Обучение §3 — 9 блоков + 0 «База знаний». Оставлен
                // для бакауорд-совместимости (фронт fallback'ит сюда, если
                // ни одной категории нет).
                'block' => $c->block ?? 0,
                'category_id' => $categoryId,
                'categoryName' => $categoryId ? ($categoryNameById[$categoryId] ?? null) : null,
                'lessonCount' => $total,
                'lessonViewed' => $viewed,
                'testPassed' => $testPassed,
                'testScore' => $completion?->score,
                'testTotal' => $completion?->total,
                'completed' => $testPassed && $allLessonsViewed,
            ];
        })->values();

        return response()->json([
            'data' => $data,
            'categories' => $categories->map(fn ($cat) => [
                'id' => $cat->id,
                'name' => $cat->name,
                'sort_order' => (int) $cat->sort_order,
            ])->values(),
        ]);
    }

    /**
     * Single course with lessons and test questions.
     * Correct answers are NEVER returned to the client.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $userId = $request->user()->id;

        $course = DB::table('education_courses')->where('id', $id)->where('active', true)->first();
        if (! $course) {
            return response()->json(['message' => 'Курс не найден'], 404);
        }

        $lessons = DB::table('education_lessons')
            ->where('course_id', $id)
            ->where('active', true)
            ->orderBy('sort_order')
            ->get();

        $viewedIds = DB::table('education_lesson_views')
            ->where('user_id', $userId)
            ->whereIn('lesson_id', $lessons->pluck('id'))
            ->pluck('lesson_id')
            ->all();
        $viewedSet = array_flip($viewedIds);

        $tests = DB::table('education_tests')
            ->where('course_id', $id)
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'question' => $t->question,
                'answers' => json_decode($t->answers, true) ?: [],
            ]);

        $completion = DB::table('education_course_completions')
            ->where('user_id', $userId)
            ->where('course_id', $id)
            ->first();

        return response()->json([
            'course' => [
                'id' => $course->id,
                'title' => $course->title,
                'description' => $course->description,
                'product_id' => $course->product_id,
            ],
            'lessons' => $lessons->map(function ($l) use ($viewedSet) {
                $hasArrays = Schema::hasColumn('education_lessons', 'video_urls');
                $videos = $this->expandUrlArray($hasArrays ? ($l->video_urls ?? null) : null, $l->video_url ?? null);
                $docs = $this->expandUrlArray($hasArrays ? ($l->document_urls ?? null) : null, $l->document_url ?? null);
                return [
                    'id' => $l->id,
                    'title' => $l->title,
                    'content' => $l->content,
                    'content_type' => $l->content_type,
                    // Legacy single-поля оставлены на случай старого фронта.
                    'video_url' => $videos[0] ?? null,
                    'document_url' => $docs[0] ?? null,
                    'video_urls' => $videos,
                    'document_urls' => $docs,
                    'is_test' => (bool) ($l->is_test ?? false),
                    'viewed' => isset($viewedSet[$l->id]),
                ];
            })->values(),
            'tests' => $tests,
            'completion' => $completion ? [
                'score' => $completion->score,
                'total' => $completion->total,
                'completed_at' => $completion->completed_at,
            ] : null,
        ]);
    }

    /**
     * Разворачиваем JSONB-массив элементов урока к [{url, label}, ...].
     * Поддерживаем legacy-форматы: массив строк и одиночный video_url/
     * document_url. Дублирует AdminEducationController::urlArray.
     */
    private function expandUrlArray($jsonbValue, $legacySingle): array
    {
        $items = [];
        if ($jsonbValue !== null && $jsonbValue !== '') {
            $decoded = is_array($jsonbValue) ? $jsonbValue : json_decode((string) $jsonbValue, true);
            if (is_array($decoded)) {
                foreach ($decoded as $item) {
                    if (is_string($item) && trim($item) !== '') {
                        $items[] = ['url' => trim($item), 'label' => null];
                    } elseif (is_array($item) && isset($item['url']) && trim((string) $item['url']) !== '') {
                        $items[] = [
                            'url' => trim((string) $item['url']),
                            'label' => isset($item['label']) && trim((string) $item['label']) !== ''
                                ? trim((string) $item['label']) : null,
                        ];
                    }
                }
            }
        }
        if (! $items && $legacySingle) {
            $items[] = ['url' => $legacySingle, 'label' => null];
        }
        return $items;
    }

    /** Mark a lesson as viewed (idempotent upsert). */
    public function markLessonViewed(Request $request, int $id): JsonResponse
    {
        $userId = $request->user()->id;

        $lesson = DB::table('education_lessons')->where('id', $id)->where('active', true)->first();
        if (! $lesson) {
            return response()->json(['message' => 'Урок не найден'], 404);
        }

        // Drip-feed: если урок ещё не открыт по расписанию — не даём
        // его «изученным», иначе релейтив-таймер становится бесполезным.
        $course = DB::table('education_courses')->where('id', $lesson->course_id)->first();
        if ($course) {
            $av = app(\App\Services\DripScheduleService::class)
                ->lessonAvailability($lesson, (int) $userId, $course);
            if (! $av['open']) {
                return response()->json([
                    'message' => $av['reason'] ?? 'Урок ещё не открыт',
                ], 423);
            }

            // Регистрируем первый вход в курс — для anchor='first_login'.
            app(\App\Services\DripScheduleService::class)
                ->markCourseStarted((int) $userId, (int) $lesson->course_id);
        }

        DB::table('education_lesson_views')->upsert(
            [[
                'user_id' => $userId,
                'lesson_id' => $id,
                'viewed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['user_id', 'lesson_id'],
            ['viewed_at', 'updated_at']
        );

        return response()->json(['viewed' => true]);
    }

    /**
     * Submit test answers for a course.
     * Passing requires ALL answers correct (100%).
     * Failed attempts do not persist — user may retry.
     */
    public function submitTest(Request $request, int $id): JsonResponse
    {
        $userId = $request->user()->id;

        $course = DB::table('education_courses')->where('id', $id)->where('active', true)->first();
        if (! $course) {
            return response()->json(['message' => 'Курс не найден'], 404);
        }

        $answers = $request->validate([
            'answers' => ['required', 'array'],
            'answers.*' => ['nullable', 'integer'],
        ])['answers'];

        $questions = DB::table('education_tests')
            ->where('course_id', $id)
            ->orderBy('sort_order')
            ->get();

        if ($questions->isEmpty()) {
            return response()->json(['message' => 'К курсу не привязаны вопросы'], 422);
        }

        $total = $questions->count();
        $correct = 0;
        foreach ($questions as $q) {
            $userAnswer = $answers[$q->id] ?? null;
            if ($userAnswer !== null && (int) $userAnswer === (int) $q->correct_answer) {
                $correct++;
            }
        }

        $passed = $correct === $total;

        // Сохраняем КАЖДУЮ попытку (включая неудачные) для куратора —
        // он анализирует на каких вопросах партнёры спотыкаются и
        // сколько раз пробуют до успеха. Completion-таблица остаётся
        // источником «прошёл/не прошёл», attempts — историческим логом.
        DB::table('education_test_attempts')->insert([
            'user_id' => $userId,
            'course_id' => $id,
            'score' => $correct,
            'total' => $total,
            'passed' => $passed,
            'attempted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($passed) {
            DB::table('education_course_completions')->upsert(
                [[
                    'user_id' => $userId,
                    'course_id' => $id,
                    'score' => $correct,
                    'total' => $total,
                    'completed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]],
                ['user_id', 'course_id'],
                ['score', 'total', 'completed_at', 'updated_at']
            );
        }

        return response()->json([
            'passed' => $passed,
            'score' => $correct,
            'total' => $total,
        ]);
    }
}
