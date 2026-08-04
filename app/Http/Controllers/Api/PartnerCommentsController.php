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
        $rows = DB::table('partner_comments as pc')
            ->leftJoin('WebUser as wu', 'wu.id', '=', 'pc.author_id')
            ->where('pc.consultant_id', $consultantId)
            ->orderByDesc('pc.created_at')
            ->select([
                'pc.id',
                'pc.body',
                'pc.created_at',
                'pc.author_id',
                DB::raw("TRIM(COALESCE(wu.\"lastName\",'') || ' ' || COALESCE(wu.\"firstName\",'')) AS author_name"),
            ])
            ->get();

        return response()->json(['data' => $rows]);
    }

    /** POST /partner-comments */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'consultant_id' => 'required|integer|exists:consultant,id',
            'body'          => 'required|string|max:2000',
        ]);

        $id = DB::table('partner_comments')->insertGetId([
            'consultant_id' => $data['consultant_id'],
            'author_id'     => $request->user()->id,
            'body'          => trim($data['body']),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $row = DB::table('partner_comments as pc')
            ->leftJoin('WebUser as wu', 'wu.id', '=', 'pc.author_id')
            ->where('pc.id', $id)
            ->select([
                'pc.id', 'pc.body', 'pc.created_at', 'pc.author_id',
                DB::raw("TRIM(COALESCE(wu.\"lastName\",'') || ' ' || COALESCE(wu.\"firstName\",'')) AS author_name"),
            ])
            ->first();

        return response()->json(['message' => 'Комментарий добавлен', 'comment' => $row], 201);
    }

    /** DELETE /partner-comments/{id} */
    public function destroy(int $id, Request $request): JsonResponse
    {
        $row = DB::table('partner_comments')->where('id', $id)->first();
        if (! $row) {
            return response()->json(['message' => 'Комментарий не найден'], 404);
        }
        if ((int) $row->author_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Нельзя удалить чужой комментарий'], 403);
        }
        DB::table('partner_comments')->where('id', $id)->delete();
        return response()->json(['message' => 'Удалён']);
    }
}
