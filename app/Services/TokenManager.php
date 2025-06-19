<?php

namespace App\Services;

use App\Models\User;
use DateTime;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TokenManager
{
    private string $baseUrl = 'https://bankaccountdata.gocardless.com/api/v2';

    private User $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    /**
     * Get a valid access token, refreshing if necessary.
     *
     * @throws \Exception
     */
    public function getAccessToken(): string
    {
        // Check if we have a valid access token
        if ($this->isAccessTokenValid()) {
            return $this->user->gocardless_access_token;
        }

        // Try to refresh the token
        if ($this->isRefreshTokenValid()) {
            return $this->refreshAccessToken();
        }

        // Get a new token set
        return $this->getNewTokenSet();
    }

    /**
     * Check if the access token is still valid.
     */
    private function isAccessTokenValid(): bool
    {
        if (! $this->user->gocardless_access_token || ! $this->user->gocardless_access_token_expires_at) {
            return false;
        }

        $expiresAt = $this->user->gocardless_access_token_expires_at;

        // Handle case where it might still be a string
        if (is_string($expiresAt)) {
            $expiresAt = new DateTime($expiresAt);
        }

        $now = new DateTime;

        // Add 5 minute buffer
        $now->modify('+5 minutes');

        return $expiresAt > $now;
    }

    /**
     * Check if the refresh token is still valid.
     */
    private function isRefreshTokenValid(): bool
    {
        if (! $this->user->gocardless_refresh_token || ! $this->user->gocardless_refresh_token_expires_at) {
            return false;
        }

        $expiresAt = $this->user->gocardless_refresh_token_expires_at;

        // Handle case where it might still be a string
        if (is_string($expiresAt)) {
            $expiresAt = new DateTime($expiresAt);
        }

        $now = new DateTime;

        return $expiresAt > $now;
    }

    /**
     * Refresh the access token using the refresh token.
     *
     * @throws \Exception
     */
    private function refreshAccessToken(): string
    {
        Log::info('Refreshing GoCardless access token', ['user_id' => $this->user->id]);

        $response = Http::post("{$this->baseUrl}/token/refresh/", [
            'refresh' => $this->user->gocardless_refresh_token,
        ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to refresh access token: '.$response->body());
        }

        $data = $response->json();

        // Validate response format
        if (! is_array($data) || ! isset($data['access'])) {
            Log::error('Invalid response format from GoCardless token refresh', [
                'user_id' => $this->user->id,
                'response_data' => $data,
            ]);
            throw new \Exception('Invalid response format from GoCardless API: missing access token');
        }

        $this->updateTokens($data);

        return $data['access'];
    }

    /**
     * Get a new token set using credentials.
     *
     * @throws \Exception
     */
    private function getNewTokenSet(): string
    {
        Log::info('Getting new GoCardless token set', ['user_id' => $this->user->id]);

        if (! $this->user->gocardless_secret_id || ! $this->user->gocardless_secret_key) {
            throw new \Exception('GoCardless credentials not configured');
        }

        $response = Http::post("{$this->baseUrl}/token/new/", [
            'secret_id' => $this->user->gocardless_secret_id,
            'secret_key' => $this->user->gocardless_secret_key,
        ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to get new token set: '.$response->body());
        }

        $data = $response->json();

        // Validate response format
        if (! is_array($data) || ! isset($data['access'])) {
            Log::error('Invalid response format from GoCardless new token request', [
                'user_id' => $this->user->id,
                'response_data' => $data,
            ]);
            throw new \Exception('Invalid response format from GoCardless API: missing access token');
        }

        $this->updateTokens($data);

        return $data['access'];
    }

    /**
     * Update user tokens in database.
     *
     * @throws \Exception
     */
    private function updateTokens(array $tokenData): void
    {
        // Validate required keys exist
        $requiredKeys = ['access', 'refresh', 'access_expires', 'refresh_expires'];
        $missingKeys = array_diff($requiredKeys, array_keys($tokenData));

        if (! empty($missingKeys)) {
            Log::error('Missing required token data keys', [
                'user_id' => $this->user->id,
                'missing_keys' => $missingKeys,
                'available_keys' => array_keys($tokenData),
            ]);
            throw new \Exception('Invalid token data: missing required keys: '.implode(', ', $missingKeys));
        }

        // Validate that expiry values are numeric
        if (! is_numeric($tokenData['access_expires']) || ! is_numeric($tokenData['refresh_expires'])) {
            Log::error('Invalid token expiry values', [
                'user_id' => $this->user->id,
                'access_expires' => $tokenData['access_expires'],
                'refresh_expires' => $tokenData['refresh_expires'],
            ]);
            throw new \Exception('Invalid token data: expiry values must be numeric');
        }

        $now = new DateTime;

        $accessExpiresAt = clone $now;
        $accessExpiresAt->modify('+'.$tokenData['access_expires'].' seconds');

        $refreshExpiresAt = clone $now;
        $refreshExpiresAt->modify('+'.$tokenData['refresh_expires'].' seconds');

        $this->user->update([
            'gocardless_access_token' => $tokenData['access'],
            'gocardless_refresh_token' => $tokenData['refresh'],
            'gocardless_access_token_expires_at' => $accessExpiresAt,
            'gocardless_refresh_token_expires_at' => $refreshExpiresAt,
        ]);

        Log::info('Updated GoCardless tokens', [
            'user_id' => $this->user->id,
            'access_expires_at' => $accessExpiresAt->format('Y-m-d H:i:s'),
            'refresh_expires_at' => $refreshExpiresAt->format('Y-m-d H:i:s'),
        ]);
    }
}
