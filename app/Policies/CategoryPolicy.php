<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;

class CategoryPolicy extends OwnedByUserPolicy
{
    // Additional category-specific authorization logic can be placed here
}
