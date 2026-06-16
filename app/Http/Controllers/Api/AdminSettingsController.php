<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API раздела «Настройки» (system_settings).
 *
 * GET  /admin/settings        — все настройки, сгруппированные по категориям.
 * PUT  /admin/settings        — массовое сохранение { key: value, ... }.
 *
 * Гейт — только роль admin (см. routes/api.php), т.к. это системные параметры.
 */
class AdminSettingsController extends Controller
{
    /** Человекочитаемые названия категорий + порядок. */
    private const CATEGORIES = [
        'business'      => 'Бизнес-правила / Расчёты',
        'partners'      => 'Партнёры и статусы',
        'finance'       => 'Финансы',
        'education'     => 'Обучение',
        'security'      => 'Безопасность',
        'notifications' => 'Почта и уведомления',
        'maintenance'   => 'Планировщик и обслуживание',
        'performance'   => 'Производительность',
        'appearance'    => 'Внешний вид / Бренд',
        'general'       => 'Прочее',
    ];

    public function index(): JsonResponse
    {
        $rows = SystemSetting::query()
            ->orderBy('category')
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get(['key', 'value', 'type', 'category', 'label', 'description', 'is_secret']);

        $groups = [];
        foreach (self::CATEGORIES as $cat => $title) {
            $items = $rows->where('category', $cat)->values()->map(fn ($r) => [
                'key'         => $r->key,
                'value'       => $r->is_secret ? null : self::castOut($r->value, $r->type),
                'type'        => $r->type,
                'label'       => $r->label,
                'description' => $r->description,
                'isSecret'    => (bool) $r->is_secret,
            ]);
            if ($items->isNotEmpty()) {
                $groups[] = ['category' => $cat, 'title' => $title, 'items' => $items];
            }
        }

        return response()->json(['groups' => $groups]);
    }

    public function update(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'settings'   => ['required', 'array'],
            'settings.*' => ['nullable'],
        ])['settings'];

        // Меняем только существующие ключи (нельзя завести произвольный).
        $known = SystemSetting::query()->pluck('type', 'key');
        $updated = 0;
        foreach ($payload as $key => $value) {
            if (! $known->has($key)) {
                continue;
            }
            SystemSetting::put($key, $this->normalize($value, $known[$key]));
            $updated++;
        }

        return response()->json(['message' => 'Настройки сохранены', 'updated' => $updated]);
    }

    /** Нормализация входящего значения под тип перед записью. */
    private function normalize($value, string $type)
    {
        return match ($type) {
            'int'   => (int) $value,
            'float' => (float) $value,
            'bool'  => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'json'  => is_array($value) ? $value : (json_decode((string) $value, true) ?? []),
            default => (string) $value,
        };
    }

    private static function castOut($value, string $type)
    {
        return match ($type) {
            'int'   => (int) $value,
            'float' => (float) $value,
            'bool'  => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'json'  => json_decode((string) $value, true),
            default => $value,
        };
    }
}
