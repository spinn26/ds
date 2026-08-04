<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Домашние задания LMS.
 *
 * Партнёрские endpoints:
 *   POST /education/lessons/{lessonId}/homework   — отправить ответ
 *   GET  /education/homework/my                   — мои ответы
 *
 * Кураторские endpoints (роль curator или education):
 *   GET   /admin/kb/homework                      — очередь на проверку
 *   POST  /admin/kb/homework/{id}/review          — approve/reject + comment
 */
class HomeworkController extends Controller
{
    public function __construct()
    {
        if (! Schema::hasTable('education_homework_submissions')) {
            abort(503, 'Таблица homework не создана — нужна миграция 2026_05_25_000020');
        }
    }

    /** POST /education/lessons/{lessonId}/homework — partner submits */
    public function submit(Request $request, int $lessonId): JsonResponse
    {
        $data = $request->validate([
            'answer_text' => 'nullable|string|max:50000',
            'attachments' => 'nullable|array',
            'attachments.*.name' => 'nullable|string|max:255',
            'attachments.*.url' => 'required_with:attachments|string|max:1000',
        ]);

        $lesson = DB::table('education_lessons')->where('id', $lessonId)->first();
        if (! $lesson) return response()->json(['message' => 'Урок не найден'], 404);

        $userId = (int) $request->user()->id;

        // Если уже есть pending-ответ — обновляем (не плодим дубли).
        $existing = DB::table('education_homework_submissions')
            ->where('lesson_id', $lessonId)
            ->where('user_id', $userId)
            ->where('status', 'pending')
            ->first();

        $payload = [
            'lesson_id' => $lessonId,
            'user_id' => $userId,
            'answer_text' => $data['answer_text'] ?? null,
            'attachments' => isset($data['attachments'])
                ? json_encode($data['attachments'], JSON_UNESCAPED_UNICODE) : null,
            'status' => 'pending',
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('education_homework_submissions')
                ->where('id', $existing->id)->update($payload);
            $id = $existing->id;
        } else {
            $payload['created_at'] = now();
            $id = DB::table('education_homework_submissions')->insertGetId($payload);
        }

        return response()->json([
            'message' => 'Ответ отправлен на проверку',
            'id' => $id,
        ], 201);
    }

    /** GET /education/homework/my — мои ответы */
    public function my(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $rows = DB::table('education_homework_submissions as h')
            ->leftJoin('education_lessons as l', 'l.id', '=', 'h.lesson_id')
            ->where('h.user_id', $userId)
            ->orderByDesc('h.id')
            ->get([
                'h.id', 'h.lesson_id', 'l.title as lesson_title',
                'l.course_id', 'h.status', 'h.answer_text',
                'h.attachments', 'h.reviewer_comment',
                'h.created_at', 'h.reviewed_at',
            ]);
        return response()->json(['items' => $rows->map(fn ($r) => [
            'id' => $r->id, 'lessonId' => $r->lesson_id,
            'lessonTitle' => $r->lesson_title, 'courseId' => $r->course_id,
            'status' => $r->status, 'answerText' => $r->answer_text,
            'attachments' => $r->attachments
                ? (is_string($r->attachments) ? json_decode($r->attachments, true) : $r->attachments)
                : [],
            'reviewerComment' => $r->reviewer_comment,
            'createdAt' => $r->created_at, 'reviewedAt' => $r->reviewed_at,
        ])]);
    }

    /** GET /admin/kb/homework — очередь на проверку для куратора */
    public function queue(Request $request): JsonResponse
    {
        $status = $request->query('status', 'pending');
        $rows = DB::table('education_homework_submissions as h')
            ->leftJoin('education_lessons as l', 'l.id', '=', 'h.lesson_id')
            ->leftJoin('education_courses as c', 'c.id', '=', 'l.course_id')
            ->leftJoin('WebUser as u', 'u.id', '=', 'h.user_id')
            ->when($status !== 'all', fn ($q) => $q->where('h.status', $status))
            ->orderByDesc('h.created_at')
            ->limit(500)
            ->get([
                'h.id', 'h.user_id', 'h.lesson_id', 'h.status',
                'h.answer_text', 'h.attachments', 'h.reviewer_comment',
                'h.created_at', 'h.reviewed_at',
                'l.title as lesson_title', 'c.title as course_title',
                'c.id as course_id',
                DB::raw('TRIM(CONCAT(u."firstName", \' \', u."lastName")) as user_name'),
            ]);
        return response()->json(['items' => $rows->map(fn ($r) => [
            'id' => $r->id, 'lessonId' => $r->lesson_id,
            'lessonTitle' => $r->lesson_title, 'courseId' => $r->course_id,
            'courseTitle' => $r->course_title,
            'userId' => $r->user_id, 'userName' => trim((string) $r->user_name),
            'status' => $r->status, 'answerText' => $r->answer_text,
            'attachments' => $r->attachments
                ? (is_string($r->attachments) ? json_decode($r->attachments, true) : $r->attachments)
                : [],
            'reviewerComment' => $r->reviewer_comment,
            'createdAt' => $r->created_at, 'reviewedAt' => $r->reviewed_at,
        ])]);
    }

    /** POST /admin/kb/homework/{id}/review — куратор approve/reject */
    public function review(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|in:approved,rejected',
            'comment' => 'nullable|string|max:5000',
        ]);

        $hw = DB::table('education_homework_submissions')->where('id', $id)->first();
        if (! $hw) return response()->json(['message' => 'Не найдено'], 404);

        DB::table('education_homework_submissions')->where('id', $id)->update([
            'status' => $data['status'],
            'reviewer_comment' => $data['comment'] ?? null,
            'reviewer_id' => (int) $request->user()->id,
            'reviewed_at' => now(),
            'updated_at' => now(),
        ]);

        // Если approved + есть lesson — отметим как просмотренный
        // (чтобы прогресс курса автоматически продвинулся).
        if ($data['status'] === 'approved') {
            $exists = DB::table('education_lesson_views')
                ->where('lesson_id', $hw->lesson_id)
                ->where('user_id', $hw->user_id)
                ->exists();
            if (! $exists) {
                DB::table('education_lesson_views')->insert([
                    'lesson_id' => $hw->lesson_id,
                    'user_id' => $hw->user_id,
                    'viewed_at' => now(),
                ]);
            }
        }

        return response()->json(['message' => 'Сохранено']);
    }
}
