<?php

namespace App\Providers;

use App\Contracts\Repositories\GoCardlessSyncFailureRepositoryInterface;
use App\Contracts\Repositories\TransactionRepositoryInterface;
use App\Models\Account;
use App\Models\Transaction;
use App\Repositories\AccountRepository;
use App\Repositories\TransactionRepository;
use App\Services\GoCardless\FieldExtractors\FieldExtractorFactory;
use App\Services\GoCardless\GocardlessMapper;
use App\Services\GoCardless\GoCardlessService;
use App\Services\GoCardless\TokenManager;
use App\Services\GoCardless\TransactionDataValidator;
use App\Services\GoCardless\TransactionSyncService;
use App\Services\GoCardless\ClientFactory\GoCardlessClientFactoryInterface;
use App\Services\GoCardless\Mock\MockGoCardlessFixtureRepository;
use App\Services\TransferDetectionService;
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

        $this->app->singleton(FieldExtractorFactory::class, function ($app) {
            return new FieldExtractorFactory;
        });

        $this->app->singleton(GocardlessMapper::class, function ($app) {
            return new GocardlessMapper($app->make(FieldExtractorFactory::class));
        });

        $this->app->singleton(TransactionSyncService::class, function ($app) {
            return new TransactionSyncService(
                $app->make(TransactionRepositoryInterface::class),
                $app->make(GocardlessMapper::class),
                $app->make(TransferDetectionService::class),
                $app->make(TransactionDataValidator::class),
                $app->make(GoCardlessSyncFailureRepositoryInterface::class)
            );
        });

        $this->app->singleton(MockGoCardlessFixtureRepository::class, function ($app) {
            return new MockGoCardlessFixtureRepository(
                config('services.gocardless.mock_data_path', base_path('gocardless_bank_account_data'))
            );
        });

        $this->app->singleton(GoCardlessClientFactoryInterface::class, function ($app) {
            if (config('services.gocardless.use_mock', false)) {
                return new \App\Services\GoCardless\ClientFactory\MockClientFactory(
                    $app->make(MockGoCardlessFixtureRepository::class)
                );
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
