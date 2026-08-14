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

    /**
     * Мемо для schema-guard'а. Кэшируем ТОЛЬКО положительный ответ: таблица,
     * однажды появившись, в пределах процесса не исчезает, а вот отрицательный
     * ответ бывает временным — приложение бутстрапится и до миграций (например,
     * на composer package:discover), и запомненный false запер бы настройки
     * навсегда.
     */
    private static ?bool $tableExists = null;

    /** Полная карта key => raw row (кэш навсегда, сбрасывается на put). */
    public static function map(): array
    {
        // Schema-guard: до миграции таблицы нет — возвращаем пусто, фолбэки спасают.
        //
        // ⚠ Проверка мемоизирована не ради красоты: Schema::hasTable() — это
        // запрос к pg_class, а value() зовут из сборки КАЖДОЙ строки списков
        // (пороги активации в /admin/partners и /admin/partner-statuses).
        // Замер: страница на 15 строках стоила 23 запроса против 11 на трёх —
        // весь прирост давал этот guard.
        if (self::$tableExists !== true) {
            self::$tableExists = Schema::hasTable('system_settings');
        }
        if (! self::$tableExists) {
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

    /**
     * Записать значение (raw приводится к строке) и сбросить кэш.
     * Upsert: если ключа ещё нет — создаём (type='string'), иначе обновляем
     * value, сохраняя существующий type. Раньше был update() → put() по
     * несуществующему ключу молча ничего не писал (баг: watermark выгрузки).
     */
    public static function put(string $key, $value): void
    {
        $raw = is_bool($value) ? ($value ? '1' : '0')
            : (is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value);

        $row = static::firstOrNew(['key' => $key]);
        $row->value = $raw;
        if (! $row->exists) {
            $row->type = 'string';
        }
        $row->save();
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
