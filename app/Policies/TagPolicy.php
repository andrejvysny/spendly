<?php

namespace App\Policies;

use App\Models\Tag;
use App\Models\User;

class TagPolicy extends OwnedByUserPolicy
{
    // Additional tag-specific authorization logic can be placed here
}
