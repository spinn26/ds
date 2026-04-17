<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;

class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        // Any authenticated user may list — scoping is applied at the query level
        return true;
    }

    public function view(User $user, Client $client): bool
    {
        if ($user->isStaff()) {
            return true;
        }

        $consultant = $user->consultantRecord;
        return $consultant !== null && (int) $client->consultant === $consultant->id;
    }

    public function update(User $user, Client $client): bool
    {
        return $this->view($user, $client);
    }

    public function delete(User $user, Client $client): bool
    {
        return $user->isStaff();
    }
}
