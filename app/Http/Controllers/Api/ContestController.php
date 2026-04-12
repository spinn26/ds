<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContestController extends Controller
{
    /**
     * Список конкурсов и событий.
     */
    public function index(Request $request): JsonResponse
    {
        $query = DB::table('Contest')
            ->orderByDesc('start');

        // Фильтр по статусу
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Фильтр по типу
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        $contests = $query->get()->map(function ($c) {
            $typeName = $c->type
                ? DB::table('type_contest')->where('id', $c->type)->value('name')
                : null;

            $statusLabel = match ((int) $c->status) {
                1 => 'Активный',
                2 => 'Завершён',
                3 => 'Архив',
                default => 'Неизвестно',
            };

            return [
                'id' => $c->id,
                'name' => $c->name,
                'description' => $c->description,
                'typeName' => $typeName,
                'status' => (int) $c->status,
                'statusLabel' => $statusLabel,
                'start' => $c->start,
                'end' => $c->end,
                'numberOfWinners' => $c->numberOfWinners,
                'resultsPublicationDate' => $c->resultsPublicationDate,
                'presentation' => $c->presentation,
            ];
        });

        // Типы конкурсов для фильтра
        $types = DB::table('type_contest')
            ->orderBy('name')
            ->get()
            ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name]);

        return response()->json([
            'contests' => $contests,
            'types' => $types,
        ]);
    }
}
