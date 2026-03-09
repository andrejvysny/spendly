<?php

declare(strict_types=1);

namespace App\Services\GoCardless\ClientFactory;

use App\Models\User;
use App\Services\GoCardless\BankDataClientInterface;
use App\Services\GoCardless\GoCardlessBankDataClient;
use App\Services\GoCardless\TokenManager;

class ProductionClientFactory implements GoCardlessClientFactoryInterface
{
    public function make(User $user): BankDataClientInterface
    {
        if (! $user->gocardless_secret_id || ! $user->gocardless_secret_key) {
            throw new \InvalidArgumentException(
                'GoCardless credentials not configured. Please add your Secret ID and Secret Key in Settings > Bank Data.'
            );
        }

        $tokenManager = app(TokenManager::class, ['user' => $user]);

        return new GoCardlessBankDataClient(
            secretId: $user->gocardless_secret_id,
            secretKey: $user->gocardless_secret_key,
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
