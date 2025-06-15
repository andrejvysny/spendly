<?php

namespace App\Policies;

use App\Models\Merchant;
use App\Models\User;

class MerchantPolicy extends OwnedByUserPolicy
{
    // Additional merchant-specific authorization logic can be placed here
}
