<?php

namespace App\Policies;

use App\Models\FeeType;
use App\Models\User;

class FeeTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, FeeType $feeType): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, FeeType $feeType): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, FeeType $feeType): bool
    {
        return false;
    }
}
