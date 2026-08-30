<?php

declare(strict_types=1);

namespace App\Services\GoCardless\ClientFactory;

use App\Models\User;
use App\Services\GoCardless\BankDataClientInterface;
use App\Services\GoCardless\CredentialsResolver;
use App\Services\GoCardless\GoCardlessBankDataClient;
use App\Services\GoCardless\TokenManager;

class ProductionClientFactory implements GoCardlessClientFactoryInterface
{
    public function __construct(
        private readonly CredentialsResolver $credentialsResolver
    ) {}

    /**
     * @throws \App\Exceptions\MissingGoCardlessCredentialsException When neither a personal
     *                                                               override nor instance credentials are usable.
     */
    public function make(User $user): BankDataClientInterface
    {
        $credentials = $this->credentialsResolver->resolve($user);

        $tokenManager = app(TokenManager::class, ['user' => $user, 'credentials' => $credentials]);

        return new GoCardlessBankDataClient(
            secretId: $credentials->secretId,
            secretKey: $credentials->secretKey,
            accessToken: $user->gocardless_access_token,
            refreshToken: $user->gocardless_refresh_token,
            refreshTokenExpires: $this->toDateTime($user->gocardless_refresh_token_expires_at),
            accessTokenExpires: $this->toDateTime($user->gocardless_access_token_expires_at),
            useCache: true,
            tokenManager: $tokenManager
        );
    }

    private function toDateTime(mixed $value): ?\DateTime
    {
        if ($value instanceof \DateTime) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return \DateTime::createFromInterface($value);
        }
        if (is_string($value)) {
            return new \DateTime($value);
        }

        return null;
    }
}
