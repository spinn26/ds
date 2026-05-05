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
            ->whereNull('deletedAt');
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

        $courses = $rows->map(fn ($c) => [
            'id' => $c->id,
            'title' => $c->title,
            'description' => $c->description,
            'product_id' => $c->product_id,
            'productName' => $c->product_id ? ($productNames[$c->product_id] ?? null) : null,
            'active' => (bool) $c->active,
            'sort_order' => $c->sort_order,
            'lessonCount' => (int) ($lessonCounts[$c->id] ?? 0),
            'testCount' => (int) ($testCounts[$c->id] ?? 0),
        ]);

        return response()->json(['data' => $courses, 'total' => $total]);
    }

    /** Создать курс */
    public function storeCourse(Request $request): JsonResponse
    {
        $request->validate([
            'title' => 'required|string|max:255',
        ]);

        $id = DB::table('education_courses')->insertGetId([
            'title' => $request->title,
            'description' => $request->description,
            'product_id' => $request->product_id,
            'active' => $request->boolean('active', true),
            'sort_order' => $request->input('sort_order', 0),
            'created_at' => now(),
        ]);

        return response()->json(['message' => 'Курс создан', 'id' => $id], 201);
    }

    /** Обновить курс */
    public function updateCourse(Request $request, int $id): JsonResponse
    {
        $request->validate(['title' => 'required|string|max:255']);

        DB::table('education_courses')->where('id', $id)->update([
            'title' => $request->title,
            'description' => $request->description,
            'product_id' => $request->product_id,
            'active' => $request->boolean('active'),
            'sort_order' => $request->input('sort_order', 0),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Курс обновлён']);
    }

    /** Удалить курс */
    public function destroyCourse(int $id): JsonResponse
    {
        DB::table('education_courses')->where('id', $id)->update(['active' => false]);
        return response()->json(['message' => 'Курс деактивирован']);
    }

    /** Уроки курса */
    public function lessons(int $courseId): JsonResponse
    {
        $lessons = DB::table('education_lessons')
            ->where('course_id', $courseId)
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($l) => [
                'id' => $l->id,
                'title' => $l->title,
                'content' => $l->content,
                'content_type' => $l->content_type, // text, video, audio
                'video_url' => $l->video_url,
                'document_url' => $l->document_url,
                'sort_order' => $l->sort_order,
                'active' => (bool) $l->active,
            ]);

        return response()->json($lessons);
    }

    /** CRUD урока */
    public function storeLesson(Request $request, int $courseId): JsonResponse
    {
        $request->validate(['title' => 'required|string|max:255']);

        $id = DB::table('education_lessons')->insertGetId([
            'course_id' => $courseId,
            'title' => $request->title,
            'content' => $request->input('content'),
            'content_type' => $request->input('content_type', 'text'),
            'video_url' => $request->video_url,
            'document_url' => $request->document_url,
            'sort_order' => $request->input('sort_order', 0),
            'active' => $request->boolean('active', true),
            'created_at' => now(),
        ]);

        return response()->json(['message' => 'Урок создан', 'id' => $id], 201);
    }

    public function updateLesson(Request $request, int $courseId, int $lessonId): JsonResponse
    {
        DB::table('education_lessons')->where('id', $lessonId)->update([
            'title' => $request->title,
            'content' => $request->input('content'),
            'content_type' => $request->input('content_type', 'text'),
            'video_url' => $request->video_url,
            'document_url' => $request->document_url,
            'sort_order' => $request->input('sort_order', 0),
            'active' => $request->boolean('active'),
            'updated_at' => now(),
        ]);

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
