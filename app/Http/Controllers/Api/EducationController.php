<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EducationController extends Controller
{
    private function db(): ConnectionInterface
    {
        return DB::connection('pgsql_v2');
    }

    private function schema(): Builder
    {
        return Schema::connection('pgsql_v2');
    }

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
        $minChars = (int) \App\Models\SystemSetting::value('education.search_min_chars', 2);
        if (mb_strlen($q) < $minChars) return response()->json(['items' => []]);
        $like = '%' . mb_strtolower($q) . '%';

        $courses = $this->db()->table('education_courses')
            ->where('active', true)
            ->whereRaw('LOWER(title) LIKE ?', [$like])
            ->limit(15)
            ->get(['id', 'title', 'parent_id'])
            ->map(fn ($c) => [
                'type' => 'course', 'id' => $c->id, 'title' => $c->title,
                'parent_id' => $c->parent_id,
            ]);

        $lessons = $this->db()->table('education_lessons')
            ->where('active', true)
            ->whereRaw('LOWER(title) LIKE ?', [$like])
            ->limit(15)
            ->get(['id', 'title', 'course_id'])
            ->map(fn ($l) => [
                'type' => 'lesson', 'id' => $l->id, 'title' => $l->title,
                'courseId' => $l->course_id,
            ]);

        $kb = Schema::connection('pgsql_v2')->hasTable('education_kb_articles')
            ? DB::connection('pgsql_v2')->table('education_kb_articles')
                ->whereNull('deleted_at')
                ->where('status', 'published')
                ->whereRaw('LOWER(title) LIKE ?', [$like])
                ->limit(15)
                ->get(['id', 'title', 'section_id'])
                ->map(fn ($a) => [
                    'type' => 'kb_article', 'id' => $a->id, 'title' => $a->title,
                    'sectionId' => $a->section_id,
                ])
            : collect();

        $limit = (int) \App\Models\SystemSetting::value('education.search_limit', 30);
        $items = $courses->concat($lessons)->concat($kb)->take($limit)->values();
        return response()->json(['items' => $items]);
    }

    /**
     * GET /education/kb — дерево разделов и подразделов базы знаний.
     * Сами материалы тянутся отдельно по разделу через kb/sections/{id}.
     */
    public function kbTree(): JsonResponse
    {
        if (! Schema::connection('pgsql_v2')->hasTable('education_kb_sections')) {
            return response()->json(['sections' => []]);
        }
        $rows = DB::connection('pgsql_v2')->table('education_kb_sections')
            ->whereNull('deleted_at')
            ->where('active', true)
            ->orderBy('sort_order')
            ->get();
        $counts = Schema::connection('pgsql_v2')->hasTable('education_kb_articles')
            ? DB::connection('pgsql_v2')->table('education_kb_articles')
                ->whereNull('deleted_at')
                ->where('status', 'published')
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
        if (! Schema::connection('pgsql_v2')->hasTable('education_kb_sections')) {
            return response()->json(['section' => null, 'subsections' => [], 'articles' => [], 'breadcrumbs' => []]);
        }

        $section = DB::connection('pgsql_v2')->table('education_kb_sections')
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->where('active', true)
            ->first();
        if (! $section) {
            return response()->json(['message' => 'Раздел не найден'], 404);
        }

        $childSections = DB::connection('pgsql_v2')->table('education_kb_sections')
            ->where('parent_id', $id)
            ->whereNull('deleted_at')
            ->where('active', true)
            ->orderBy('sort_order')
            ->get();

        $articleCounts = Schema::connection('pgsql_v2')->hasTable('education_kb_articles')
            ? DB::connection('pgsql_v2')->table('education_kb_articles')
                ->whereNull('deleted_at')
                ->where('status', 'published')
                ->select('section_id', DB::raw('COUNT(*) as cnt'))
                ->groupBy('section_id')
                ->pluck('cnt', 'section_id')
            : collect();

        $subChildCounts = DB::connection('pgsql_v2')->table('education_kb_sections')
            ->whereNull('deleted_at')
            ->where('active', true)
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

        $articles = Schema::connection('pgsql_v2')->hasTable('education_kb_articles')
            ? DB::connection('pgsql_v2')->table('education_kb_articles')
                ->where('section_id', $id)
                ->whereNull('deleted_at')
                ->where('status', 'published')
                ->orderBy('sort_order')
                ->get(['id', 'title', 'summary', 'tags', 'sort_order'])
                ->map(fn ($a) => [
                    'id' => $a->id, 'title' => $a->title, 'description' => $a->summary,
                    'tags' => $a->tags ? (is_string($a->tags) ? json_decode($a->tags, true) : $a->tags) : [],
                ])
            : collect();

        $breadcrumbs = [];
        $cursor = $section;
        $guard = 0;
        while ($cursor && $guard++ < 16) {
            array_unshift($breadcrumbs, ['id' => $cursor->id, 'title' => $cursor->title]);
            if (! $cursor->parent_id) break;
            $cursor = DB::connection('pgsql_v2')->table('education_kb_sections')
                ->where('id', $cursor->parent_id)
                ->whereNull('deleted_at')
                ->where('active', true)
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
        if (! Schema::connection('pgsql_v2')->hasTable('education_kb_articles')) abort(404);
        $a = DB::connection('pgsql_v2')->table('education_kb_articles')
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->where('status', 'published')
            ->first();
        if (! $a) return response()->json(['message' => 'Материал не найден'], 404);

        return response()->json([
            'id' => $a->id,
            'title' => $a->title,
            'description' => $a->summary,
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

        $courses = $this->db()->table('education_courses')
            ->where('active', true)
            ->orderBy('sort_order')
            ->get();

        if ($courses->isEmpty()) {
            return response()->json(['data' => []]);
        }

        $courseIds = $courses->pluck('id')->all();

        $lessonTotals = $this->db()->table('education_lessons')
            ->select('course_id', DB::raw('COUNT(*) AS total'))
            ->where('active', true)
            ->whereIn('course_id', $courseIds)
            ->groupBy('course_id')
            ->pluck('total', 'course_id');

        $lessonViewed = $this->db()->table('education_lesson_views AS v')
            ->join('education_lessons AS l', 'l.id', '=', 'v.lesson_id')
            ->select('l.course_id', DB::raw('COUNT(*) AS viewed'))
            ->where('v.user_id', $userId)
            ->where('l.active', true)
            ->whereIn('l.course_id', $courseIds)
            ->groupBy('l.course_id')
            ->pluck('viewed', 'course_id');

        $completions = $this->db()->table('education_course_completions')
            ->where('user_id', $userId)
            ->whereIn('course_id', $courseIds)
            ->get()
            ->keyBy('course_id');

        // Категории (миграция 2026_05_21_000020) — отдаём id+name, чтобы
        // витрина группировала курсы по ним вместо легаси-блоков.
        $hasCategory = $this->schema()->hasColumn('education_courses', 'category_id');
        $categories = $hasCategory && $this->schema()->hasTable('education_course_categories')
            ? $this->db()->table('education_course_categories')
                ->whereNull('deleted_at')
                ->where('active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name', 'sort_order'])
            : collect();
        $categoryNameById = $categories->pluck('name', 'id');

        $primaryProducts = $this->db()->table('education_course_product')
            ->whereIn('course_id', $courseIds)
            ->orderByDesc('is_primary')
            ->orderBy('product_id')
            ->get(['course_id', 'product_id'])
            ->unique('course_id')
            ->pluck('product_id', 'course_id');

        $data = $courses->map(function ($c) use ($lessonTotals, $lessonViewed, $completions, $hasCategory, $categoryNameById, $primaryProducts) {
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
                'product_id' => $primaryProducts[$c->id] ?? null,
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
                // «Пройден» = тест сдан (единый критерий со всей платформой:
                // дерево, гейт продукта, экран теста). Просмотр всех уроков —
                // отдельный прогресс (lessonViewed/«изучен»), не «пройден».
                'allLessonsViewed' => $allLessonsViewed,
                'completed' => $testPassed,
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

        $course = $this->db()->table('education_courses')->where('id', $id)->where('active', true)->first();
        if (! $course) {
            return response()->json(['message' => 'Курс не найден'], 404);
        }

        $lessons = $this->db()->table('education_lessons')
            ->where('course_id', $id)
            ->where('active', true)
            ->orderBy('sort_order')
            ->get();

        $viewedIds = $this->db()->table('education_lesson_views')
            ->where('user_id', $userId)
            ->whereIn('lesson_id', $lessons->pluck('id'))
            ->pluck('lesson_id')
            ->all();
        $viewedSet = array_flip($viewedIds);

        // Drip availability — batch to avoid N+1.
        // Only run per-lesson checks when the course actually uses drip/stop config.
        $hasDrip = $this->schema()->hasColumn('education_lessons', 'drip_open_at')
            && $lessons->contains(fn ($l) =>
                ! empty($l->drip_open_at)
                || (! empty($l->drip_delay_hours) && (int) $l->drip_delay_hours > 0)
                || ! empty($l->is_stop_lesson)
            );
        $availabilityMap = [];
        if ($hasDrip) {
            $drip = app(\App\Services\DripScheduleService::class);
            foreach ($lessons as $l) {
                $availabilityMap[$l->id] = $drip->lessonAvailability($l, $userId, $course);
            }
        }

        $tests = $this->db()->table('education_tests')
            ->where('course_id', $id)
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'question' => $t->question,
                'answers' => json_decode($t->answers, true) ?: [],
            ]);

        $completion = $this->db()->table('education_course_completions')
            ->where('user_id', $userId)
            ->where('course_id', $id)
            ->first();

        // Привязки для разблокировки (many-to-many). product_ids — целые
        // продукты, program_ids — конкретные программы (пусто = все программы
        // привязанных продуктов). Гейт пока не enforce, но фронт/витрина читают
        // полный набор. product_id (scalar) оставлен для бэкуорд-совместимости.
        $productIds = $this->schema()->hasTable('education_course_product')
            ? $this->db()->table('education_course_product')->where('course_id', $id)
                ->orderByDesc('is_primary')->orderBy('product_id')
                ->pluck('product_id')->map(fn ($v) => (int) $v)->all()
            : [];
        $programIds = $this->schema()->hasTable('education_course_program')
            ? $this->db()->table('education_course_program')->where('course_id', $id)->orderBy('program_id')
                ->pluck('program_id')->map(fn ($v) => (int) $v)->all()
            : [];

        return response()->json([
            'course' => [
                'id' => $course->id,
                'title' => $course->title,
                'description' => $course->description,
                'product_id' => $productIds[0] ?? null,
                'product_ids' => $productIds,
                'program_ids' => $programIds,
            ],
            'lessons' => $lessons->map(function ($l) use ($viewedSet, $hasDrip, $availabilityMap) {
                $hasArrays = $this->schema()->hasColumn('education_lessons', 'video_urls');
                $videos = $this->expandUrlArray($hasArrays ? ($l->video_urls ?? null) : null, $l->video_url ?? null);
                $docs = $this->expandUrlArray($hasArrays ? ($l->document_urls ?? null) : null, $l->document_url ?? null);
                $av = $hasDrip ? ($availabilityMap[$l->id] ?? ['open' => true, 'reason' => null]) : ['open' => true, 'reason' => null];
                $body = null;
                if (! empty($l->body)) {
                    $decoded = is_array($l->body) ? $l->body : json_decode($l->body, true);
                    if (is_array($decoded)) $body = $decoded;
                }
                return [
                    'id' => $l->id,
                    'title' => $l->title,
                    'content' => $l->content,
                    'contentType' => $l->content_type,
                    'body' => $body,
                    'videoUrls' => $videos,
                    'documentUrls' => $docs,
                    'isTest' => (bool) ($l->is_test ?? false),
                    'viewed' => isset($viewedSet[$l->id]),
                    'available' => $av['open'],
                    'unavailableReason' => $av['reason'],
                    'requiresHomework' => (bool) ($l->requires_homework ?? false),
                    'homeworkInstructions' => $l->homework_instructions ?? null,
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

        $lesson = $this->db()->table('education_lessons')->where('id', $id)->where('active', true)->first();
        if (! $lesson) {
            return response()->json(['message' => 'Урок не найден'], 404);
        }

        // Drip-feed: если урок ещё не открыт по расписанию — не даём
        // его «изученным», иначе релейтив-таймер становится бесполезным.
        $course = $this->db()->table('education_courses')->where('id', $lesson->course_id)->first();
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

        $this->db()->table('education_lesson_views')->upsert(
            [[
                'user_id' => $userId,
                'lesson_id' => $id,
                'first_viewed_at' => now(),
                'last_viewed_at' => now(),
                'completed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['user_id', 'lesson_id'],
            ['last_viewed_at', 'completed_at', 'updated_at']
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

        $course = $this->db()->table('education_courses')->where('id', $id)->where('active', true)->first();
        if (! $course) {
            return response()->json(['message' => 'Курс не найден'], 404);
        }

        $answers = $request->validate([
            'answers' => ['required', 'array'],
            'answers.*' => ['nullable', 'integer'],
        ])['answers'];

        $questions = $this->db()->table('education_tests')
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
            $correctAnswers = is_array($q->correct_answers)
                ? $q->correct_answers
                : json_decode((string) $q->correct_answers, true);
            if ($userAnswer !== null && in_array((int) $userAnswer, array_map('intval', $correctAnswers ?: []), true)) {
                $correct++;
            }
        }

        // Порог сдачи настраивается из админки (Обучение), фолбэк 100% (всё верно).
        $passPercent = (int) \App\Models\SystemSetting::value('education.pass_percent', 100);
        $passed = $total > 0 && ($correct * 100 / $total) >= $passPercent;

        // Сохраняем КАЖДУЮ попытку (включая неудачные) для куратора —
        // он анализирует на каких вопросах партнёры спотыкаются и
        // сколько раз пробуют до успеха. Completion-таблица остаётся
        // источником «прошёл/не прошёл», attempts — историческим логом.
        $attemptId = $this->db()->table('education_test_attempts')->insertGetId([
            'user_id' => $userId,
            'course_id' => $id,
            'score' => $correct,
            'max_score' => $total,
            'passed' => $passed,
            'answers' => json_encode($answers, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($passed) {
            $this->db()->table('education_course_completions')->upsert(
                [[
                    'user_id' => $userId,
                    'course_id' => $id,
                    'test_attempt_id' => $attemptId,
                    'score' => $correct,
                    'total' => $total,
                    'completed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]],
                ['user_id', 'course_id'],
                ['test_attempt_id', 'score', 'total', 'completed_at', 'updated_at']
            );

            // Сдача теста = урок-тест пройден: помечаем урок(и) is_test
            // изученными, иначе в списке «Уроки курса» тест висит «не изучен»
            // и прогресс не доходит до 100% (изучено 4 из 5), хотя тест сдан.
            if ($this->schema()->hasColumn('education_lessons', 'is_test')) {
                $testLessonIds = $this->db()->table('education_lessons')
                    ->where('course_id', $id)->where('is_test', true)->pluck('id');
                foreach ($testLessonIds as $lessonId) {
                    $this->db()->table('education_lesson_views')->insertOrIgnore([
                        'user_id' => $userId,
                        'lesson_id' => $lessonId,
                        'first_viewed_at' => now(),
                        'last_viewed_at' => now(),
                        'completed_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        // Номер/количество попыток и процент правильных — для экрана результата
        // (партнёр видит «попытка №N» и «X% правильных»).
        $attempts = $this->db()->table('education_test_attempts')
            ->where('user_id', $userId)->where('course_id', $id)->count();
        $percent = $total > 0 ? (int) round($correct * 100 / $total) : 0;

        return response()->json([
            'passed' => $passed,
            'score' => $correct,
            'total' => $total,
            'percent' => $percent,
            'attempt' => $attempts,
            'attempts' => $attempts,
        ]);
    }
}
