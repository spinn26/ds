<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultant;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboardService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $consultant = Consultant::where('webUser', $user->id)->first();

        if (! $consultant) {
            return response()->json(['message' => 'Консультант не найден'], 404);
        }

        $month = $request->input('month', now()->format('Y-m'));

        return response()->json($this->dashboardService->getDashboardData($consultant, $month));
    }

    public function statusLevels(): JsonResponse
    {
        $levels = DB::table('status_levels')
            ->orderBy('level')
            ->get()
            ->map(fn ($l) => [
                'id' => $l->id,
                'level' => $l->level,
                'title' => $l->title,
                'percent' => $l->percent,
                'personalVolume' => $l->personalVolume ?? 0,
                'groupVolume' => $l->groupVolume ?? 0,
                'groupVolumeCumulative' => $l->groupVolumeCumulative ?? 0,
                'otrif' => $l->otrif ?? 0,
                'pool' => $l->pool ?? 0,
                'dsShare' => $l->dsShare ?? 0,
            ]);

        return response()->json($levels);
    }
}
