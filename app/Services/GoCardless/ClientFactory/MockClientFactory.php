<?php

namespace App\Services\GoCardless\ClientFactory;

use App\Services\GoCardless\BankDataClientInterface;
use App\Services\GoCardless\MockGoCardlessBankDataClient;
use App\Models\User;

class MockClientFactory implements GoCardlessClientFactoryInterface
{
    public function make(User $user): BankDataClientInterface
    {
        return new MockGoCardlessBankDataClient($user);
    }
}
