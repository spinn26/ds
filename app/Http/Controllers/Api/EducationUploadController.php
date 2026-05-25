<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Загрузка файлов раздела «Обучение».
 *
 * Per ТЗ Жосан §8 «материалы внутри урока» + §6 «конструктор урока»:
 *   администратор курса прикрепляет PDF / DOCX / XLSX / картинки.
 *   Партнёр прикрепляет файлы к ответу домашнего задания.
 *
 * Файлы кладём в storage/app/public/education/{kind}/yyyy-mm/{rand}.ext
 * и возвращаем публичный URL (через `php artisan storage:link` это
 * мапится в public/storage/...). MVP: без подписей и истечения —
 * любой кто знает url, может скачать. Для конфиденциальных материалов
 * закроем через signed-urls в следующей итерации.
 */
class EducationUploadController extends Controller
{
    /**
     * POST /education/upload
     * multipart/form-data:
     *   file       — обязательное, до 50 MB
     *   kind       — необязательно, default 'misc'. Допустимо:
     *                'lesson' | 'kb' | 'homework' | 'cover' | 'misc'
     */
    public function upload(Request $request): JsonResponse
    {
        $kind = $request->input('kind', 'misc');
        if (! in_array($kind, ['lesson', 'kb', 'homework', 'cover', 'misc'], true)) {
            $kind = 'misc';
        }

        // Разрешаем учебные форматы + изображения + аудио + видео.
        $request->validate([
            'file' => [
                'required', 'file',
                'max:51200',   // 50 MB
                'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip,'
                    . 'png,jpg,jpeg,gif,webp,svg,'
                    . 'mp3,wav,m4a,ogg,'
                    . 'mp4,webm,mov',
            ],
        ]);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension() ?: 'bin');
        $folder = "education/{$kind}/" . now()->format('Y-m');
        $name = Str::random(24) . '.' . $ext;

        $path = $file->storeAs($folder, $name, 'public');
        $url = Storage::disk('public')->url($path);

        return response()->json([
            'url' => $url,
            'path' => $path,
            'name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'mime' => $file->getMimeType(),
            'kind' => $kind,
        ], 201);
    }
}
