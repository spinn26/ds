<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContestController extends Controller
{
    // status_contest: 1=Черновик, 2=Опубликован, 3=Завершён.
    // Публичная страница показывает только активные (Опубликован).
    private const ACTIVE_STATUS = 2;

    /**
     * Список активных конкурсов и событий.
     */
    public function index(Request $request): JsonResponse
    {
        $query = DB::table('Contest')
            ->where('status', self::ACTIVE_STATUS)
            ->orderByDesc('start');

        // Фильтр по типу
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        $rows = $query->get();
        $typeNames = DB::table('type_contest')
            ->whereIn('id', $rows->pluck('type')->filter()->unique()->values())
            ->pluck('type', 'id');

        $contests = $rows->map(function ($c) use ($typeNames) {
            $typeName = $c->type ? ($typeNames[$c->type] ?? null) : null;

            return [
                'id' => $c->id,
                'name' => $c->name,
                'description' => $c->description,
                'typeName' => $typeName,
                'status' => (int) $c->status,
                'statusLabel' => 'Активный',
                'start' => $c->start,
                'end' => $c->end,
                'numberOfWinners' => $c->numberOfWinners,
                'resultsPublicationDate' => $c->resultsPublicationDate,
                'presentation' => $c->presentation,
            ];
        });

        // Типы конкурсов для фильтра
        $types = DB::table('type_contest')
            ->orderBy('type')
            ->get()
            ->map(fn ($t) => ['id' => $t->id, 'name' => $t->type]);

        return response()->json([
            'contests' => $contests,
            'types' => $types,
        ]);
    }
}
