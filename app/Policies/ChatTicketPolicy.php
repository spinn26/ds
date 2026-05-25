<?php

namespace App\Policies;

use App\Models\ChatTicket;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Support\Facades\DB;

class ChatTicketPolicy
{
    /**
     * Кто может читать / писать в тикет:
     *   - админ — всегда (особый случай в TicketService);
     *   - стафф — если его роль матчит department тикета;
     *   - создатель / recipient_id / assigned_to — всегда;
     *   - дополнительный участник (chat_ticket_participants) — всегда.
     */
    public function view(User $user, ChatTicket $ticket): bool
    {
        if ((int) $ticket->created_by === (int) $user->id) return true;
        if ((int) ($ticket->recipient_id ?? 0) === (int) $user->id) return true;
        if ((int) ($ticket->assigned_to ?? 0) === (int) $user->id) return true;

        // Дополнительные участники чата (приглашённые сотрудники).
        $isParticipant = DB::table('chat_ticket_participants')
            ->where('ticket_id', $ticket->id)
            ->where('user_id', $user->id)
            ->exists();
        if ($isParticipant) return true;

        if (! $user->isStaff()) return false;

        $roles = array_map('trim', explode(',', $user->role ?? ''));
        if (! TicketService::staffCanSeeCategory($roles, $ticket->department)) return false;

        // Claim & hide: после того как тикет взят в работу (assigned_to NOT NULL),
        // прочим staff отдела доступ закрыт. Сам assignee видит тикет через
        // более раннюю проверку (assigned_to === user->id). Зеркалит фильтр
        // в ChatController::index() — чтобы прямой URL не обходил список.
        return empty($ticket->assigned_to);
    }

    /** Status changes, assignment, notes — staff-only workflow. */
    public function update(User $user, ChatTicket $ticket): bool
    {
        return $user->isStaff();
    }

    /**
     * Полное удаление чата вместе со всей перепиской и вложениями.
     * Деструктивная операция без отката, поэтому только админ —
     * support/finance/etc. при необходимости пользуются «закрытием»
     * через updateStatus.
     */
    public function delete(User $user, ChatTicket $ticket): bool
    {
        $roles = array_map('trim', explode(',', $user->role ?? ''));
        return in_array('admin', $roles, true);
    }
}
