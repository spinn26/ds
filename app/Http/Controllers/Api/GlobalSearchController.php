<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GlobalSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Сквозной поиск верхней панели. Логика — в GlobalSearchService, включая
 * фильтрацию разделов по правам сотрудника.
 */
class GlobalSearchController extends Controller
{
    public function __invoke(Request $request, GlobalSearchService $search): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $user->isStaff()) {
            return response()->json(['query' => '', 'groups' => [], 'total' => 0]);
        }

        return response()->json($search->search((string) $request->input('q', ''), $user));
    }
}
