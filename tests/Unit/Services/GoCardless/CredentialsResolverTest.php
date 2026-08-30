<?php

declare(strict_types=1);

namespace Tests\Unit\Services\GoCardless;

use App\Enums\GoCardlessCredentialSource;
use App\Exceptions\MissingGoCardlessCredentialsException;
use App\Models\User;
use App\Services\GoCardless\CredentialsResolver;
use Tests\TestCase;

class CredentialsResolverTest extends TestCase
{
    private CredentialsResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new CredentialsResolver;
    }

    private function setInstanceCredentials(?string $secretId, ?string $secretKey): void
    {
        config([
            'services.gocardless.secret_id' => $secretId,
            'services.gocardless.secret_key' => $secretKey,
        ]);
    }

    // ── resolve ───────────────────────────────────────────────────────────

    public function test_user_override_wins_over_instance_credentials(): void
    {
        $this->setInstanceCredentials('instance-id', 'instance-key');

        $user = User::factory()->create([
            'gocardless_secret_id' => 'user-id',
            'gocardless_secret_key' => 'user-key',
        ]);

        $credentials = $this->resolver->resolve($user);

        $this->assertSame('user-id', $credentials->secretId);
        $this->assertSame('user-key', $credentials->secretKey);
        $this->assertSame(GoCardlessCredentialSource::USER, $credentials->source);
    }

    public function test_falls_back_to_instance_credentials_when_user_has_none(): void
    {
        $this->setInstanceCredentials('instance-id', 'instance-key');

        $user = User::factory()->create([
            'gocardless_secret_id' => null,
            'gocardless_secret_key' => null,
        ]);

        $credentials = $this->resolver->resolve($user);

        $this->assertSame('instance-id', $credentials->secretId);
        $this->assertSame('instance-key', $credentials->secretKey);
        $this->assertSame(GoCardlessCredentialSource::INSTANCE, $credentials->source);
    }

    /**
     * A user with only half a pair must never be quietly switched onto the administrator's
     * credentials — they would sync somebody else's GoCardless quota with no way to tell.
     */
    public function test_half_configured_override_throws_instead_of_falling_back(): void
    {
        $this->setInstanceCredentials('instance-id', 'instance-key');

        $user = User::factory()->create([
            'gocardless_secret_id' => 'user-id',
            'gocardless_secret_key' => null,
        ]);

        $this->expectException(MissingGoCardlessCredentialsException::class);
        $this->expectExceptionMessage(MissingGoCardlessCredentialsException::PARTIAL_MESSAGE);

        $this->resolver->resolve($user);
    }

    public function test_half_configured_override_throws_when_only_key_is_set(): void
    {
        $this->setInstanceCredentials(null, null);

        $user = User::factory()->create([
            'gocardless_secret_id' => null,
            'gocardless_secret_key' => 'user-key',
        ]);

        $this->expectException(MissingGoCardlessCredentialsException::class);
        $this->expectExceptionMessage(MissingGoCardlessCredentialsException::PARTIAL_MESSAGE);

        $this->resolver->resolve($user);
    }

    public function test_throws_when_neither_source_is_configured(): void
    {
        $this->setInstanceCredentials(null, null);

        $user = User::factory()->create([
            'gocardless_secret_id' => null,
            'gocardless_secret_key' => null,
        ]);

        $this->expectException(MissingGoCardlessCredentialsException::class);
        $this->expectExceptionMessage(MissingGoCardlessCredentialsException::DEFAULT_MESSAGE);

        $this->resolver->resolve($user);
    }

    public function test_half_configured_instance_credentials_do_not_count(): void
    {
        $this->setInstanceCredentials('instance-id', null);

        $user = User::factory()->create([
            'gocardless_secret_id' => null,
            'gocardless_secret_key' => null,
        ]);

        $this->expectException(MissingGoCardlessCredentialsException::class);

        $this->resolver->resolve($user);
    }

    public function test_blank_instance_credentials_do_not_count(): void
    {
        $this->setInstanceCredentials('   ', '');

        $user = User::factory()->create([
            'gocardless_secret_id' => null,
            'gocardless_secret_key' => null,
        ]);

        $this->assertFalse($this->resolver->hasInstanceCredentials());
        $this->assertNull($this->resolver->sourceFor($user));
    }

    // ── tryResolve ────────────────────────────────────────────────────────

    public function test_try_resolve_returns_credentials_when_available(): void
    {
        $this->setInstanceCredentials('instance-id', 'instance-key');

        $user = User::factory()->create([
            'gocardless_secret_id' => null,
            'gocardless_secret_key' => null,
        ]);

        $this->assertSame(GoCardlessCredentialSource::INSTANCE, $this->resolver->tryResolve($user)?->source);
    }

    public function test_try_resolve_returns_null_for_half_configured_override(): void
    {
        $this->setInstanceCredentials('instance-id', 'instance-key');

        $user = User::factory()->create([
            'gocardless_secret_id' => 'user-id',
            'gocardless_secret_key' => null,
        ]);

        $this->assertNull($this->resolver->tryResolve($user));
    }

    public function test_try_resolve_returns_null_when_nothing_is_configured(): void
    {
        $this->setInstanceCredentials(null, null);

        $user = User::factory()->create([
            'gocardless_secret_id' => null,
            'gocardless_secret_key' => null,
        ]);

        $this->assertNull($this->resolver->tryResolve($user));
    }

    // ── introspection helpers ─────────────────────────────────────────────

    public function test_has_instance_credentials_requires_both_halves(): void
    {
        $this->setInstanceCredentials('instance-id', 'instance-key');
        $this->assertTrue($this->resolver->hasInstanceCredentials());

        $this->setInstanceCredentials('instance-id', null);
        $this->assertFalse($this->resolver->hasInstanceCredentials());

        $this->setInstanceCredentials(null, null);
        $this->assertFalse($this->resolver->hasInstanceCredentials());
    }

    public function test_has_user_override_requires_both_halves(): void
    {
        $complete = User::factory()->create([
            'gocardless_secret_id' => 'user-id',
            'gocardless_secret_key' => 'user-key',
        ]);
        $partial = User::factory()->create([
            'gocardless_secret_id' => 'user-id',
            'gocardless_secret_key' => null,
        ]);

        $this->assertTrue($this->resolver->hasUserOverride($complete));
        $this->assertFalse($this->resolver->hasUserOverride($partial));
    }

    public function test_source_for_reports_each_state(): void
    {
        $this->setInstanceCredentials('instance-id', 'instance-key');

        $withOverride = User::factory()->create([
            'gocardless_secret_id' => 'user-id',
            'gocardless_secret_key' => 'user-key',
        ]);
        $withoutOverride = User::factory()->create([
            'gocardless_secret_id' => null,
            'gocardless_secret_key' => null,
        ]);

        $this->assertSame(GoCardlessCredentialSource::USER, $this->resolver->sourceFor($withOverride));
        $this->assertSame(GoCardlessCredentialSource::INSTANCE, $this->resolver->sourceFor($withoutOverride));

        $this->setInstanceCredentials(null, null);
        $this->assertNull($this->resolver->sourceFor($withoutOverride));
    }

    // ── fingerprint ───────────────────────────────────────────────────────

    public function test_fingerprint_is_stable_and_changes_on_rotation(): void
    {
        $this->setInstanceCredentials(null, null);

        $user = User::factory()->create([
            'gocardless_secret_id' => 'user-id',
            'gocardless_secret_key' => 'user-key',
        ]);

        $first = $this->resolver->resolve($user)->fingerprint();
        $second = $this->resolver->resolve($user)->fingerprint();

        $this->assertSame($first, $second);
        $this->assertSame(hash('sha256', 'user-id'), $first);

        $user->update(['gocardless_secret_id' => 'rotated-id']);

        $this->assertNotSame($first, $this->resolver->resolve($user)->fingerprint());
    }

    /**
     * Switching a user from their own pair to the instance pair must change the fingerprint, or
     * the tokens minted under the old pair would be replayed against the new one.
     */
    public function test_fingerprint_differs_between_sources(): void
    {
        $this->setInstanceCredentials('instance-id', 'instance-key');

        $user = User::factory()->create([
            'gocardless_secret_id' => 'user-id',
            'gocardless_secret_key' => 'user-key',
        ]);

        $userFingerprint = $this->resolver->resolve($user)->fingerprint();

        $user->update(['gocardless_secret_id' => null, 'gocardless_secret_key' => null]);

        $instanceCredentials = $this->resolver->resolve($user);

        $this->assertSame(GoCardlessCredentialSource::INSTANCE, $instanceCredentials->source);
        $this->assertNotSame($userFingerprint, $instanceCredentials->fingerprint());
    }

    public function test_surrounding_whitespace_is_ignored(): void
    {
        $this->setInstanceCredentials(null, null);

        $user = User::factory()->create([
            'gocardless_secret_id' => "  user-id\n",
            'gocardless_secret_key' => ' user-key ',
        ]);

        $credentials = $this->resolver->resolve($user);

        $this->assertSame('user-id', $credentials->secretId);
        $this->assertSame('user-key', $credentials->secretKey);
    }
}
