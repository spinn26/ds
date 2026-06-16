<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Редактируемые из админки настройки платформы.
 *
 * Чтение — через статический value($key, $default): весь набор кэшируется
 * одной записью (system_settings:map), значения приводятся к типу из колонки
 * `type`. Запись — put($key, $value) с инвалидацией кэша. Сервисы всегда
 * передают фолбэк (прежнюю константу), поэтому отсутствие строки/таблицы не
 * ломает поведение.
 */
class SystemSetting extends Model
{
    protected $table = 'system_settings';
    protected $guarded = [];

    private const CACHE_KEY = 'system_settings:map';

    /** Полная карта key => raw row (кэш навсегда, сбрасывается на put). */
    public static function map(): array
    {
        // Schema-guard: до миграции таблицы нет — возвращаем пусто, фолбэки спасают.
        if (! Schema::hasTable('system_settings')) {
            return [];
        }

        return Cache::rememberForever(self::CACHE_KEY, function () {
            return static::query()->get(['key', 'value', 'type'])
                ->keyBy('key')
                ->map(fn ($r) => ['value' => $r->value, 'type' => $r->type])
                ->all();
        });
    }

    /** Значение с приведением к типу; $default если нет строки. */
    public static function value(string $key, $default = null)
    {
        $row = self::map()[$key] ?? null;
        if ($row === null) {
            return $default;
        }
        return self::cast($row['value'], $row['type']);
    }

    /** Записать значение (raw приводится к строке) и сбросить кэш. */
    public static function put(string $key, $value): void
    {
        $raw = is_bool($value) ? ($value ? '1' : '0')
            : (is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value);

        static::query()->where('key', $key)->update(['value' => $raw, 'updated_at' => now()]);
        Cache::forget(self::CACHE_KEY);
    }

    private static function cast($value, string $type)
    {
        return match ($type) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode((string) $value, true),
            default => $value,
        };
    }
}
