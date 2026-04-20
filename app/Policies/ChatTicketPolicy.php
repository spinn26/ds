<?php

namespace App\Policies;

use App\Models\ChatTicket;
use App\Models\User;

class ChatTicketPolicy
{
    /**
     * Who may read / post to a ticket: staff, the creator, or the addressed recipient.
     * This single gate backs show, sendMessage, togglePin, toggleReaction —
     * anything that touches a specific ticket's contents.
     */
    public function view(User $user, ChatTicket $ticket): bool
    {
        if ($user->isStaff()) {
            return true;
        }

        return (int) $ticket->created_by === (int) $user->id
            || (int) ($ticket->recipient_id ?? 0) === (int) $user->id;
    }

    /** Status changes, assignment, notes — staff-only workflow. */
    public function update(User $user, ChatTicket $ticket): bool
    {
        return $user->isStaff();
    }
}
