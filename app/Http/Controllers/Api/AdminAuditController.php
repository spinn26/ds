<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\PaginatesRequests;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Просмотр аудит-лога (audit_log) с фильтрами. Только admin. */
class AdminAuditController extends Controller
{
    use PaginatesRequests;

    public function index(Request $request): JsonResponse
    {
        if (! Schema::hasTable('audit_log')) {
            return response()->json(['data' => [], 'total' => 0, 'entities' => [], 'actions' => []]);
        }

        $q = DB::table('audit_log');
        if ($e = $request->input('entity')) $q->where('entity', $e);
        if ($a = $request->input('action')) $q->where('action', $a);
        if ($from = $request->input('from')) $q->whereDate('created_at', '>=', $from);
        if ($to = $request->input('to')) $q->whereDate('created_at', '<=', $to);
        if ($s = trim((string) $request->input('search', ''))) {
            $q->where(function ($x) use ($s) {
                $x->where('user_email', 'ilike', "%{$s}%")
                  ->orWhere('ip', 'ilike', "%{$s}%")
                  ->orWhere('entity_id', 'ilike', "%{$s}%");
            });
        }

        $total = (clone $q)->count();
        $rows = $q->orderByDesc('created_at')
            ->offset($this->paginationOffset($request))
            ->limit($this->paginationPerPage($request))
            ->get(['id', 'user_id', 'user_email', 'user_role', 'action', 'entity', 'entity_id', 'payload', 'ip', 'created_at'])
            ->map(fn ($r) => [
                'id' => $r->id,
                'userEmail' => $r->user_email,
                'userRole' => $r->user_role,
                'action' => $r->action,
                'entity' => $r->entity,
                'entityId' => $r->entity_id,
                'ip' => $r->ip,
                'createdAt' => $r->created_at,
                'payload' => is_string($r->payload) ? json_decode($r->payload, true) : $r->payload,
            ]);

        return response()->json([
            'data' => $rows,
            'total' => $total,
            'entities' => DB::table('audit_log')->distinct()->orderBy('entity')->pluck('entity'),
            'actions' => DB::table('audit_log')->distinct()->orderBy('action')->pluck('action'),
        ]);
    }
}
