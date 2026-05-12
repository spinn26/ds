<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class DocumentController extends Controller
{
    /**
     * Маппинг типа из API в имя колонки на consultant.
     */
    private const TYPE_TO_FIELD = [
        'passportPage1' => 'passportScanPage1',
        'passportPage2' => 'passportScanPage2',
        'applicationForPayment' => 'applicationForPayment',
    ];

    private const TYPE_LABEL = [
        'passportPage1' => 'Паспорт (разворот с фото)',
        'passportPage2' => 'Паспорт (регистрация)',
        'applicationForPayment' => 'Заявление на выплату',
    ];

    /**
     * Загрузить документ партнёра (паспорт, заявление).
     * Хранится на private-диске (local). Скачивание — через signed URL,
     * раздаваемый list()/upload(), а не прямым `/storage/...` (раньше
     * паспорта были на public-диске → утечка по предсказуемому пути).
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:10240|mimes:jpg,jpeg,png,pdf',
            'type' => 'required|in:' . implode(',', array_keys(self::TYPE_TO_FIELD)),
        ]);

        $user = $request->user();
        $consultant = Consultant::where('webUser', $user->id)->first();

        if (! $consultant) {
            return response()->json(['message' => 'Консультант не найден'], 404);
        }

        $path = $request->file('file')->store("documents/{$consultant->id}", 'local');

        $consultant->{self::TYPE_TO_FIELD[$request->type]} = $path;
        $consultant->saveQuietly();

        return response()->json([
            'message' => 'Документ загружен',
            'type' => $request->type,
            'url' => $this->signedUrlFor($consultant->id, $request->type),
        ]);
    }

    /**
     * Получить документы партнёра — список + signed URLs.
     */
    public function list(Request $request): JsonResponse
    {
        $user = $request->user();
        $consultant = Consultant::where('webUser', $user->id)->first();

        if (! $consultant) {
            return response()->json(['documents' => []]);
        }

        $documents = [];
        foreach (self::TYPE_TO_FIELD as $type => $field) {
            if ($consultant->{$field}) {
                $documents[] = [
                    'type' => $type,
                    'label' => self::TYPE_LABEL[$type],
                    'url' => $this->signedUrlFor($consultant->id, $type),
                    'hasFile' => true,
                ];
            }
        }

        return response()->json(['documents' => $documents]);
    }

    /**
     * GET /documents/{consultantId}/{type} — signed-route раздача файла.
     * Middleware('signed') в роутах валидирует подпись; контроллер
     * только тянет файл с private-диска. Подпись выдаётся list()/upload()
     * только владельцу/staff, поэтому отдельной auth-проверки нет.
     */
    public function download(int $consultantId, string $type): Response
    {
        abort_unless(isset(self::TYPE_TO_FIELD[$type]), 404);
        $consultant = Consultant::find($consultantId);
        abort_unless($consultant, 404);

        $path = $consultant->{self::TYPE_TO_FIELD[$type]};
        abort_unless($path, 404, 'Документ не загружен');

        $disk = Storage::disk('local');
        abort_unless($disk->exists($path), 404, 'Файл не найден на диске');

        $mime = $disk->mimeType($path) ?: 'application/octet-stream';
        $name = self::TYPE_LABEL[$type] . '.' . pathinfo($path, PATHINFO_EXTENSION);
        $disposition = (str_starts_with($mime, 'image/') || $mime === 'application/pdf')
            ? 'inline' : 'attachment';

        return response()->stream(function () use ($disk, $path) {
            $stream = $disk->readStream($path);
            if ($stream) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => $disposition . '; filename="' . addslashes($name) . '"',
        ]);
    }

    private function signedUrlFor(int $consultantId, string $type): string
    {
        return URL::temporarySignedRoute('documents.download', now()->addHour(), [
            'consultantId' => $consultantId,
            'type' => $type,
        ]);
    }
}
