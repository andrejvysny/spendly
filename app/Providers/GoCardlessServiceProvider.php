<?php

namespace App\Providers;

use App\Repositories\AccountRepository;
use App\Repositories\TransactionRepository;
use App\Services\GocardlessMapper;
use App\Services\GoCardlessService;
use App\Services\TokenManager;
use App\Services\TransactionSyncService;
use Illuminate\Support\ServiceProvider;

class GoCardlessServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(AccountRepository::class, function ($app) {
            return new AccountRepository;
        });

        $this->app->singleton(TransactionRepository::class, function ($app) {
            return new TransactionRepository;
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

        $this->app->singleton(GoCardlessService::class, function ($app) {
            return new GoCardlessService(
                $app->make(AccountRepository::class),
                $app->make(TransactionSyncService::class),
                $app->make(GocardlessMapper::class)
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
