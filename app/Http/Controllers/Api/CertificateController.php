<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Сертификаты прохождения курсов.
 *
 * Endpoint выдаёт HTML-страницу с print-стилями — партнёр в браузере
 * жмёт Ctrl+P → «Сохранить как PDF». Это намеренно простой MVP без
 * зависимостей от dompdf/TCPDF/wkhtmltopdf — composer install на проде
 * у нас платный по времени, а конечный результат для пользователя
 * идентичен (PDF в один клик).
 *
 * GET /education/courses/{courseId}/certificate
 */
class CertificateController extends Controller
{
    public function show(Request $request, int $courseId)
    {
        $userId = (int) $request->user()->id;

        $course = DB::table('education_courses')->where('id', $courseId)->first();
        if (! $course) abort(404, 'Курс не найден');

        // Проверка: тест курса сдан и/или все уроки просмотрены.
        $completion = DB::table('education_course_completions')
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->first();
        $totalLessons = DB::table('education_lessons')
            ->where('course_id', $courseId)
            ->whereNull('dateDeleted')
            ->count();
        $viewedLessons = DB::table('education_lesson_views as v')
            ->join('education_lessons as l', 'l.id', '=', 'v.lesson_id')
            ->where('l.course_id', $courseId)
            ->where('v.user_id', $userId)
            ->count();
        $allViewed = $totalLessons > 0 && $viewedLessons >= $totalLessons;
        if (! $completion && ! $allViewed) {
            abort(403, 'Курс не завершён — сертификат недоступен');
        }

        // Получить/выдать номер сертификата (idempotent).
        $certNo = null;
        if (Schema::hasTable('education_course_certificates')) {
            $existing = DB::table('education_course_certificates')
                ->where('user_id', $userId)->where('course_id', $courseId)->first();
            if ($existing) {
                $certNo = $existing->certificate_no;
            } else {
                $certNo = sprintf('DS-%d-%d-%s', $courseId, $userId, date('ymd'));
                DB::table('education_course_certificates')->insertOrIgnore([
                    'user_id' => $userId,
                    'course_id' => $courseId,
                    'certificate_no' => $certNo,
                    'issued_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
        $certNo = $certNo ?? sprintf('DS-%d-%d-%s', $courseId, $userId, date('ymd'));

        $user = $request->user();
        $fio = trim(($user->lastName ?? '') . ' ' . ($user->firstName ?? '') . ' ' . ($user->patronymic ?? ''));
        if ($fio === '') $fio = $user->email ?? 'Партнёр';

        $score = null;
        $totalQ = null;
        if ($completion) {
            $score = $completion->score ?? null;
            $totalQ = $completion->total ?? null;
        }

        return response()->view('certificates.education', [
            'fio' => $fio,
            'courseTitle' => $course->title,
            'certNo' => $certNo,
            'issuedAt' => now()->format('d.m.Y'),
            'score' => $score,
            'totalQ' => $totalQ,
        ])->header('Content-Type', 'text/html; charset=utf-8');
    }
}
