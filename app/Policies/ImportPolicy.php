<?php

namespace App\Policies;

use App\Models\Import\Import;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ImportPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view the import.
     */
    public function view(User $user, Import $import): bool
    {
        return $user->id === $import->user_id;
    }

    /**
     * Determine whether the user can update the import.
     */
    public function update(User $user, Import $import): bool
    {
        return $user->id === $import->user_id;
    }

    /**
     * Determine whether the user can delete the import.
     */
    public function delete(User $user, Import $import): bool
    {
        return $user->id === $import->user_id;
    }
}
