<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Inertia\Inertia;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Disable CSRF protection for tests
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

        // Force the log channel to null to suppress all logs during tests
        config(['logging.default' => 'null']);

        // Reset the log manager to use the new configuration
        $this->app->make('log')->setDefaultDriver('null');
    }

    /**
     * Make an Inertia request with the proper headers.
     * This automatically sets the X-Inertia and X-Inertia-Version headers.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    protected function inertia(string $method, string $uri, array $data = [])
    {
        // Get the current Inertia version
        $version = $this->getInertiaVersion();

        return $this->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $version,
        ])->json($method, $uri, $data);
    }

    /**
     * Get the current Inertia version for testing.
     * This calculates the version the same way the middleware does.
     */
    protected function getInertiaVersion(): ?string
    {
        if (config('app.asset_url')) {
            return hash('xxh128', config('app.asset_url'));
        }

        if (file_exists($manifest = public_path('mix-manifest.json'))) {
            return hash_file('xxh128', $manifest);
        }

        if (file_exists($manifest = public_path('build/manifest.json'))) {
            return hash_file('xxh128', $manifest);
        }

        return null;
    }
}
