<?php

declare(strict_types=1);

namespace Tests\Unit\Services\GoCardless;

use App\Enums\GoCardlessCredentialSource;
use App\Models\User;
use App\Services\GoCardless\DTOs\GoCardlessCredentials;
use App\Services\GoCardless\TokenManager;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TokenManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
    }

    private function credentials(
        string $secretId = 'secret_id',
        string $secretKey = 'secret_key',
        GoCardlessCredentialSource $source = GoCardlessCredentialSource::USER,
    ): GoCardlessCredentials {
        return new GoCardlessCredentials($secretId, $secretKey, $source);
    }

    /**
     * Two workers can hit a 401 on the same expired token. The second one must not spend a refresh
     * grant re-doing work the first one already finished.
     */
    public function test_refresh_after_unauthorized_returns_other_workers_token_without_api_call(): void
    {
        Http::fake();

        $credentials = $this->credentials();

        $user = User::factory()->create([
            'gocardless_secret_id' => 'secret_id',
            'gocardless_secret_key' => 'secret_key',
            'gocardless_token_secret_hash' => $credentials->fingerprint(),
            'gocardless_access_token' => 'rotated_token',
            'gocardless_refresh_token' => 'refresh_token',
            'gocardless_access_token_expires_at' => now()->addHour(),
            'gocardless_refresh_token_expires_at' => now()->addDay(),
        ]);

        $manager = new TokenManager($user, $credentials);

        // Another worker already replaced the token this request failed with.
        $token = $manager->refreshAfterUnauthorized('stale_token');

        $this->assertSame('rotated_token', $token);
        Http::assertNothingSent();
    }

    /**
     * The token the API rejected is still the stored one, so it must actually be rotated —
     * regardless of the stored expiry claiming it is still fresh.
     */
    public function test_refresh_after_unauthorized_uses_refresh_token_when_valid(): void
    {
        // The real SpectacularJWTRefresh schema: access + access_expires only, no refresh token.
        Http::fake([
            '*/token/refresh/' => Http::response([
                'access' => 'new_access_token',
                'access_expires' => 3600,
            ], 200),
        ]);

        $credentials = $this->credentials();

        $user = User::factory()->create([
            'gocardless_secret_id' => 'secret_id',
            'gocardless_secret_key' => 'secret_key',
            'gocardless_token_secret_hash' => $credentials->fingerprint(),
            'gocardless_access_token' => 'failed_token',
            'gocardless_refresh_token' => 'valid_refresh_token',
            // Expiry says the token is fine; the 401 says otherwise and wins.
            'gocardless_access_token_expires_at' => now()->addHour(),
            'gocardless_refresh_token_expires_at' => now()->addDay(),
        ]);

        $manager = new TokenManager($user, $credentials);

        $token = $manager->refreshAfterUnauthorized('failed_token');

        $this->assertSame('new_access_token', $token);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/token/refresh/')
            && $request['refresh'] === 'valid_refresh_token');

        $user->refresh();
        $this->assertSame('new_access_token', $user->gocardless_access_token);
        // Refreshing does not rotate the refresh token — the stored one must survive untouched.
        $this->assertSame('valid_refresh_token', $user->gocardless_refresh_token);
    }

    /**
     * A refresh response carrying only `access` and `access_expires` is what GoCardless actually
     * returns (SpectacularJWTRefresh). Treating it as malformed used to throw
     * "missing required keys: refresh, refresh_expires" on every sync once the 24h access token
     * lapsed, and kept throwing until the 30-day refresh grant expired.
     */
    public function test_refresh_accepts_response_without_refresh_token_and_preserves_grant(): void
    {
        Http::fake([
            '*/token/refresh/' => Http::response([
                'access' => 'refreshed_access_token',
                'access_expires' => 86400,
            ], 200),
        ]);

        $credentials = $this->credentials();
        $refreshExpiry = now()->addDays(20);

        $user = User::factory()->create([
            'gocardless_secret_id' => 'secret_id',
            'gocardless_secret_key' => 'secret_key',
            'gocardless_token_secret_hash' => $credentials->fingerprint(),
            'gocardless_access_token' => 'expired_access_token',
            'gocardless_refresh_token' => 'long_lived_refresh_token',
            'gocardless_access_token_expires_at' => now()->subHour(),
            'gocardless_refresh_token_expires_at' => $refreshExpiry,
        ]);

        $token = (new TokenManager($user, $credentials))->getAccessToken();

        $this->assertSame('refreshed_access_token', $token);

        $user->refresh();
        $this->assertSame('refreshed_access_token', $user->gocardless_access_token);
        $this->assertSame('long_lived_refresh_token', $user->gocardless_refresh_token);
        $this->assertTrue($user->gocardless_access_token_expires_at->isFuture());
        // The refresh grant's own expiry is not moved by an access-token refresh.
        $this->assertSame(
            $refreshExpiry->format('Y-m-d H:i'),
            $user->gocardless_refresh_token_expires_at->format('Y-m-d H:i')
        );

        // Never fell back to minting a brand new pair.
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/token/new/'));
    }

    /**
     * Forward compatibility: if GoCardless ever does rotate the refresh token on a refresh, take it.
     */
    public function test_refresh_adopts_a_rotated_refresh_token_when_one_is_returned(): void
    {
        Http::fake([
            '*/token/refresh/' => Http::response([
                'access' => 'refreshed_access_token',
                'access_expires' => 86400,
                'refresh' => 'rotated_refresh_token',
                'refresh_expires' => 2592000,
            ], 200),
        ]);

        $credentials = $this->credentials();

        $user = User::factory()->create([
            'gocardless_secret_id' => 'secret_id',
            'gocardless_secret_key' => 'secret_key',
            'gocardless_token_secret_hash' => $credentials->fingerprint(),
            'gocardless_access_token' => 'expired_access_token',
            'gocardless_refresh_token' => 'old_refresh_token',
            'gocardless_access_token_expires_at' => now()->subHour(),
            'gocardless_refresh_token_expires_at' => now()->addDays(20),
        ]);

        $this->assertSame('refreshed_access_token', (new TokenManager($user, $credentials))->getAccessToken());

        $user->refresh();
        $this->assertSame('rotated_refresh_token', $user->gocardless_refresh_token);
    }

    /**
     * With no usable refresh token the only way forward is a brand new token set.
     */
    public function test_refresh_after_unauthorized_falls_back_to_new_token_set(): void
    {
        Http::fake([
            '*/token/new/' => Http::response([
                'access' => 'brand_new_token',
                'refresh' => 'brand_new_refresh',
                'access_expires' => 3600,
                'refresh_expires' => 86400,
            ], 200),
        ]);

        $credentials = $this->credentials();

        $user = User::factory()->create([
            'gocardless_secret_id' => 'secret_id',
            'gocardless_secret_key' => 'secret_key',
            'gocardless_token_secret_hash' => $credentials->fingerprint(),
            'gocardless_access_token' => 'failed_token',
            'gocardless_refresh_token' => 'expired_refresh_token',
            'gocardless_access_token_expires_at' => now()->subHour(),
            'gocardless_refresh_token_expires_at' => now()->subHour(),
        ]);

        $manager = new TokenManager($user, $credentials);

        $this->assertSame('brand_new_token', $manager->refreshAfterUnauthorized('failed_token'));

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/token/new/'));
    }

    /**
     * A failed token/new request must not echo the raw response body — which can carry fragments
     * of the credentials being validated — back in the exception message.
     */
    public function test_new_token_set_failure_excludes_response_body(): void
    {
        Http::fake([
            '*/token/new/' => Http::response(['error' => 'invalid secret_key sk_live_topsecret'], 400),
        ]);

        $credentials = $this->credentials();

        $user = User::factory()->create([
            'gocardless_secret_id' => 'secret_id',
            'gocardless_secret_key' => 'secret_key',
            'gocardless_token_secret_hash' => $credentials->fingerprint(),
            'gocardless_access_token' => null,
            'gocardless_refresh_token' => null,
            'gocardless_access_token_expires_at' => now()->subHour(),
            'gocardless_refresh_token_expires_at' => now()->subHour(),
        ]);

        $manager = new TokenManager($user, $credentials);

        try {
            $manager->getAccessToken();
            $this->fail('Expected token request failure');
        } catch (\Exception $e) {
            $this->assertStringNotContainsString('sk_live_topsecret', $e->getMessage());
            $this->assertSame('Failed to get new token set', $e->getMessage());
        }
    }

    /**
     * Likewise for a failed token/refresh request.
     */
    public function test_refresh_token_failure_excludes_response_body(): void
    {
        Http::fake([
            '*/token/refresh/' => Http::response(['error' => 'stale refresh_token sk_live_topsecret'], 400),
        ]);

        $credentials = $this->credentials();

        $user = User::factory()->create([
            'gocardless_secret_id' => 'secret_id',
            'gocardless_secret_key' => 'secret_key',
            'gocardless_token_secret_hash' => $credentials->fingerprint(),
            'gocardless_access_token' => null,
            'gocardless_refresh_token' => 'some_refresh_token',
            'gocardless_access_token_expires_at' => now()->subHour(),
            'gocardless_refresh_token_expires_at' => now()->addDay(),
        ]);

        $manager = new TokenManager($user, $credentials);

        try {
            $manager->getAccessToken();
            $this->fail('Expected token refresh failure');
        } catch (\Exception $e) {
            $this->assertStringNotContainsString('sk_live_topsecret', $e->getMessage());
            $this->assertSame('Failed to refresh access token', $e->getMessage());
        }
    }

    // ── credential/token binding ──────────────────────────────────────────

    /**
     * The stored tokens belong to a secret pair that is no longer in use — an override was added,
     * removed, or rotated. They are worthless against the new pair, so they must be dropped and a
     * fresh set minted rather than replayed.
     */
    public function test_credential_change_discards_stored_tokens_and_mints_a_fresh_set(): void
    {
        Http::fake([
            '*/token/new/' => Http::response([
                'access' => 'fresh_access',
                'refresh' => 'fresh_refresh',
                'access_expires' => 3600,
                'refresh_expires' => 86400,
            ], 200),
        ]);

        $oldCredentials = $this->credentials('old_secret_id', 'old_secret_key');
        $newCredentials = $this->credentials('new_secret_id', 'new_secret_key');

        $user = User::factory()->create([
            'gocardless_secret_id' => 'new_secret_id',
            'gocardless_secret_key' => 'new_secret_key',
            'gocardless_token_secret_hash' => $oldCredentials->fingerprint(),
            'gocardless_access_token' => 'token_from_old_pair',
            'gocardless_refresh_token' => 'refresh_from_old_pair',
            // Both tokens still look perfectly valid; only the credential change disqualifies them.
            'gocardless_access_token_expires_at' => now()->addHour(),
            'gocardless_refresh_token_expires_at' => now()->addDay(),
        ]);

        $manager = new TokenManager($user, $newCredentials);

        $this->assertSame('fresh_access', $manager->getAccessToken());

        // Never spent the old refresh grant, and asked for the new pair's tokens.
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/token/new/')
            && $request['secret_id'] === 'new_secret_id'
            && $request['secret_key'] === 'new_secret_key');
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/token/refresh/'));

        $user->refresh();
        $this->assertSame('fresh_access', $user->gocardless_access_token);
        $this->assertSame('fresh_refresh', $user->gocardless_refresh_token);
        $this->assertSame($newCredentials->fingerprint(), $user->gocardless_token_secret_hash);
    }

    /**
     * Rows written before the fingerprint column existed carry no provenance. Trusting them would
     * mean assuming the pair never changed, so they are re-minted once.
     */
    public function test_missing_fingerprint_is_treated_as_a_credential_change(): void
    {
        Http::fake([
            '*/token/new/' => Http::response([
                'access' => 'fresh_access',
                'refresh' => 'fresh_refresh',
                'access_expires' => 3600,
                'refresh_expires' => 86400,
            ], 200),
        ]);

        $credentials = $this->credentials();

        $user = User::factory()->create([
            'gocardless_secret_id' => 'secret_id',
            'gocardless_secret_key' => 'secret_key',
            'gocardless_token_secret_hash' => null,
            'gocardless_access_token' => 'legacy_token',
            'gocardless_refresh_token' => 'legacy_refresh',
            'gocardless_access_token_expires_at' => now()->addHour(),
            'gocardless_refresh_token_expires_at' => now()->addDay(),
        ]);

        $manager = new TokenManager($user, $credentials);

        $this->assertSame('fresh_access', $manager->getAccessToken());

        $user->refresh();
        $this->assertSame($credentials->fingerprint(), $user->gocardless_token_secret_hash);
    }

    /**
     * The counterpart: a matching fingerprint plus an unexpired token means no API call at all.
     */
    public function test_matching_fingerprint_keeps_a_valid_stored_token(): void
    {
        Http::fake();

        $credentials = $this->credentials();

        $user = User::factory()->create([
            'gocardless_secret_id' => 'secret_id',
            'gocardless_secret_key' => 'secret_key',
            'gocardless_token_secret_hash' => $credentials->fingerprint(),
            'gocardless_access_token' => 'still_good_token',
            'gocardless_refresh_token' => 'still_good_refresh',
            'gocardless_access_token_expires_at' => now()->addHour(),
            'gocardless_refresh_token_expires_at' => now()->addDay(),
        ]);

        $manager = new TokenManager($user, $credentials);

        $this->assertSame('still_good_token', $manager->getAccessToken());
        Http::assertNothingSent();
    }

    /**
     * A 401 arriving after a credential swap must not take the "another worker rotated it" branch:
     * that worker's token came from the superseded pair too.
     */
    public function test_credential_change_wins_over_the_already_rotated_shortcut(): void
    {
        Http::fake([
            '*/token/new/' => Http::response([
                'access' => 'fresh_access',
                'refresh' => 'fresh_refresh',
                'access_expires' => 3600,
                'refresh_expires' => 86400,
            ], 200),
        ]);

        $newCredentials = $this->credentials('new_secret_id', 'new_secret_key');

        $user = User::factory()->create([
            'gocardless_secret_id' => 'new_secret_id',
            'gocardless_secret_key' => 'new_secret_key',
            'gocardless_token_secret_hash' => $this->credentials('old_secret_id')->fingerprint(),
            'gocardless_access_token' => 'rotated_under_old_pair',
            'gocardless_refresh_token' => 'refresh_under_old_pair',
            'gocardless_access_token_expires_at' => now()->addHour(),
            'gocardless_refresh_token_expires_at' => now()->addDay(),
        ]);

        $manager = new TokenManager($user, $newCredentials);

        $this->assertSame('fresh_access', $manager->refreshAfterUnauthorized('the_token_that_failed'));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/token/new/'));
    }

    /**
     * Instance-level credentials leave the user's own secret columns empty; the pair must come
     * from the injected DTO, not from the row.
     */
    public function test_new_token_set_uses_injected_credentials_not_user_columns(): void
    {
        Http::fake([
            '*/token/new/' => Http::response([
                'access' => 'instance_access',
                'refresh' => 'instance_refresh',
                'access_expires' => 3600,
                'refresh_expires' => 86400,
            ], 200),
        ]);

        $credentials = $this->credentials('env_secret_id', 'env_secret_key', GoCardlessCredentialSource::INSTANCE);

        $user = User::factory()->create([
            'gocardless_secret_id' => null,
            'gocardless_secret_key' => null,
            'gocardless_token_secret_hash' => $credentials->fingerprint(),
            'gocardless_access_token' => null,
            'gocardless_refresh_token' => null,
        ]);

        $manager = new TokenManager($user, $credentials);

        $this->assertSame('instance_access', $manager->getAccessToken());

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/token/new/')
            && $request['secret_id'] === 'env_secret_id'
            && $request['secret_key'] === 'env_secret_key');
    }
}
