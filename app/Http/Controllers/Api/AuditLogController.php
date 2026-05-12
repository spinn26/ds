<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->hasAnyRole(['admin'])) {
            abort(403);
        }

        $q = DB::table('audit_log');
        if ($request->filled('action')) $q->where('action', $request->input('action'));
        if ($request->filled('entity')) $q->where('entity', $request->input('entity'));
        if ($request->filled('user_id')) $q->where('user_id', (int) $request->input('user_id'));
        if ($request->filled('search')) {
            $s = '%' . $request->input('search') . '%';
            $q->where(function ($w) use ($s) {
                $w->where('action', 'ilike', $s)
                  ->orWhere('entity', 'ilike', $s)
                  ->orWhere('user_email', 'ilike', $s)
                  ->orWhere('entity_id', 'ilike', $s);
            });
        }
        if ($request->filled('from')) $q->where('created_at', '>=', $request->input('from'));
        if ($request->filled('to')) $q->where('created_at', '<=', $request->input('to') . ' 23:59:59');

        $total = $q->count();
        $rows = $q->orderByDesc('id')
            ->offset(max(0, ((int) $request->input('page', 1) - 1) * 50))
            ->limit(50)
            ->get();

        return response()->json([
            'data' => $rows,
            'total' => $total,
        ]);
    }
}
