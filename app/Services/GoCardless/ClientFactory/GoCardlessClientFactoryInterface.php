<?php

namespace App\Services\GoCardless\ClientFactory;

use App\Models\User;
use App\Services\GoCardless\BankDataClientInterface;

interface GoCardlessClientFactoryInterface
{
    public function make(User $user): BankDataClientInterface;
}
