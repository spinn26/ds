<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    /**
     * Загрузить документ партнёра (паспорт, заявление).
     * Типы: passportPage1, passportPage2, applicationForPayment
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:10240|mimes:jpg,jpeg,png,pdf',
            'type' => 'required|in:passportPage1,passportPage2,applicationForPayment',
        ]);

        $user = $request->user();
        $consultant = Consultant::where('webUser', $user->id)->first();

        if (! $consultant) {
            return response()->json(['message' => 'Консультант не найден'], 404);
        }

        $file = $request->file('file');
        $type = $request->type;

        // Сохраняем файл
        $path = $file->store("documents/{$consultant->id}", 'public');

        // Обновляем поле на консультанте
        $fieldMap = [
            'passportPage1' => 'passportScanPage1',
            'passportPage2' => 'passportScanPage2',
            'applicationForPayment' => 'applicationForPayment',
        ];

        // Сохраняем путь к файлу (или ID если есть FileUpload таблица)
        $consultant->{$fieldMap[$type]} = $path;
        $consultant->saveQuietly();

        return response()->json([
            'message' => 'Документ загружен',
            'path' => $path,
            'url' => Storage::disk('public')->url($path),
        ]);
    }

    /**
     * Получить документы партнёра.
     */
    public function list(Request $request): JsonResponse
    {
        $user = $request->user();
        $consultant = Consultant::where('webUser', $user->id)->first();

        if (! $consultant) {
            return response()->json(['documents' => []]);
        }

        $documents = [];

        if ($consultant->passportScanPage1) {
            $documents[] = [
                'type' => 'passportPage1',
                'label' => 'Паспорт (разворот с фото)',
                'path' => $consultant->passportScanPage1,
                'hasFile' => true,
            ];
        }

        if ($consultant->passportScanPage2) {
            $documents[] = [
                'type' => 'passportPage2',
                'label' => 'Паспорт (регистрация)',
                'path' => $consultant->passportScanPage2,
                'hasFile' => true,
            ];
        }

        if ($consultant->applicationForPayment) {
            $documents[] = [
                'type' => 'applicationForPayment',
                'label' => 'Заявление на выплату',
                'path' => $consultant->applicationForPayment,
                'hasFile' => true,
            ];
        }

        return response()->json(['documents' => $documents]);
    }
}
