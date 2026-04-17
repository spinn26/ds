<?php

namespace App\Policies;

use App\Models\Contract;
use App\Models\User;
use App\Services\ConsultantService;

class ContractPolicy
{
    public function __construct(
        private readonly ConsultantService $consultantService,
    ) {}

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Contract $contract): bool
    {
        if ($user->isStaff()) {
            return true;
        }

        $own = $user->consultantRecord;
        if ($own === null) {
            return false;
        }

        $teamIds = $this->consultantService->getTeamIds($own->id);
        return in_array((int) $contract->consultant, $teamIds, true);
    }

    public function create(User $user): bool
    {
        // Partners create their own contracts; staff create anywhere
        return $user->consultantRecord !== null || $user->isStaff();
    }

    public function update(User $user, Contract $contract): bool
    {
        if ($user->isStaff()) {
            return true;
        }

        $own = $user->consultantRecord;
        return $own !== null && (int) $contract->consultant === $own->id;
    }

    public function delete(User $user, Contract $contract): bool
    {
        return $user->isStaff();
    }
}
