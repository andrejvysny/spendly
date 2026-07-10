<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Services\ExchangeRateService;
use Carbon\Carbon;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Populate the exchange_rates table on first migrate so the app has at least
 * one day of rates before the daily scheduler runs. Skips testing env, missing
 * table, and any pre-existing data. Network failures are logged but never
 * propagate — migration must succeed regardless of Frankfurter availability.
 */
class WarmupExchangeRatesOnMigrate
{
    public function __construct(private readonly ExchangeRateService $service) {}

    public function handle(MigrationsEnded $event): void
    {
        if (app()->environment('testing') || app()->runningUnitTests()) {
            return;
        }

        // Skip when sqlite is in-memory (testing or ephemeral containers).
        /** @var mixed $defaultRaw */
        $defaultRaw = config('database.default');
        $defaultConn = is_string($defaultRaw) ? $defaultRaw : 'sqlite';
        $dbName = config('database.connections.'.$defaultConn.'.database');
        if ($dbName === ':memory:') {
            return;
        }

        // Belt + braces: any of these env signals indicate a test run.
        $envSignal = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: null;
        if ($envSignal === 'testing') {
            return;
        }

        try {
            if (! Schema::hasTable('exchange_rates')) {
                return;
            }

            if (\App\Models\ExchangeRate::query()->exists()) {
                return;
            }

            $count = $this->service->fetchRatesForDate(Carbon::today());

            if ($count > 0) {
                Log::info('Exchange rates warmup populated initial rates after migration', [
                    'count' => $count,
                ]);
            }
        } catch (Throwable $e) {
            // Never let warmup break migrations.
            Log::warning('Exchange rates warmup failed; daily scheduler will retry', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
