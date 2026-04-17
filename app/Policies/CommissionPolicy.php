<?php

namespace App\Policies;

use App\Models\Commission;
use App\Models\User;

class CommissionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Commission $commission): bool
    {
        if ($user->isStaff()) {
            return true;
        }

        $own = $user->consultantRecord;
        return $own !== null && (int) $commission->consultant === $own->id;
    }

    public function create(User $user): bool
    {
        return $user->isStaff();
    }

    public function update(User $user, Commission $commission): bool
    {
        return $user->isStaff();
    }

    public function delete(User $user, Commission $commission): bool
    {
        return $user->isStaff();
    }
}
