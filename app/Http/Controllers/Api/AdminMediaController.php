<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Медиа-библиотека: файлы в public-диске (папка media/). Список/загрузка/
 * удаление. Только admin. SVG исключён (stored-XSS).
 */
class AdminMediaController extends Controller
{
    private const DIR = 'media';

    public function index(): JsonResponse
    {
        $disk = Storage::disk('public');
        if (! $disk->exists(self::DIR)) {
            return response()->json(['files' => []]);
        }

        $files = collect($disk->files(self::DIR))
            ->map(fn ($path) => [
                'path' => $path,
                'name' => basename($path),
                'url' => Storage::url($path),
                'size' => $disk->size($path),
                'modified' => $disk->lastModified($path),
            ])
            ->sortByDesc('modified')
            ->values();

        return response()->json(['files' => $files]);
    }

    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:png,jpg,jpeg,webp,gif,ico,pdf,doc,docx,xls,xlsx,mp4,webm'],
        ]);
        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension() ?: 'bin');
        $name = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: Str::random(8);
        $name = substr($name, 0, 40) . '-' . Str::random(6) . '.' . $ext;
        $path = $file->storeAs(self::DIR, $name, 'public');

        return response()->json(['url' => Storage::url($path), 'path' => $path]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $path = (string) $request->input('path', '');
        // Защита от traversal: удаляем только внутри media/.
        if (! Str::startsWith($path, self::DIR . '/') || Str::contains($path, '..')) {
            return response()->json(['message' => 'Недопустимый путь'], 422);
        }
        Storage::disk('public')->delete($path);

        return response()->json(['message' => 'Файл удалён']);
    }
}
