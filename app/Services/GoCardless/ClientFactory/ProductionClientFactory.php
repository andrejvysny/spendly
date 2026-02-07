<?php

namespace App\Services\GoCardless\ClientFactory;

use App\Services\GoCardless\BankDataClientInterface;
use App\Services\GoCardless\GoCardlessBankDataClient;
use App\Services\GoCardless\TokenManager;
use App\Models\User;

class ProductionClientFactory implements GoCardlessClientFactoryInterface
{
    public function make(User $user): BankDataClientInterface
    {
        // Use the service container to resolve TokenManager with the user
        $tokenManager = app(TokenManager::class, ['user' => $user]);
        $accessToken = $tokenManager->getAccessToken();

        // Ensure datetime fields are properly converted
        $refreshTokenExpires = $user->gocardless_refresh_token_expires_at;
        $accessTokenExpires = $user->gocardless_access_token_expires_at;

        // Convert to DateTime if they are strings
        if (is_string($refreshTokenExpires)) {
            $refreshTokenExpires = new \DateTime($refreshTokenExpires);
        }
        if (is_string($accessTokenExpires)) {
            $accessTokenExpires = new \DateTime($accessTokenExpires);
        }

        return new GoCardlessBankDataClient(
            $user->gocardless_secret_id,
            $user->gocardless_secret_key,
            $accessToken,
            $user->gocardless_refresh_token,
            $refreshTokenExpires,
            $accessTokenExpires,
            true // Enable caching
        );
    }
}
