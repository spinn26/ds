<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DesignTheme;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Раздел «Дизайн» (CMS-подобный): шаблоны логотипа/палитр/CSS.
 * Только admin. Импорт = создание из присланного config, экспорт = отдаём
 * config (фронт сам скачивает файлом).
 */
class AdminDesignController extends Controller
{
    public function index(): JsonResponse
    {
        $themes = DesignTheme::query()->orderByDesc('is_active')->orderBy('name')
            ->get(['id', 'name', 'is_active', 'config', 'updated_at']);

        return response()->json(['themes' => $themes]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request);
        $theme = DesignTheme::create([
            'name' => $data['name'],
            'is_active' => false,
            'config' => $data['config'],
        ]);

        return response()->json(['theme' => $theme], 201);
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $theme = DesignTheme::findOrFail($id);
        $data = $this->validateData($request);
        $theme->update(['name' => $data['name'], 'config' => $data['config']]);

        return response()->json(['theme' => $theme->fresh()]);
    }

    public function destroy(int $id): JsonResponse
    {
        $theme = DesignTheme::findOrFail($id);
        if ($theme->is_active) {
            return response()->json(['message' => 'Нельзя удалить активный шаблон'], 422);
        }
        $theme->delete();

        return response()->json(['message' => 'Шаблон удалён']);
    }

    /**
     * Загрузка ассета дизайна (логотип / фавикон). SVG исключён намеренно —
     * inline-<script> в .svg = stored-XSS (как в EducationUploadController).
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:4096', 'mimes:png,jpg,jpeg,webp,gif,ico'],
        ]);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension() ?: 'png');
        $name = Str::random(24) . '.' . $ext;
        $path = $file->storeAs('design/' . now()->format('Y-m'), $name, 'public');

        return response()->json(['url' => \Illuminate\Support\Facades\Storage::url($path)]);
    }

    /** Сделать шаблон активным (ровно один активный — partial unique index). */
    public function activate(int $id): JsonResponse
    {
        $theme = DesignTheme::findOrFail($id);
        DB::transaction(function () use ($theme) {
            DesignTheme::query()->where('is_active', true)->update(['is_active' => false]);
            $theme->update(['is_active' => true]);
        });

        return response()->json(['message' => 'Шаблон активирован', 'theme' => $theme->fresh()]);
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'config' => ['required', 'array'],
            'config.brandName' => ['nullable', 'string', 'max:120'],
            'config.logoText' => ['nullable', 'string', 'max:40'],
            'config.logoUrl' => ['nullable', 'string', 'max:1000'],
            'config.faviconUrl' => ['nullable', 'string', 'max:1000'],
            'config.loginTitle' => ['nullable', 'string', 'max:160'],
            'config.colors' => ['nullable', 'array'],
            'config.typography' => ['nullable', 'array'],
            'config.typography.fontFamily' => ['nullable', 'string', 'max:300'],
            'config.typography.baseSize' => ['nullable', 'integer', 'min:10', 'max:24'],
            'config.radius' => ['nullable', 'array'],
            'config.radius.sm' => ['nullable', 'integer', 'min:0', 'max:60'],
            'config.radius.md' => ['nullable', 'integer', 'min:0', 'max:60'],
            'config.radius.lg' => ['nullable', 'integer', 'min:0', 'max:60'],
            'config.radius.xl' => ['nullable', 'integer', 'min:0', 'max:60'],
            'config.shadows' => ['nullable', 'array'],
            'config.shadows.card' => ['nullable', 'string', 'in:,none,soft,medium,strong'],
            'config.tokens' => ['nullable', 'array'],
            'config.tokens.*' => ['nullable', 'string', 'max:300'],
            'config.customCss' => ['nullable', 'string', 'max:50000'],
        ]);
    }
}
