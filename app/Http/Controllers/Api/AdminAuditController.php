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
                  ->orWhere('entity_id', 'ilike', "%{$s}%")
                  ->orWhereRaw('payload::text ilike ?', ["%{$s}%"]);
            });
        }

        $total = (clone $q)->count();
        $raw = $q->orderByDesc('created_at')
            ->offset($this->paginationOffset($request))
            ->limit($this->paginationPerPage($request))
            ->get(['id', 'user_id', 'user_email', 'user_role', 'action', 'entity', 'entity_id', 'payload', 'ip', 'created_at']);

        // Резолвим имена WebUser одним запросом: и актор (user_id), и субъект
        // (entity_id, когда сущность — WebUser). Для login/password_reset актор
        // не аутентифицирован → user_* пусты, но субъект = entity_id.
        $userIds = [];
        foreach ($raw as $r) {
            if ($r->user_id) $userIds[] = (int) $r->user_id;
            if ($r->entity === 'WebUser' && is_numeric($r->entity_id)) $userIds[] = (int) $r->entity_id;
        }
        $users = empty($userIds)
            ? collect()
            : DB::table('WebUser')->whereIn('id', array_unique($userIds))
                ->get(['id', 'firstName', 'lastName', 'email', 'role'])->keyBy('id');

        $name = fn ($u) => $u ? trim("{$u->firstName} {$u->lastName}") : null;

        $rows = $raw->map(function ($r) use ($users, $name) {
            $payload = is_string($r->payload) ? json_decode($r->payload, true) : $r->payload;
            $actor = $r->user_id ? ($users[(int) $r->user_id] ?? null) : null;
            // Имя/почта актора: из WebUser по user_id, иначе из записи, иначе из payload.email
            $actorName = $name($actor);
            $actorEmail = $r->user_email ?: ($actor->email ?? ($payload['email'] ?? null));
            // Субъект действия (над кем): если сущность — WebUser, подставляем имя
            $subject = null;
            if ($r->entity === 'WebUser' && is_numeric($r->entity_id)) {
                $subject = $name($users[(int) $r->entity_id] ?? null);
            }
            // Если актор так и не определён, но субъект — WebUser (login и т.п.),
            // показываем субъекта как действующее лицо.
            if (! $actorName && ! $actorEmail && $subject) {
                $actorName = $subject;
                $subjectU = $users[(int) $r->entity_id] ?? null;
                $actorEmail = $subjectU->email ?? null;
            }

            return [
                'id' => $r->id,
                'userName' => $actorName,
                'userEmail' => $actorEmail,
                'userRole' => $r->user_role ?: ($actor->role ?? null),
                'action' => $r->action,
                'entity' => $r->entity,
                'entityId' => $r->entity_id,
                'subject' => $subject,
                'ip' => $r->ip,
                'createdAt' => $r->created_at,
                'payload' => $payload,
            ];
        });

        return response()->json([
            'data' => $rows,
            'total' => $total,
            'entities' => DB::table('audit_log')->distinct()->orderBy('entity')->pluck('entity'),
            'actions' => DB::table('audit_log')->distinct()->orderBy('action')->pluck('action'),
        ]);
    }
}
