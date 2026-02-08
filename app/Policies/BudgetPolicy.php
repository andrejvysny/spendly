<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Budget;
use App\Models\User;

class BudgetPolicy extends OwnedByUserPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Budget $budget): bool
    {
        return $user->getId() === $budget->getUserId();
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Budget $budget): bool
    {
        return $user->getId() === $budget->getUserId();
    }

    public function delete(User $user, Budget $budget): bool
    {
        return $user->getId() === $budget->getUserId();
    }
}
