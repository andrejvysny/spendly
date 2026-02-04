<?php

namespace App\Providers;

use App\Models\Account;
use App\Models\Transaction;
use App\Repositories\AccountRepository;
use App\Repositories\TransactionRepository;
use App\Services\GoCardless\GocardlessMapper;
use App\Services\GoCardless\GoCardlessService;
use App\Services\GoCardless\TokenManager;
use App\Services\GoCardless\TransactionSyncService;
use App\Services\GoCardless\ClientFactory\GoCardlessClientFactoryInterface;
use Illuminate\Support\ServiceProvider;

class GoCardlessServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(AccountRepository::class, function ($app) {
            return new AccountRepository(new Account);
        });

        $this->app->singleton(TransactionRepository::class, function ($app) {
            return new TransactionRepository(new Transaction);
        });

        $this->app->singleton(GocardlessMapper::class, function ($app) {
            return new GocardlessMapper;
        });

        $this->app->singleton(TransactionSyncService::class, function ($app) {
            return new TransactionSyncService(
                $app->make(TransactionRepository::class),
                $app->make(GocardlessMapper::class)
            );
        });

        $this->app->singleton(GoCardlessClientFactoryInterface::class, function ($app) {
            if (config('services.gocardless.use_mock', false)) {
                return new \App\Services\GoCardless\ClientFactory\MockClientFactory;
            }

            return new \App\Services\GoCardless\ClientFactory\ProductionClientFactory;
        });

        $this->app->singleton(GoCardlessService::class, function ($app) {
            return new GoCardlessService(
                $app->make(AccountRepository::class),
                $app->make(TransactionSyncService::class),
                $app->make(GocardlessMapper::class),
                $app->make(GoCardlessClientFactoryInterface::class)
            );
        });

        // Register TokenManager as a factory since it requires a User instance
        $this->app->bind(TokenManager::class, function ($app, array $parameters) {
            // If a user is provided in the parameters, use it
            if (isset($parameters['user'])) {
                return new TokenManager($parameters['user']);
            }

            // Otherwise, try to get the authenticated user
            $user = auth()->user();
            if (! $user) {
                throw new \InvalidArgumentException('TokenManager requires a User instance. No authenticated user found.');
            }

            return new TokenManager($user);
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
