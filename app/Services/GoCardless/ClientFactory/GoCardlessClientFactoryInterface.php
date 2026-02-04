<?php

namespace App\Services\GoCardless\ClientFactory;

use App\Services\GoCardless\BankDataClientInterface;
use App\Models\User;

interface GoCardlessClientFactoryInterface
{
    public function make(User $user): BankDataClientInterface;
}
