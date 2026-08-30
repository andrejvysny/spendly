<?php

declare(strict_types=1);

namespace App\Services\GoCardless;

use App\Models\User;
use App\Services\GoCardless\DTOs\GoCardlessCredentials;
use App\Support\SensitiveDataRedactor;
use DateTime;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TokenManager
{
    private string $baseUrl = 'https://bankaccountdata.gocardless.com/api/v2';

    private User $user;

    private GoCardlessCredentials $credentials;

    public function __construct(User $user, GoCardlessCredentials $credentials)
    {
        $this->user = $user;
        $this->credentials = $credentials;
    }

    /**
     * Get a valid access token, refreshing if necessary.
     *
     * @throws \Exception
     */
    public function getAccessToken(): string
    {
        return Cache::lock("gc_token:{$this->user->id}", 60)->block(30, function () {
            // Re-read user from DB to get latest token values (may have been refreshed by another worker)
            $this->user->refresh();

            if ($this->credentialsChanged()) {
                return $this->rotateForNewCredentials();
            }

            if ($this->isAccessTokenValid()) {
                return $this->user->gocardless_access_token;
            }

            if ($this->isRefreshTokenValid()) {
                return $this->refreshAccessToken();
            }

            return $this->getNewTokenSet();
        });
    }

    /**
     * Rotate the access token after the API rejected one with a 401.
     *
     * Expiry is not consulted here: the API is the authority on whether a token works, and it has
     * already said this one does not. If another worker rotated the token while the failed request
     * was in flight, its token is returned as-is rather than burning a refresh grant on a token
     * that is probably already good.
     *
     * @param  string  $failedToken  The access token the API answered 401 to.
     *
     * @throws \Exception When no new token can be obtained.
     */
    public function refreshAfterUnauthorized(string $failedToken): string
    {
        $token = Cache::lock("gc_token:{$this->user->id}", 60)->block(30, function () use ($failedToken): string {
            // Re-read user from DB — another worker may have rotated the token in the meantime.
            $this->user->refresh();

            // A credential swap invalidates every stored token, including one another worker just
            // minted under the old pair, so this check comes before the already-rotated shortcut.
            if ($this->credentialsChanged()) {
                return $this->rotateForNewCredentials();
            }

            $current = $this->user->gocardless_access_token;

            if ($current && ! hash_equals($failedToken, $current)) {
                Log::info('GoCardless access token already rotated by another worker', ['user_id' => $this->user->id]);

                return $current;
            }

            return $this->isRefreshTokenValid() ? $this->refreshAccessToken() : $this->getNewTokenSet();
        });

        if (! is_string($token)) {
            throw new \Exception('Failed to obtain a GoCardless access token after authentication failure');
        }

        return $token;
    }

    /**
     * Whether the stored tokens were minted from a different secret pair than the one in use.
     *
     * True for rows written before the fingerprint column existed too — those tokens may well be
     * fine, but "unknown provenance" is not a claim worth trusting, and the cost is one extra mint.
     * Must only be called with a freshly read user inside the token lock.
     */
    private function credentialsChanged(): bool
    {
        $stored = $this->user->gocardless_token_secret_hash;

        return ! is_string($stored) || ! hash_equals($this->credentials->fingerprint(), $stored);
    }

    /**
     * Drop tokens belonging to a superseded secret pair and mint a set under the current one.
     *
     * The stored tokens are cleared before the mint rather than after, so a failing mint cannot
     * leave behind credentials that a later call would happily reuse against the wrong pair.
     *
     * @throws \Exception
     */
    private function rotateForNewCredentials(): string
    {
        Log::info('GoCardless credentials changed, discarding stored tokens', [
            'user_id' => $this->user->id,
            'source' => $this->credentials->source->value,
        ]);

        $this->user->update([
            'gocardless_access_token' => null,
            'gocardless_refresh_token' => null,
            'gocardless_access_token_expires_at' => null,
            'gocardless_refresh_token_expires_at' => null,
            'gocardless_token_secret_hash' => null,
        ]);

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

        $response = Http::timeout(30)->connectTimeout(10)
            ->post("{$this->baseUrl}/token/refresh/", [
                'refresh' => $this->user->gocardless_refresh_token,
            ]);

        if (! $response->successful()) {
            Log::warning('GoCardless token refresh failed', [
                'user_id' => $this->user->id,
                'status' => $response->status(),
                'body' => SensitiveDataRedactor::redact($response->body()),
            ]);
            throw new \Exception('Failed to refresh access token');
        }

        $data = $response->json();

        // Validate response format
        if (! is_array($data) || ! isset($data['access'])) {
            Log::error('Invalid response format from GoCardless token refresh', [
                'user_id' => $this->user->id,
                'response_data' => SensitiveDataRedactor::redact(json_encode($data) ?: ''),
            ]);
            throw new \Exception('Invalid response format from GoCardless API: missing access token');
        }

        $this->updateTokens($data);

        return $data['access'];
    }

    /**
     * Get a new token set using the resolved credentials.
     *
     * Reads the pair from the injected DTO, never from the user row: the pair in play may be the
     * instance-level one, in which case the user has no secret columns at all.
     *
     * @throws \Exception
     */
    private function getNewTokenSet(): string
    {
        Log::info('Getting new GoCardless token set', [
            'user_id' => $this->user->id,
            'source' => $this->credentials->source->value,
        ]);

        $response = Http::timeout(30)->connectTimeout(10)
            ->post("{$this->baseUrl}/token/new/", [
                'secret_id' => $this->credentials->secretId,
                'secret_key' => $this->credentials->secretKey,
            ]);

        if (! $response->successful()) {
            Log::warning('GoCardless new token request failed', [
                'user_id' => $this->user->id,
                'status' => $response->status(),
                'body' => SensitiveDataRedactor::redact($response->body()),
            ]);
            throw new \Exception('Failed to get new token set');
        }

        $data = $response->json();

        // Validate response format
        if (! is_array($data) || ! isset($data['access'])) {
            Log::error('Invalid response format from GoCardless new token request', [
                'user_id' => $this->user->id,
                'response_data' => SensitiveDataRedactor::redact(json_encode($data) ?: ''),
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
            // Written in the same statement as the tokens so the pair they belong to can never
            // drift apart from them.
            'gocardless_token_secret_hash' => $this->credentials->fingerprint(),
        ]);

        Log::info('Updated GoCardless tokens', [
            'user_id' => $this->user->id,
            'access_expires_at' => $accessExpiresAt->format('Y-m-d H:i:s'),
            'refresh_expires_at' => $refreshExpiresAt->format('Y-m-d H:i:s'),
        ]);
    }
}
