<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StructureController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $consultant = Consultant::where('person', $user->id)->first();

        if (! $consultant) {
            return response()->json(['data' => []]);
        }

        // Get direct children (level 0)
        $children = DB::table('consultantStructure')
            ->where('parent', $consultant->id)
            ->pluck('child')
            ->toArray();

        $members = Consultant::whereIn('id', $children)
            ->get()
            ->map(function ($c) {
                $statusLevel = null;
                if ($c->status_and_lvl) {
                    $statusLevel = DB::table('status_levels')->where('id', $c->status_and_lvl)->first();
                }

                $qLog = DB::table('qualificationLog')
                    ->where('consultant', $c->id)
                    ->whereNull('dateDeleted')
                    ->orderByDesc('date')
                    ->first();

                $clientCount = DB::table('client')
                    ->where('consultant', $c->id)
                    ->where('active', true)
                    ->count();

                $contractCount = DB::table('contract')
                    ->where('consultant', $c->id)
                    ->whereNull('deletedAt')
                    ->count();

                $subCount = DB::table('consultantStructure')
                    ->where('parent', $c->id)
                    ->count();

                $activityName = $c->activity
                    ? DB::table('directory_of_activities')->where('id', $c->activity)->value('name')
                    : null;

                return [
                    'id' => $c->id,
                    'personName' => $c->personName,
                    'active' => $c->active,
                    'level' => 0,
                    'qualification' => $statusLevel ? [
                        'level' => $statusLevel->level,
                        'title' => $statusLevel->title,
                    ] : null,
                    'activityName' => $activityName ?? ($c->active ? 'Активный' : 'Терминирован'),
                    'personalVolume' => round((float) ($qLog->personalVolume ?? $c->personalVolume ?? 0), 2),
                    'groupVolume' => round((float) ($qLog->groupVolume ?? $c->groupVolume ?? 0), 2),
                    'groupVolumeCumulative' => round((float) ($qLog->groupVolumeCumulative ?? $c->groupVolumeCumulative ?? 0), 2),
                    'clientCount' => $clientCount,
                    'contractCount' => $contractCount,
                    'hasChildren' => $subCount > 0,
                    'dateActivity' => $c->dateActivity,
                ];
            });

        // Filters
        if ($request->filled('search')) {
            $search = mb_strtolower($request->search);
            $members = $members->filter(fn ($m) => str_contains(mb_strtolower($m['personName']), $search));
        }
        if ($request->filled('active')) {
            $isActive = $request->active === 'true';
            $members = $members->filter(fn ($m) => $m['active'] === $isActive);
        }

        return response()->json(['data' => $members->values()]);
    }

    public function children(Request $request, int $consultantId): JsonResponse
    {
        $children = DB::table('consultantStructure')
            ->where('parent', $consultantId)
            ->pluck('child')
            ->toArray();

        $members = Consultant::whereIn('id', $children)
            ->get()
            ->map(function ($c) {
                $statusLevel = $c->status_and_lvl
                    ? DB::table('status_levels')->where('id', $c->status_and_lvl)->first()
                    : null;

                $qLog = DB::table('qualificationLog')
                    ->where('consultant', $c->id)
                    ->whereNull('dateDeleted')
                    ->orderByDesc('date')
                    ->first();

                $subCount = DB::table('consultantStructure')
                    ->where('parent', $c->id)
                    ->count();

                return [
                    'id' => $c->id,
                    'personName' => $c->personName,
                    'active' => $c->active,
                    'qualification' => $statusLevel ? [
                        'level' => $statusLevel->level,
                        'title' => $statusLevel->title,
                    ] : null,
                    'activityName' => $c->active ? 'Активный' : 'Терминирован',
                    'personalVolume' => round((float) ($qLog->personalVolume ?? 0), 2),
                    'groupVolume' => round((float) ($qLog->groupVolume ?? 0), 2),
                    'groupVolumeCumulative' => round((float) ($qLog->groupVolumeCumulative ?? 0), 2),
                    'clientCount' => DB::table('client')->where('consultant', $c->id)->where('active', true)->count(),
                    'contractCount' => DB::table('contract')->where('consultant', $c->id)->whereNull('deletedAt')->count(),
                    'hasChildren' => $subCount > 0,
                ];
            });

        return response()->json(['data' => $members->values()]);
    }
}
