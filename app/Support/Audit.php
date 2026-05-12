<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Аудит чувствительных действий админов.
 *
 *   Audit::log('role_change', 'WebUser', $userId, ['from'=>'partner','to'=>'admin']);
 *   Audit::log('delete', 'chat_ticket', $ticketId);
 *
 * Падать не должен — лог-фейл не блокирует бизнес-операцию.
 */
class Audit
{
    public static function log(string $action, string $entity, $entityId = null, array $payload = []): void
    {
        try {
            $req = request();
            $user = $req?->user();
            DB::table('audit_log')->insert([
                'user_id' => $user?->id,
                'user_email' => $user?->email,
                'user_role' => $user?->role,
                'action' => $action,
                'entity' => $entity,
                'entity_id' => $entityId !== null ? (string) $entityId : null,
                'payload' => $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
                'ip' => $req?->ip(),
                'user_agent' => substr((string) $req?->userAgent(), 0, 500),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('audit log failed', [
                'action' => $action,
                'entity' => $entity,
                'entity_id' => $entityId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
