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

        $this->storeRefreshedAccessToken($data);

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

        $this->storeNewTokenPair($data);

        return $data['access'];
    }

    /**
     * Persist a freshly minted token pair from POST /token/new/.
     *
     * That endpoint (SpectacularJWTObtain) is the only one that hands out a refresh token, so all
     * four fields are genuinely required here and a missing one is a real protocol violation.
     *
     * @param  array<string, mixed>  $tokenData
     *
     * @throws \Exception
     */
    private function storeNewTokenPair(array $tokenData): void
    {
        $this->assertPresent($tokenData, ['access', 'refresh', 'access_expires', 'refresh_expires']);
        $this->assertNumeric($tokenData, ['access_expires', 'refresh_expires']);

        $accessExpiresAt = $this->expiryFromNow($tokenData['access_expires']);
        $refreshExpiresAt = $this->expiryFromNow($tokenData['refresh_expires']);

        $this->user->update([
            'gocardless_access_token' => $tokenData['access'],
            'gocardless_refresh_token' => $tokenData['refresh'],
            'gocardless_access_token_expires_at' => $accessExpiresAt,
            'gocardless_refresh_token_expires_at' => $refreshExpiresAt,
            // Written in the same statement as the tokens so the pair they belong to can never
            // drift apart from them.
            'gocardless_token_secret_hash' => $this->credentials->fingerprint(),
        ]);

        Log::info('Stored new GoCardless token pair', [
            'user_id' => $this->user->id,
            'access_expires_at' => $accessExpiresAt->format('Y-m-d H:i:s'),
            'refresh_expires_at' => $refreshExpiresAt->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Persist the access token returned by POST /token/refresh/.
     *
     * SpectacularJWTRefresh carries only `access` and `access_expires` — refreshing does NOT rotate
     * the refresh token. Requiring `refresh`/`refresh_expires` here (as one shared writer used to)
     * made every valid refresh look like a malformed response, so once the 24h access token lapsed
     * every sync threw until the 30-day refresh grant itself expired. The stored refresh token is
     * therefore carried forward untouched, and only replaced if GoCardless ever does send a new one.
     *
     * @param  array<string, mixed>  $tokenData
     *
     * @throws \Exception
     */
    private function storeRefreshedAccessToken(array $tokenData): void
    {
        $this->assertPresent($tokenData, ['access', 'access_expires']);
        $this->assertNumeric($tokenData, ['access_expires']);

        $accessExpiresAt = $this->expiryFromNow($tokenData['access_expires']);

        $attributes = [
            'gocardless_access_token' => $tokenData['access'],
            'gocardless_access_token_expires_at' => $accessExpiresAt,
            'gocardless_token_secret_hash' => $this->credentials->fingerprint(),
        ];

        // Forward-compatible: honour a rotated refresh token if one ever appears, but never require
        // it. Its expiry is only moved when the response actually states a new one.
        if (isset($tokenData['refresh']) && is_string($tokenData['refresh']) && $tokenData['refresh'] !== '') {
            $attributes['gocardless_refresh_token'] = $tokenData['refresh'];

            if (isset($tokenData['refresh_expires'])) {
                $this->assertNumeric($tokenData, ['refresh_expires']);
                $attributes['gocardless_refresh_token_expires_at'] = $this->expiryFromNow($tokenData['refresh_expires']);
            }
        }

        $this->user->update($attributes);

        Log::info('Stored refreshed GoCardless access token', [
            'user_id' => $this->user->id,
            'access_expires_at' => $accessExpiresAt->format('Y-m-d H:i:s'),
            'refresh_token_rotated' => array_key_exists('gocardless_refresh_token', $attributes),
        ]);
    }

    /**
     * @param  array<string, mixed>  $tokenData
     * @param  list<string>  $keys
     *
     * @throws \Exception
     */
    private function assertPresent(array $tokenData, array $keys): void
    {
        $missingKeys = array_diff($keys, array_keys($tokenData));

        if ($missingKeys === []) {
            return;
        }

        Log::error('Missing required token data keys', [
            'user_id' => $this->user->id,
            'missing_keys' => array_values($missingKeys),
            'available_keys' => array_keys($tokenData),
        ]);

        throw new \Exception('Invalid token data: missing required keys: '.implode(', ', $missingKeys));
    }

    /**
     * @param  array<string, mixed>  $tokenData
     * @param  list<string>  $keys
     *
     * @throws \Exception
     */
    private function assertNumeric(array $tokenData, array $keys): void
    {
        foreach ($keys as $key) {
            if (is_numeric($tokenData[$key] ?? null)) {
                continue;
            }

            Log::error('Invalid token expiry value', [
                'user_id' => $this->user->id,
                'key' => $key,
            ]);

            throw new \Exception('Invalid token data: expiry values must be numeric');
        }
    }

    private function expiryFromNow(mixed $seconds): DateTime
    {
        // assertNumeric() has already run for every caller; repeated here because the narrowing
        // does not survive the call boundary and a bad cast would silently mean "expires now".
        $seconds = is_numeric($seconds) ? (int) $seconds : 0;

        $expiresAt = new DateTime;
        $expiresAt->modify('+'.$seconds.' seconds');

        return $expiresAt;
    }
}
