<?php

namespace App\Http\Middleware;

use App\Models\SystemSetting;
use Closure;
use Illuminate\Http\Request;

/**
 * Режим обслуживания: если включён (system_settings: maintenance.enabled),
 * не-админы получают 503 с сообщением. Админы проходят всегда — чтобы можно
 * было выключить режим из админки.
 */
class EnsureNotInMaintenance
{
    public function handle(Request $request, Closure $next)
    {
        if (! SystemSetting::value('maintenance.enabled', false)) {
            return $next($request);
        }

        $user = $request->user();
        $isAdmin = $user && method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['admin']);
        if ($isAdmin) {
            return $next($request);
        }

        $endsAt = SystemSetting::value('maintenance.ends_at');

        return response()->json([
            'maintenance' => true,
            'message' => SystemSetting::value('maintenance.message', 'Идут технические работы.'),
            'ends_at' => $endsAt !== '' ? $endsAt : null,
        ], 503);
    }
}
