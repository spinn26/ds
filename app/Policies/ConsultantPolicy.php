<?php

namespace App\Policies;

use App\Models\Consultant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ConsultantPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Consultant $consultant): bool
    {
        if ($user->isStaff()) {
            return true;
        }

        $own = $user->consultantRecord;
        if ($own === null) {
            return false;
        }

        if ($own->id === $consultant->id) {
            return true;
        }

        return $this->isDescendantOf($consultant->id, $own->id);
    }

    public function viewTree(User $user, Consultant $consultant): bool
    {
        return $this->view($user, $consultant);
    }

    public function update(User $user, Consultant $consultant): bool
    {
        if ($user->isStaff()) {
            return true;
        }

        $own = $user->consultantRecord;
        return $own !== null && $own->id === $consultant->id;
    }

    public function delete(User $user, Consultant $consultant): bool
    {
        return $user->isStaff();
    }

    /**
     * Walk UP the target's inviter chain; return true if ancestorId is encountered.
     * Guarded against cycles and limited to depth 20.
     */
    private function isDescendantOf(int $targetId, int $ancestorId): bool
    {
        $current = $targetId;
        $seen = [];

        for ($i = 0; $i < 20; $i++) {
            if (in_array($current, $seen, true)) {
                return false;
            }
            $seen[] = $current;

            $row = DB::table('consultant')->where('id', $current)->first(['inviter']);
            if (! $row || ! $row->inviter) {
                return false;
            }

            if ((int) $row->inviter === $ancestorId) {
                return true;
            }

            $current = (int) $row->inviter;
        }

        return false;
    }
}
