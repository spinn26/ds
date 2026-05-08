<?php

namespace App\Policies;

use App\Models\ChatTicket;
use App\Models\User;
use App\Services\TicketService;

class ChatTicketPolicy
{
    /**
     * Кто может читать / писать в тикет:
     *   - админ — всегда (особый случай в TicketService);
     *   - стафф — если его роль матчит department тикета (support видит
     *     только support-тикеты, finance — accruals, и т.д.). Раньше
     *     любой стафф видел всё, что приводило к перегруженной выдаче
     *     и нарушало приватность партнёрских переписок.
     *   - создатель и явный получатель (recipient_id) — всегда.
     *   - назначенный (assigned_to) — для эскалаций между отделами.
     */
    public function view(User $user, ChatTicket $ticket): bool
    {
        if ((int) $ticket->created_by === (int) $user->id) return true;
        if ((int) ($ticket->recipient_id ?? 0) === (int) $user->id) return true;
        if ((int) ($ticket->assigned_to ?? 0) === (int) $user->id) return true;

        if (! $user->isStaff()) return false;

        $roles = array_map('trim', explode(',', $user->role ?? ''));
        return TicketService::staffCanSeeCategory($roles, $ticket->department);
    }

    /** Status changes, assignment, notes — staff-only workflow. */
    public function update(User $user, ChatTicket $ticket): bool
    {
        return $user->isStaff();
    }
}
