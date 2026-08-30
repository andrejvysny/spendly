<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\GoCardless\CredentialsResolver;
use App\Support\AppUrlDiagnostics;
use Illuminate\Console\Command;

/**
 * Boot-time diagnostics for self-hosters: catches the handful of misconfigurations that don't
 * blow up at startup but silently break bank sync or leak debug info once traffic arrives.
 *
 * Always exits 0 — this runs unattended from init-app on every container start, and a non-zero
 * exit there would abort boot over a warning. Findings are for a human to read (or scrape via
 * --json), never a deploy gate.
 */
class CheckConfigCommand extends Command
{
    protected $signature = 'spendly:check-config {--json : Output findings as JSON}';

    protected $description = 'Run boot-time configuration diagnostics and report warnings (never fails).';

    public function __construct(
        private readonly CredentialsResolver $credentialsResolver,
        private readonly AppUrlDiagnostics $appUrlDiagnostics,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $checks = [
            $this->checkAppUrl(),
            $this->checkGoCardlessCredentials(),
            $this->checkQueueConnection(),
            $this->checkDebugMode(),
            $this->checkAppKey(),
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode(['checks' => $checks], JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->renderHuman($checks);

        return self::SUCCESS;
    }

    /**
     * @param  list<array{id: string, level: string, message: string}>  $checks
     */
    private function renderHuman(array $checks): void
    {
        foreach ($checks as $check) {
            match ($check['level']) {
                'warn' => $this->warn("[warn] {$check['message']}"),
                'info' => $this->line("[info] {$check['message']}"),
                default => $this->info("[ok] {$check['message']}"),
            };
        }
    }

    /**
     * @return array{id: string, level: string, message: string}
     */
    private function checkAppUrl(): array
    {
        if (config('app.env') !== 'production') {
            return $this->note('app_url', 'APP_URL check skipped outside production.');
        }

        $warning = $this->appUrlDiagnostics->warning();
        if ($warning !== null) {
            return $this->finding('app_url', $warning);
        }

        return $this->pass('app_url', 'APP_URL is a valid https production URL.');
    }

    /**
     * @return array{id: string, level: string, message: string}
     */
    private function checkGoCardlessCredentials(): array
    {
        if (config('services.gocardless.use_mock')) {
            return $this->note('gocardless_credentials', 'GoCardless mock mode is enabled; credential check skipped.');
        }

        if ($this->credentialsResolver->hasInstanceCredentials()) {
            return $this->pass('gocardless_credentials', 'Instance GoCardless credentials are configured.');
        }

        $anyUserHasCredentials = User::query()->whereNotNull('gocardless_secret_id')->exists();
        if ($anyUserHasCredentials) {
            return $this->pass('gocardless_credentials', 'At least one user has personal GoCardless credentials configured.');
        }

        return $this->finding('gocardless_credentials', 'No GoCardless credentials configured anywhere.');
    }

    /**
     * @return array{id: string, level: string, message: string}
     */
    private function checkQueueConnection(): array
    {
        if (config('app.env') !== 'production') {
            return $this->note('queue_connection', 'Queue connection check skipped outside production.');
        }

        if (config('queue.default') === 'sync') {
            return $this->finding('queue_connection', 'Queued bank syncs will run inline in requests.');
        }

        return $this->pass('queue_connection', 'Queue connection is not sync.');
    }

    /**
     * @return array{id: string, level: string, message: string}
     */
    private function checkDebugMode(): array
    {
        if (config('app.env') !== 'production') {
            return $this->note('app_debug', 'Debug mode check skipped outside production.');
        }

        if (config('app.debug') === true) {
            return $this->finding('app_debug', 'APP_DEBUG is enabled in production; error details may leak to users.');
        }

        return $this->pass('app_debug', 'Debug mode is disabled.');
    }

    /**
     * @return array{id: string, level: string, message: string}
     */
    private function checkAppKey(): array
    {
        $key = config('app.key');
        if (! is_string($key) || trim($key) === '') {
            return $this->finding('app_key', 'APP_KEY is not set.');
        }

        return $this->pass('app_key', 'APP_KEY is set.');
    }

    /**
     * @return array{id: string, level: string, message: string}
     */
    private function pass(string $id, string $message): array
    {
        return ['id' => $id, 'level' => 'ok', 'message' => $message];
    }

    /**
     * @return array{id: string, level: string, message: string}
     */
    private function finding(string $id, string $message): array
    {
        return ['id' => $id, 'level' => 'warn', 'message' => $message];
    }

    /**
     * @return array{id: string, level: string, message: string}
     */
    private function note(string $id, string $message): array
    {
        return ['id' => $id, 'level' => 'info', 'message' => $message];
    }
}
