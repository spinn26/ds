<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\XlsxExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminEducationController extends Controller
{
    public function __construct()
    {
        $this->ensureTablesExist();
    }

    /**
     * GET /admin/education/analytics
     *
     * Сводка по обучению: на каждого партнёра — сколько уроков просмотрено,
     * сколько курсов пройдено (тест 100 %), последняя активность. Поддержка
     * фильтров по партнёру и курсу для куратора обучения.
     *
     * Query: search (по ФИО), course_id, page (по умолчанию 1), per (25).
     */
    public function analytics(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('search', ''));
        $courseId = (int) $request->input('course_id', 0);
        $page = max(1, (int) $request->input('page', 1));
        $per = min(100, max(10, (int) $request->input('per', 25)));

        // База — все WebUser с ролью consultant (потенциальные обучающиеся).
        $usersQuery = DB::table('WebUser')
            ->where('role', 'like', '%consultant%')
            ->whereNull('dateDeleted');
        if ($search !== '') {
            $usersQuery->where(function ($q) use ($search) {
                $q->where('lastName', 'ilike', "%{$search}%")
                  ->orWhere('firstName', 'ilike', "%{$search}%")
                  ->orWhere('email', 'ilike', "%{$search}%");
            });
        }
        $total = (clone $usersQuery)->count();
        $users = $usersQuery
            ->orderBy('lastName')->orderBy('firstName')
            ->forPage($page, $per)
            ->get(['id', 'lastName', 'firstName', 'email']);
        if ($users->isEmpty()) {
            return response()->json(['data' => [], 'total' => $total]);
        }

        $userIds = $users->pluck('id');
        $totalCourses = $courseId > 0 ? 1
            : (int) DB::table('education_courses')->where('active', true)->count();

        // Просмотренные уроки.
        $viewQ = DB::table('education_lesson_views as v')
            ->join('education_lessons as l', 'l.id', '=', 'v.lesson_id')
            ->whereIn('v.user_id', $userIds);
        if ($courseId > 0) $viewQ->where('l.course_id', $courseId);
        $views = $viewQ
            ->select('v.user_id', DB::raw('COUNT(*) as cnt'), DB::raw('MAX(v.viewed_at) as last_viewed'))
            ->groupBy('v.user_id')
            ->get()->keyBy('user_id');

        // Пройденные курсы.
        $compQ = DB::table('education_course_completions')
            ->whereIn('user_id', $userIds);
        if ($courseId > 0) $compQ->where('course_id', $courseId);
        $completions = $compQ
            ->select('user_id',
                DB::raw('COUNT(*) as cnt'),
                DB::raw('AVG(score::float / NULLIF(total,0)) as avg_pct'),
                DB::raw('MAX(completed_at) as last_completed'))
            ->groupBy('user_id')
            ->get()->keyBy('user_id');

        // История попыток (все, включая неудачные).
        $attempts = collect();
        if (Schema::hasTable('education_test_attempts')) {
            $attemptQ = DB::table('education_test_attempts')
                ->whereIn('user_id', $userIds);
            if ($courseId > 0) $attemptQ->where('course_id', $courseId);
            $attempts = $attemptQ
                ->select('user_id',
                    DB::raw('COUNT(*) as total_attempts'),
                    DB::raw('SUM(CASE WHEN passed THEN 1 ELSE 0 END) as passed_attempts'))
                ->groupBy('user_id')
                ->get()->keyBy('user_id');
        }

        $data = $users->map(function ($u) use ($views, $completions, $attempts, $totalCourses) {
            $v = $views[$u->id] ?? null;
            $c = $completions[$u->id] ?? null;
            $a = $attempts[$u->id] ?? null;
            $lastActivity = max(
                $v?->last_viewed ?? '0',
                $c?->last_completed ?? '0',
            );
            return [
                'user_id' => $u->id,
                'name' => trim(($u->lastName ?? '') . ' ' . ($u->firstName ?? '')) ?: ($u->email ?? '—'),
                'email' => $u->email,
                'lessons_viewed' => (int) ($v->cnt ?? 0),
                'courses_completed' => (int) ($c->cnt ?? 0),
                'courses_total' => $totalCourses,
                'avg_score_pct' => $c && $c->avg_pct !== null ? round($c->avg_pct * 100, 1) : null,
                'test_attempts' => (int) ($a->total_attempts ?? 0),
                'test_passed' => (int) ($a->passed_attempts ?? 0),
                'last_activity' => $lastActivity !== '0' ? $lastActivity : null,
            ];
        })->values();

        return response()->json(['data' => $data, 'total' => $total]);
    }

    /**
     * GET /admin/education/analytics/export
     *
     * Стилизованный XLSX (зелёная шапка, freeze panes, autofilter,
     * форматы чисел и процентов). Без пагинации.
     */
    public function analyticsExport(Request $request, XlsxExportService $xlsx): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $request->merge(['per' => 100000, 'page' => 1]);
        $data = $this->analytics($request)->getData(true)['data'] ?? [];

        $headers = [
            'Партнёр', 'E-mail',
            'Просмотрено уроков', 'Пройдено курсов', 'Всего курсов',
            'Средний балл, %',
            'Попыток тестов', 'Сдач из попыток',
            'Последняя активность',
        ];
        $rows = array_map(fn ($r) => [
            $r['name'], $r['email'] ?? '',
            $r['lessons_viewed'], $r['courses_completed'], $r['courses_total'],
            $r['avg_score_pct'],
            $r['test_attempts'] ?? 0, $r['test_passed'] ?? 0,
            $r['last_activity'] ?? '',
        ], $data);

        return $xlsx->stream(
            'education-analytics-' . now()->format('Y-m-d'),
            'Статистика обучения',
            $headers,
            $rows,
            [
                'numericColumns' => [3, 4, 5, 7, 8],
                'percentColumns' => [6],
                'dateColumns' => [9],
            ]
        );
    }

    /** Список курсов */
    public function courses(Request $request): JsonResponse
    {
        $query = DB::table('education_courses');

        if ($request->filled('search')) {
            $query->where('title', 'ilike', '%' . $request->search . '%');
        }

        $total = $query->count();
        $rows = $query->orderBy('sort_order')
            ->offset(($request->input('page', 1) - 1) * 25)
            ->limit(25)
            ->get();

        if ($rows->isEmpty()) {
            return response()->json(['data' => [], 'total' => $total]);
        }

        // Batch-load counts and product names (was N+1: three lookups per row).
        $courseIds = $rows->pluck('id');

        $lessonCounts = DB::table('education_lessons')
            ->whereIn('course_id', $courseIds)
            ->select('course_id', DB::raw('count(*) as cnt'))
            ->groupBy('course_id')
            ->pluck('cnt', 'course_id');

        $testCounts = DB::table('education_tests')
            ->whereIn('course_id', $courseIds)
            ->select('course_id', DB::raw('count(*) as cnt'))
            ->groupBy('course_id')
            ->pluck('cnt', 'course_id');

        $productIds = $rows->pluck('product_id')->filter()->unique();
        $productNames = $productIds->isNotEmpty()
            ? DB::table('product')->whereIn('id', $productIds)->pluck('name', 'id')
            : collect();

        // category_id появилась в миграции 2026_05_21_000020 — проверяем
        // через hasColumn, чтобы старая БД без миграции не отдавала 500.
        $hasCategory = Schema::hasColumn('education_courses', 'category_id');
        $categoryNames = $hasCategory && Schema::hasTable('education_course_categories')
            ? DB::table('education_course_categories')->pluck('name', 'id')
            : collect();

        $courses = $rows->map(fn ($c) => [
            'id' => $c->id,
            'title' => $c->title,
            'description' => $c->description,
            'product_id' => $c->product_id,
            'productName' => $c->product_id ? ($productNames[$c->product_id] ?? null) : null,
            'category_id' => $hasCategory ? ($c->category_id ?? null) : null,
            'categoryName' => $hasCategory && $c->category_id ? ($categoryNames[$c->category_id] ?? null) : null,
            'active' => (bool) $c->active,
            'sort_order' => $c->sort_order,
            'lessonCount' => (int) ($lessonCounts[$c->id] ?? 0),
            'testCount' => (int) ($testCounts[$c->id] ?? 0),
        ]);

        return response()->json(['data' => $courses, 'total' => $total]);
    }

    /** Создать курс / модуль / подмодуль (per ТЗ Жосан — рекурсивная иерархия) */
    public function storeCourse(Request $request): JsonResponse
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'category_id' => 'nullable|integer|exists:education_course_categories,id',
            'parent_id' => 'nullable|integer|exists:education_courses,id',
            'is_container' => 'nullable|boolean',
        ]);

        $attrs = $this->coursePayload($request) + [
            'active' => $request->boolean('active', true),
            'created_at' => now(),
        ];

        $id = DB::table('education_courses')->insertGetId($attrs);

        return response()->json(['message' => 'Курс создан', 'id' => $id], 201);
    }

    /** Обновить курс */
    public function updateCourse(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'category_id' => 'nullable|integer|exists:education_course_categories,id',
            'parent_id' => 'nullable|integer|exists:education_courses,id',
            'is_container' => 'nullable|boolean',
        ]);

        // Защита от циклов: нельзя выставить parent_id равный своему id
        // или потомку (иначе получим бесконечную рекурсию в tree).
        if ($request->filled('parent_id')) {
            $newParent = (int) $request->parent_id;
            if ($newParent === $id || $this->isDescendantOf($newParent, $id)) {
                return response()->json([
                    'message' => 'Нельзя сделать узел потомком самого себя',
                ], 422);
            }
        }

        $attrs = $this->coursePayload($request) + [
            'active' => $request->boolean('active'),
            'updated_at' => now(),
        ];

        DB::table('education_courses')->where('id', $id)->update($attrs);

        return response()->json(['message' => 'Курс обновлён']);
    }

    /**
     * POST /admin/education/courses/{id}/move
     * Переместить узел в дерева: установить новый parent_id и sort_order
     * среди siblings. Используется конструктором при drag-and-drop.
     */
    public function moveCourse(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'parent_id' => 'nullable|integer|exists:education_courses,id',
            'sort_order' => 'required|integer|min:0',
        ]);

        if (! empty($data['parent_id'])) {
            $newParent = (int) $data['parent_id'];
            if ($newParent === $id || $this->isDescendantOf($newParent, $id)) {
                return response()->json([
                    'message' => 'Нельзя переместить узел в свою же ветку',
                ], 422);
            }
        }

        DB::table('education_courses')->where('id', $id)->update([
            'parent_id' => $data['parent_id'] ?? null,
            'sort_order' => $data['sort_order'],
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Перемещено']);
    }

    /** Общий маппинг полей курса. */
    private function coursePayload(Request $request): array
    {
        $payload = [
            'title' => $request->title,
            'description' => $request->description,
            'product_id' => $request->product_id,
            'sort_order' => $request->input('sort_order', 0),
        ];
        foreach (['category_id', 'parent_id', 'is_container', 'cover_url', 'slug'] as $field) {
            if (! Schema::hasColumn('education_courses', $field)) continue;
            if ($field === 'is_container') {
                $payload[$field] = $request->boolean($field);
            } else {
                $payload[$field] = $request->input($field);
            }
        }
        return $payload;
    }

    /** True если $candidateId — потомок $rootId (защита от циклов в move). */
    private function isDescendantOf(int $candidateId, int $rootId): bool
    {
        $visited = [];
        $stack = [$rootId];
        while ($stack) {
            $cur = array_pop($stack);
            $children = DB::table('education_courses')
                ->where('parent_id', $cur)
                ->whereNull('dateDeleted')
                ->pluck('id')
                ->all();
            foreach ($children as $cId) {
                if ($cId === $candidateId) return true;
                if (! in_array($cId, $visited, true)) {
                    $visited[] = $cId;
                    $stack[] = $cId;
                }
            }
        }
        return false;
    }

    /** Удалить курс */
    public function destroyCourse(int $id): JsonResponse
    {
        $exists = DB::table('education_courses')->where('id', $id)->exists();
        if (! $exists) {
            return response()->json(['message' => 'Курс не найден'], 404);
        }

        // FK между education_* отсутствуют — каскад делаем руками.
        // Порядок: сначала views (через lesson_id), потом lessons/tests
        // и результаты прохождений, потом сам курс.
        DB::transaction(function () use ($id) {
            $lessonIds = DB::table('education_lessons')
                ->where('course_id', $id)
                ->pluck('id');
            if ($lessonIds->isNotEmpty()) {
                DB::table('education_lesson_views')
                    ->whereIn('lesson_id', $lessonIds)
                    ->delete();
            }
            DB::table('education_lessons')->where('course_id', $id)->delete();
            DB::table('education_tests')->where('course_id', $id)->delete();
            if (Schema::hasTable('education_test_attempts')) {
                DB::table('education_test_attempts')->where('course_id', $id)->delete();
            }
            DB::table('education_course_completions')->where('course_id', $id)->delete();
            DB::table('education_courses')->where('id', $id)->delete();
        });

        return response()->json(['message' => 'Курс удалён']);
    }

    /** Уроки курса */
    public function lessons(int $courseId): JsonResponse
    {
        $hasArrays = Schema::hasColumn('education_lessons', 'video_urls');

        $lessons = DB::table('education_lessons')
            ->where('course_id', $courseId)
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($l) => [
                'id' => $l->id,
                'title' => $l->title,
                'content' => $l->content,
                // Legacy single-fields оставлены для совместимости — фронт
                // может смотреть только массивы.
                'video_url' => $l->video_url,
                'document_url' => $l->document_url,
                'video_urls' => $this->urlArray($hasArrays ? ($l->video_urls ?? null) : null, $l->video_url ?? null),
                'document_urls' => $this->urlArray($hasArrays ? ($l->document_urls ?? null) : null, $l->document_url ?? null),
                'body' => isset($l->body) && $l->body
                    ? (is_string($l->body) ? json_decode($l->body, true) : $l->body)
                    : null,
                'sort_order' => $l->sort_order,
                'active' => (bool) $l->active,
            ]);

        return response()->json($lessons);
    }

    /**
     * Разворачиваем JSONB-массив элементов урока к единому формату
     * [{url, label}, ...]. Поддерживаем два legacy-формата:
     *   - массив строк ["http://..."] (был с миграции 2026_05_21_000030)
     *   - одиночный video_url/document_url (был до JSONB-миграции)
     *
     * Возвращаемый формат единый для фронта: array of {url, label}.
     */
    private function urlArray($jsonbValue, $legacySingle): array
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
                            'label' => isset($item['label']) ? trim((string) $item['label']) : null,
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

    /** Подготовка payload для урока: JSONB-массивы + legacy first-item зеркала. */
    private function lessonPayload(Request $request): array
    {
        $videoItems = $this->cleanItemList($request->input('video_urls'));
        $documentItems = $this->cleanItemList($request->input('document_urls'));

        // Бэкауорд: если фронт ещё шлёт single video_url/document_url —
        // подмешиваем их в начало массива (без label).
        if ($request->filled('video_url')) {
            $url = trim((string) $request->input('video_url'));
            $exists = array_filter($videoItems, fn ($i) => $i['url'] === $url);
            if (! $exists) array_unshift($videoItems, ['url' => $url, 'label' => null]);
        }
        if ($request->filled('document_url')) {
            $url = trim((string) $request->input('document_url'));
            $exists = array_filter($documentItems, fn ($i) => $i['url'] === $url);
            if (! $exists) array_unshift($documentItems, ['url' => $url, 'label' => null]);
        }

        $payload = [
            'title' => $request->title,
            'content' => $request->input('content'),
            // Single-колонки = url первого элемента (для легаси-потребителей).
            'video_url' => $videoItems[0]['url'] ?? null,
            'document_url' => $documentItems[0]['url'] ?? null,
            'sort_order' => $request->input('sort_order', 0),
        ];

        if (Schema::hasColumn('education_lessons', 'video_urls')) {
            $payload['video_urls'] = $videoItems ? json_encode(array_values($videoItems), JSON_UNESCAPED_UNICODE) : null;
            $payload['document_urls'] = $documentItems ? json_encode(array_values($documentItems), JSON_UNESCAPED_UNICODE) : null;
        }

        // body — конструктор блоков урока (text/video/audio/image/file/link/...),
        // массив объектов { type, value, label, order, opts }.
        // Per ТЗ Жосан §6: «урок не должен быть жёстким шаблоном, нужен
        // конструктор блоков». Старые video_urls/document_urls оставляем
        // для legacy-уроков — рендерер на фронте поддерживает оба формата.
        if (Schema::hasColumn('education_lessons', 'body')) {
            $bodyInput = $request->input('body');
            if ($bodyInput === null || $bodyInput === '') {
                $payload['body'] = null;
            } else {
                $body = is_string($bodyInput) ? json_decode($bodyInput, true) : $bodyInput;
                $payload['body'] = is_array($body) && $body
                    ? json_encode(array_values($body), JSON_UNESCAPED_UNICODE)
                    : null;
            }
        }

        // Drip-feed (миграция 2026_05_25_000020): drip_delay_hours для
        // relative-расписания, drip_open_at для fixed-даты. is_stop_lesson
        // блокирует следующие уроки до прохождения этого.
        foreach (['drip_delay_hours', 'drip_open_at', 'is_stop_lesson',
            'requires_homework', 'homework_instructions'] as $field) {
            if (! Schema::hasColumn('education_lessons', $field)) continue;
            if (in_array($field, ['is_stop_lesson', 'requires_homework'], true)) {
                $payload[$field] = $request->boolean($field);
            } else {
                $val = $request->input($field);
                $payload[$field] = ($val === '' ? null : $val);
            }
        }
        // content_type оставлено в схеме но больше не дёргаем — урок
        // содержит произвольный микс текста/видео/ссылок одновременно.

        return $payload;
    }

    /**
     * Нормализуем входящий массив к [{url, label}]. Принимаем:
     *  - массив строк (старый фронт без лейблов)
     *  - массив объектов {url, label?}
     * Пустые элементы выбрасываем, обрезаем пробелы.
     */
    private function cleanItemList($input): array
    {
        if (! is_array($input)) return [];
        $out = [];
        foreach ($input as $v) {
            if (is_string($v)) {
                $url = trim($v);
                if ($url !== '') $out[] = ['url' => $url, 'label' => null];
            } elseif (is_array($v) && isset($v['url'])) {
                $url = trim((string) $v['url']);
                if ($url === '') continue;
                $label = isset($v['label']) ? trim((string) $v['label']) : null;
                $out[] = ['url' => $url, 'label' => $label !== '' ? $label : null];
            }
        }
        // Уникализация по URL: оставляем первый встретившийся.
        $seen = [];
        $unique = [];
        foreach ($out as $item) {
            if (isset($seen[$item['url']])) continue;
            $seen[$item['url']] = true;
            $unique[] = $item;
        }
        return $unique;
    }

    /** CRUD урока */
    public function storeLesson(Request $request, int $courseId): JsonResponse
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'video_urls' => 'nullable|array',
            'document_urls' => 'nullable|array',
        ]);

        $attrs = array_merge($this->lessonPayload($request), [
            'course_id' => $courseId,
            'active' => $request->boolean('active', true),
            'created_at' => now(),
        ]);

        $id = DB::table('education_lessons')->insertGetId($attrs);

        return response()->json(['message' => 'Урок создан', 'id' => $id], 201);
    }

    public function updateLesson(Request $request, int $courseId, int $lessonId): JsonResponse
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'video_urls' => 'nullable|array',
            'document_urls' => 'nullable|array',
        ]);

        $attrs = array_merge($this->lessonPayload($request), [
            'active' => $request->boolean('active'),
            'updated_at' => now(),
        ]);

        DB::table('education_lessons')->where('id', $lessonId)->update($attrs);

        return response()->json(['message' => 'Урок обновлён']);
    }

    public function destroyLesson(int $courseId, int $lessonId): JsonResponse
    {
        // Scope by course_id so the URL {courseId} actually matters —
        // otherwise a lesson under course A could be deleted via course B's URL.
        $deleted = DB::table('education_lessons')
            ->where('id', $lessonId)
            ->where('course_id', $courseId)
            ->delete();

        if ($deleted === 0) {
            return response()->json(['message' => 'Урок не найден'], 404);
        }

        return response()->json(['message' => 'Урок удалён']);
    }

    /** Тесты курса */
    public function tests(int $courseId): JsonResponse
    {
        $tests = DB::table('education_tests')
            ->where('course_id', $courseId)
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'question' => $t->question,
                'answers' => json_decode($t->answers, true), // ["answer1", "answer2", ...]
                'correct_answer' => $t->correct_answer, // index of correct answer
                'sort_order' => $t->sort_order,
            ]);

        return response()->json($tests);
    }

    /** CRUD тест-вопроса */
    public function storeTest(Request $request, int $courseId): JsonResponse
    {
        $request->validate([
            'question' => 'required|string',
            'answers' => 'required|array|min:2',
            'correct_answer' => 'required|integer',
        ]);

        $id = DB::table('education_tests')->insertGetId([
            'course_id' => $courseId,
            'question' => $request->question,
            'answers' => json_encode($request->answers),
            'correct_answer' => $request->correct_answer,
            'sort_order' => $request->input('sort_order', 0),
            'created_at' => now(),
        ]);

        return response()->json(['message' => 'Вопрос создан', 'id' => $id], 201);
    }

    public function updateTest(Request $request, int $courseId, int $testId): JsonResponse
    {
        DB::table('education_tests')->where('id', $testId)->update([
            'question' => $request->question,
            'answers' => json_encode($request->answers),
            'correct_answer' => $request->correct_answer,
            'sort_order' => $request->input('sort_order', 0),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Вопрос обновлён']);
    }

    public function destroyTest(int $courseId, int $testId): JsonResponse
    {
        $deleted = DB::table('education_tests')
            ->where('id', $testId)
            ->where('course_id', $courseId)
            ->delete();

        if ($deleted === 0) {
            return response()->json(['message' => 'Вопрос не найден'], 404);
        }

        return response()->json(['message' => 'Вопрос удалён']);
    }

    // === КАТЕГОРИИ КУРСОВ ===
    // Произвольное группирование курсов для отдела продуктов (миграция
    // 2026_05_21_000020). На витрине партнёра группы заменяют hard-coded
    // блоки. Курсы без category_id показываются в группе «Без категории».

    /** GET /admin/education/categories — список (для админ-CRUD и селектов). */
    public function categories(Request $request): JsonResponse
    {
        if (! Schema::hasTable('education_course_categories')) {
            return response()->json(['data' => [], 'total' => 0]);
        }

        $q = DB::table('education_course_categories')->whereNull('deleted_at');
        if ($request->boolean('only_active')) {
            $q->where('active', true);
        }
        if ($request->filled('search')) {
            $q->where('name', 'ilike', '%' . $request->search . '%');
        }

        $rows = $q->orderBy('sort_order')->orderBy('name')->get();

        // Курсов в категории — для столбца «Курсов» в админке.
        $courseCounts = Schema::hasColumn('education_courses', 'category_id')
            ? DB::table('education_courses')
                ->whereNotNull('category_id')
                ->select('category_id', DB::raw('count(*) as cnt'))
                ->groupBy('category_id')
                ->pluck('cnt', 'category_id')
            : collect();

        $data = $rows->map(fn ($c) => [
            'id' => $c->id,
            'name' => $c->name,
            'sort_order' => (int) $c->sort_order,
            'active' => (bool) $c->active,
            'courseCount' => (int) ($courseCounts[$c->id] ?? 0),
        ]);

        return response()->json(['data' => $data, 'total' => $rows->count()]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:200',
            'sort_order' => 'nullable|integer',
        ]);

        $id = DB::table('education_course_categories')->insertGetId([
            'name' => $request->name,
            'sort_order' => (int) $request->input('sort_order', 0),
            'active' => $request->boolean('active', true),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Категория создана', 'id' => $id], 201);
    }

    public function updateCategory(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:200',
            'sort_order' => 'nullable|integer',
        ]);

        $affected = DB::table('education_course_categories')
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->update([
                'name' => $request->name,
                'sort_order' => (int) $request->input('sort_order', 0),
                'active' => $request->boolean('active'),
                'updated_at' => now(),
            ]);

        if (! $affected) {
            return response()->json(['message' => 'Категория не найдена'], 404);
        }
        return response()->json(['message' => 'Категория обновлена']);
    }

    public function destroyCategory(int $id): JsonResponse
    {
        // Soft-delete (через deleted_at), привязанные курсы НЕ отвязываем —
        // category_id у них останется, восстановление вернёт связку как было.
        DB::table('education_course_categories')
            ->where('id', $id)
            ->update(['deleted_at' => now(), 'active' => false]);
        return response()->json(['message' => 'Категория удалена']);
    }

    /** Создать таблицы если их нет */
    private function ensureTablesExist(): void
    {
        if (! Schema::hasTable('education_courses')) {
            DB::statement('CREATE TABLE education_courses (
                id BIGSERIAL PRIMARY KEY,
                title TEXT NOT NULL,
                description TEXT,
                product_id BIGINT,
                active BOOLEAN DEFAULT true,
                sort_order INT DEFAULT 0,
                created_at TIMESTAMP,
                updated_at TIMESTAMP
            )');
        }

        if (! Schema::hasTable('education_lessons')) {
            DB::statement('CREATE TABLE education_lessons (
                id BIGSERIAL PRIMARY KEY,
                course_id BIGINT NOT NULL,
                title TEXT NOT NULL,
                content TEXT,
                content_type TEXT DEFAULT \'text\',
                video_url TEXT,
                document_url TEXT,
                sort_order INT DEFAULT 0,
                active BOOLEAN DEFAULT true,
                created_at TIMESTAMP,
                updated_at TIMESTAMP
            )');
        }

        if (! Schema::hasTable('education_tests')) {
            DB::statement('CREATE TABLE education_tests (
                id BIGSERIAL PRIMARY KEY,
                course_id BIGINT NOT NULL,
                question TEXT NOT NULL,
                answers JSONB NOT NULL DEFAULT \'[]\',
                correct_answer INT NOT NULL DEFAULT 0,
                sort_order INT DEFAULT 0,
                created_at TIMESTAMP,
                updated_at TIMESTAMP
            )');
        }
    }
}
