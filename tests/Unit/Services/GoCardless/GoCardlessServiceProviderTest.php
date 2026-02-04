<?php

namespace Tests\Unit\Services\GoCardless;

use App\Providers\GoCardlessServiceProvider;
use App\Services\GoCardless\ClientFactory\GoCardlessClientFactoryInterface;
use App\Services\GoCardless\ClientFactory\MockClientFactory;
use App\Services\GoCardless\ClientFactory\ProductionClientFactory;
use App\Services\GoCardless\GoCardlessService;
use Illuminate\Container\Container;
use Illuminate\Config\Repository;
use Tests\TestCase;

class GoCardlessServiceProviderTest extends TestCase
{
    public function test_binds_mock_factory_when_config_enabled(): void
    {
        config(['services.gocardless.use_mock' => true]);

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
}
