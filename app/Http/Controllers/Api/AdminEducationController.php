<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
            'content' => $request->content,
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
            'content' => $request->content,
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
