<?php

declare(strict_types=1);

namespace App\Services\GoCardless\ClientFactory;

use App\Models\User;
use App\Services\GoCardless\BankDataClientInterface;
use App\Services\GoCardless\Mock\MockGoCardlessFixtureRepository;
use App\Services\GoCardless\MockGoCardlessBankDataClient;

class MockClientFactory implements GoCardlessClientFactoryInterface
{
    public function __construct(
        private readonly MockGoCardlessFixtureRepository $fixtureRepository
    ) {}

    public function make(User $user): BankDataClientInterface
    {
        return new MockGoCardlessBankDataClient($user, $this->fixtureRepository);
    }
}
