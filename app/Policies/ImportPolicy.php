<?php

namespace App\Policies;

use App\Contracts\OwnedByUserContract;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ImportPolicy extends
{
    use HandlesAuthorization;


}
