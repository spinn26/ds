<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Активные объявления для текущего пользователя (баннер в шапке SPA).
 * Фильтр: active + период [starts_at, ends_at] + аудитория по ролям.
 */
class AnnouncementController extends Controller
{
    public function active(Request $request): JsonResponse
    {
        if (! Schema::hasTable('announcements')) {
            return response()->json(['announcements' => []]);
        }

        $now = now();
        $userRoles = array_filter(array_map('trim', explode(',', (string) ($request->user()->role ?? ''))));

        $items = Announcement::query()
            ->where('active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->orderByDesc('id')
            ->get()
            ->filter(function ($a) use ($userRoles) {
                $roles = $a->roles ?? [];
                return empty($roles) || count(array_intersect($roles, $userRoles)) > 0;
            })
            ->map(fn ($a) => [
                'id' => $a->id, 'title' => $a->title, 'body' => $a->body,
                'type' => $a->type, 'dismissible' => $a->dismissible,
            ])
            ->values();

        return response()->json(['announcements' => $items]);
    }
}
