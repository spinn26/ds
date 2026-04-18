<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EducationController extends Controller
{
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

        $data = $courses->map(function ($c) use ($lessonTotals, $lessonViewed, $completions) {
            $total = (int) ($lessonTotals[$c->id] ?? 0);
            $viewed = (int) ($lessonViewed[$c->id] ?? 0);
            $completion = $completions[$c->id] ?? null;
            $testPassed = (bool) $completion;
            $allLessonsViewed = $total > 0 && $viewed >= $total;

            return [
                'id' => $c->id,
                'title' => $c->title,
                'description' => $c->description,
                'product_id' => $c->product_id,
                'lessonCount' => $total,
                'lessonViewed' => $viewed,
                'testPassed' => $testPassed,
                'testScore' => $completion?->score,
                'testTotal' => $completion?->total,
                'completed' => $testPassed && $allLessonsViewed,
            ];
        })->values();

        return response()->json(['data' => $data]);
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
            'lessons' => $lessons->map(fn ($l) => [
                'id' => $l->id,
                'title' => $l->title,
                'content' => $l->content,
                'content_type' => $l->content_type,
                'video_url' => $l->video_url,
                'document_url' => $l->document_url,
                'viewed' => isset($viewedSet[$l->id]),
            ])->values(),
            'tests' => $tests,
            'completion' => $completion ? [
                'score' => $completion->score,
                'total' => $completion->total,
                'completed_at' => $completion->completed_at,
            ] : null,
        ]);
    }

    /** Mark a lesson as viewed (idempotent upsert). */
    public function markLessonViewed(Request $request, int $id): JsonResponse
    {
        $userId = $request->user()->id;

        $lesson = DB::table('education_lessons')->where('id', $id)->where('active', true)->first();
        if (! $lesson) {
            return response()->json(['message' => 'Урок не найден'], 404);
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
