<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Фиче-флаг. enabled('key', $user) — учитывает вкл/выкл и привязку к ролям.
 */
class FeatureFlag extends Model
{
    protected $table = 'feature_flags';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'roles' => 'array'];
    }

    private const CACHE_KEY = 'feature_flags:map';

    public static function map(): array
    {
        if (! Schema::hasTable('feature_flags')) return [];
        return Cache::rememberForever(self::CACHE_KEY, fn () => static::all()->keyBy('key')
            ->map(fn ($f) => ['enabled' => (bool) $f->enabled, 'roles' => $f->roles])->all());
    }

    public static function enabled(string $key, $user = null): bool
    {
        $f = self::map()[$key] ?? null;
        if (! $f || ! $f['enabled']) return false;
        $roles = $f['roles'] ?? [];
        if (empty($roles)) return true;
        $userRoles = array_filter(array_map('trim', explode(',', (string) ($user->role ?? ''))));
        return count(array_intersect($roles, $userRoles)) > 0;
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
