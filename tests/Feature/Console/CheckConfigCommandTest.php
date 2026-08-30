<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckConfigCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_always_exits_zero_even_with_warnings(): void
    {
        config(['app.env' => 'production', 'app.url' => 'http://localhost', 'app.debug' => true]);

        $this->artisan('spendly:check-config')->assertExitCode(0);
    }

    public function test_production_localhost_app_url_warns(): void
    {
        config(['app.env' => 'production', 'app.url' => 'http://localhost']);

        $this->artisan('spendly:check-config')
            ->expectsOutputToContain('Bank redirect URLs are built from APP_URL');
    }

    public function test_production_https_domain_passes(): void
    {
        config(['app.env' => 'production', 'app.url' => 'https://spendly.example.com']);

        $this->artisan('spendly:check-config')
            ->expectsOutputToContain('APP_URL is a valid https production URL');
    }

    public function test_production_ip_host_warns(): void
    {
        config(['app.env' => 'production', 'app.url' => 'https://192.168.1.10']);

        $this->artisan('spendly:check-config')
            ->expectsOutputToContain('Bank redirect URLs are built from APP_URL');
    }

    public function test_sync_queue_warns_in_production(): void
    {
        config(['app.env' => 'production', 'queue.default' => 'sync']);

        $this->artisan('spendly:check-config')
            ->expectsOutputToContain('Queued bank syncs will run inline');
    }

    public function test_mock_mode_skips_credential_check(): void
    {
        config(['services.gocardless.use_mock' => true]);

        $this->artisan('spendly:check-config')
            ->expectsOutputToContain('mock mode is enabled');
    }

    public function test_live_mode_with_no_credentials_anywhere_warns(): void
    {
        config([
            'services.gocardless.use_mock' => false,
            'services.gocardless.secret_id' => null,
            'services.gocardless.secret_key' => null,
        ]);

        $this->artisan('spendly:check-config')
            ->expectsOutputToContain('No GoCardless credentials configured anywhere');
    }

    public function test_live_mode_with_instance_credentials_passes(): void
    {
        config([
            'services.gocardless.use_mock' => false,
            'services.gocardless.secret_id' => 'instance-id',
            'services.gocardless.secret_key' => 'instance-key',
        ]);

        $this->artisan('spendly:check-config')
            ->expectsOutputToContain('Instance GoCardless credentials are configured');
    }

    public function test_live_mode_with_user_credentials_passes(): void
    {
        config([
            'services.gocardless.use_mock' => false,
            'services.gocardless.secret_id' => null,
            'services.gocardless.secret_key' => null,
        ]);
        User::factory()->create([
            'gocardless_secret_id' => 'user-id',
            'gocardless_secret_key' => 'user-key',
        ]);

        $this->artisan('spendly:check-config')
            ->expectsOutputToContain('At least one user has personal GoCardless credentials');
    }

    public function test_json_output_shape(): void
    {
        config(['app.env' => 'production', 'app.url' => 'http://localhost']);

        $this->artisan('spendly:check-config --json')
            ->expectsOutputToContain('"checks":[{"id":"app_url","level":"warn"')
            ->assertExitCode(0);
    }

    public function test_about_command_includes_spendly_section(): void
    {
        $this->artisan('about')->expectsOutputToContain('Spendly');
    }
}
