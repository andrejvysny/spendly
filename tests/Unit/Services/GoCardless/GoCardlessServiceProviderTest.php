<?php

namespace Tests\Unit\Services\GoCardless;

use App\Enums\GoCardlessCredentialSource;
use App\Exceptions\MissingGoCardlessCredentialsException;
use App\Models\User;
use App\Services\GoCardless\ClientFactory\GoCardlessClientFactoryInterface;
use App\Services\GoCardless\ClientFactory\MockClientFactory;
use App\Services\GoCardless\ClientFactory\ProductionClientFactory;
use App\Services\GoCardless\DTOs\GoCardlessCredentials;
use App\Services\GoCardless\GoCardlessService;
use App\Services\GoCardless\TokenManager;
use Tests\TestCase;

class GoCardlessServiceProviderTest extends TestCase
{
    public function test_binds_mock_factory_when_config_enabled(): void
    {
        config(['services.gocardless.use_mock' => true]);
        $this->app->forgetInstance(GoCardlessClientFactoryInterface::class);
        $this->app->offsetUnset(GoCardlessClientFactoryInterface::class);

        // Re-register the provider so it picks up new config
        (new \App\Providers\GoCardlessServiceProvider($this->app))->register();

        $factory = $this->app->make(GoCardlessClientFactoryInterface::class);

        $this->assertInstanceOf(MockClientFactory::class, $factory);
    }

    public function test_binds_production_factory_when_config_disabled(): void
    {
        config(['services.gocardless.use_mock' => false]);

        $factory = $this->app->make(GoCardlessClientFactoryInterface::class);

        $this->assertInstanceOf(ProductionClientFactory::class, $factory);
    }

    public function test_binds_production_factory_by_default(): void
    {
        // Ensure config is unset or default
        // In this test environment, it might default to what's in .env or config files.
        // We can explicitly unset it to test default behavior if null, but the config helper might return null.
        // The service provider does: config('services.gocardless.use_mock', false)
        // so if we clear it, it should be false.

        config()->offsetUnset('services.gocardless.use_mock');

        $factory = $this->app->make(GoCardlessClientFactoryInterface::class);

        $this->assertInstanceOf(ProductionClientFactory::class, $factory);
    }

    public function test_service_is_resolved_correctly(): void
    {
        $service = $this->app->make(GoCardlessService::class);
        $this->assertInstanceOf(GoCardlessService::class, $service);
    }

    // ── TokenManager binding ──────────────────────────────────────────────

    public function test_token_manager_resolves_credentials_for_the_given_user(): void
    {
        $user = User::factory()->create([
            'gocardless_secret_id' => 'user-id',
            'gocardless_secret_key' => 'user-key',
        ]);

        $manager = $this->app->make(TokenManager::class, ['user' => $user]);

        $this->assertInstanceOf(TokenManager::class, $manager);
    }

    public function test_token_manager_accepts_pre_resolved_credentials(): void
    {
        $user = User::factory()->create([
            'gocardless_secret_id' => null,
            'gocardless_secret_key' => null,
        ]);

        $manager = $this->app->make(TokenManager::class, [
            'user' => $user,
            'credentials' => new GoCardlessCredentials('id', 'key', GoCardlessCredentialSource::INSTANCE),
        ]);

        $this->assertInstanceOf(TokenManager::class, $manager);
    }

    public function test_token_manager_throws_when_no_credentials_can_be_resolved(): void
    {
        config(['services.gocardless.secret_id' => null, 'services.gocardless.secret_key' => null]);

        $user = User::factory()->create([
            'gocardless_secret_id' => null,
            'gocardless_secret_key' => null,
        ]);

        $this->expectException(MissingGoCardlessCredentialsException::class);

        $this->app->make(TokenManager::class, ['user' => $user]);
    }

    public function test_token_manager_requires_a_user(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('TokenManager requires a User instance');

        $this->app->make(TokenManager::class);
    }

    // ── ProductionClientFactory ───────────────────────────────────────────

    public function test_production_factory_throws_when_nothing_is_configured(): void
    {
        config([
            'services.gocardless.use_mock' => false,
            'services.gocardless.secret_id' => null,
            'services.gocardless.secret_key' => null,
        ]);

        $user = User::factory()->create([
            'gocardless_secret_id' => null,
            'gocardless_secret_key' => null,
        ]);

        $factory = $this->app->make(GoCardlessClientFactoryInterface::class);

        $this->expectException(MissingGoCardlessCredentialsException::class);
        $this->expectExceptionMessage(MissingGoCardlessCredentialsException::DEFAULT_MESSAGE);

        $factory->make($user);
    }

    /**
     * GoCardlessService::initializeClient() re-throws InvalidArgumentException untouched and wraps
     * everything else in a generic RuntimeException, so the actionable message only survives while
     * this hierarchy holds.
     */
    public function test_missing_credentials_exception_stays_an_invalid_argument_exception(): void
    {
        $this->assertInstanceOf(\InvalidArgumentException::class, new MissingGoCardlessCredentialsException);
    }
}
