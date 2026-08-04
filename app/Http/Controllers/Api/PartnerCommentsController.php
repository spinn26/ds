<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PartnerCommentsController extends Controller
{
    /** GET /partner-comments/{consultantId} */
    public function index(int $consultantId): JsonResponse
    {
        $rows = DB::connection('pgsql_v2')->table('partner_notes as pc')
            ->leftJoin('users as wu', 'wu.id', '=', 'pc.author_user_id')
            ->where('pc.partner_id', $consultantId)
            ->orderByDesc('pc.created_at')
            ->select([
                'pc.id',
                'pc.body',
                'pc.created_at',
                'pc.author_user_id as author_id',
                DB::raw("TRIM(COALESCE(wu.last_name,'') || ' ' || COALESCE(wu.first_name,'')) AS author_name"),
            ])
            ->get();

        return response()->json(['data' => $rows]);
    }

    /** POST /partner-comments */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'consultant_id' => 'required|integer|exists:pgsql_v2.partners,id',
            'body'          => 'required|string|max:2000',
        ]);

        $id = DB::connection('pgsql_v2')->table('partner_notes')->insertGetId([
            'partner_id'     => $data['consultant_id'],
            'author_user_id' => $request->user()->id,
            'body'          => trim($data['body']),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $row = DB::connection('pgsql_v2')->table('partner_notes as pc')
            ->leftJoin('users as wu', 'wu.id', '=', 'pc.author_user_id')
            ->where('pc.id', $id)
            ->select([
                'pc.id', 'pc.body', 'pc.created_at', 'pc.author_user_id as author_id',
                DB::raw("TRIM(COALESCE(wu.last_name,'') || ' ' || COALESCE(wu.first_name,'')) AS author_name"),
            ])
            ->first();

        return response()->json(['message' => 'Комментарий добавлен', 'comment' => $row], 201);
    }

    /** DELETE /partner-comments/{id} */
    public function destroy(int $id, Request $request): JsonResponse
    {
        $row = DB::connection('pgsql_v2')->table('partner_notes')->where('id', $id)->first();
        if (! $row) {
            return response()->json(['message' => 'Комментарий не найден'], 404);
        }
        if ((int) $row->author_user_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Нельзя удалить чужой комментарий'], 403);
        }
        DB::connection('pgsql_v2')->table('partner_notes')->where('id', $id)->delete();
        return response()->json(['message' => 'Удалён']);
    }
}
