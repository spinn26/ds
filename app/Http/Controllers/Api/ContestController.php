<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContestController extends Controller
{
    private const ACTIVE_STATUS = 2;

    public function index(Request $request): JsonResponse
    {
        $db = DB::connection('pgsql_v2');
        $query = $db->table('contests as c')
            ->leftJoin('contest_types as ct', 'ct.id', '=', 'c.type_id')
            ->where('c.status_id', self::ACTIVE_STATUS)
            ->orderByDesc('c.starts_on');

        if ($request->filled('type')) {
            $query->where('c.type_id', $request->integer('type'));
        }

        $contests = $query->get(['c.*', 'ct.name as type_name'])->map(fn ($contest) => [
            'id' => $contest->id,
            'name' => $contest->name,
            'description' => $contest->description,
            'typeName' => $contest->type_name,
            'status' => (int) $contest->status_id,
            'statusLabel' => 'Активный',
            'start' => $contest->starts_on,
            'end' => $contest->ends_on,
            'numberOfWinners' => $contest->winner_count,
            'resultsPublicationDate' => $contest->results_published_on,
            'presentation' => $contest->presentation_url,
        ]);

        $types = $db->table('contest_types')->where('active', true)->orderBy('name')->get(['id', 'name']);

        return response()->json(['contests' => $contests, 'types' => $types]);
    }
}
