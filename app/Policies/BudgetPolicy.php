<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

class BudgetPolicy extends OwnedByUserPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }
}
