<?php

namespace App\Policies;

use App\Contracts\OwnedByUserContract;
use App\Models\User;

class OwnedByUserPolicy
{
    public function view(User $user, OwnedByUserContract $import): bool
    {
        return $user->getId() === $import->getUserId();
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, OwnedByUserContract $import): bool
    {
        return $user->getId() === $import->getUserId();
    }

    public function delete(User $user, OwnedByUserContract $import): bool
    {
        return $user->getId() === $import->getUserId();
    }
}
