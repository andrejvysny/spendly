<?php

namespace App\Providers;

use App\Contracts\Repositories\AccountRepositoryInterface;
use App\Contracts\Repositories\GoCardlessSyncFailureRepositoryInterface;
use App\Contracts\Repositories\TransactionRepositoryInterface;
use App\Contracts\RuleEngine\RuleEngineInterface;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\AccountRepository;
use App\Repositories\TransactionRepository;
use App\Services\GoCardless\ClientFactory\GoCardlessClientFactoryInterface;
use App\Services\GoCardless\CredentialsResolver;
use App\Services\GoCardless\DTOs\GoCardlessCredentials;
use App\Services\GoCardless\FieldExtractors\FieldExtractorFactory;
use App\Services\GoCardless\GocardlessMapper;
use App\Services\GoCardless\GoCardlessService;
use App\Services\GoCardless\Mock\MockGoCardlessFixtureRepository;
use App\Services\GoCardless\TokenManager;
use App\Services\GoCardless\TransactionDataValidator;
use App\Services\GoCardless\TransactionDeduplicator;
use App\Services\GoCardless\TransactionSyncService;
use App\Services\MlSuggestionService;
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
            return new GocardlessMapper(
                $app->make(FieldExtractorFactory::class),
                $app->make(AccountRepositoryInterface::class)
            );
        });

        $this->app->singleton(TransactionDeduplicator::class, function ($app) {
            return new TransactionDeduplicator(
                $app->make(TransactionRepositoryInterface::class)
            );
        });

        $this->app->singleton(TransactionSyncService::class, function ($app) {
            return new TransactionSyncService(
                $app->make(TransactionRepositoryInterface::class),
                $app->make(GocardlessMapper::class),
                $app->make(TransferDetectionService::class),
                $app->make(MlSuggestionService::class),
                $app->make(TransactionDataValidator::class),
                $app->make(GoCardlessSyncFailureRepositoryInterface::class),
                $app->make(RuleEngineInterface::class),
                $app->make(TransactionDeduplicator::class)
            );
        });

        $this->app->singleton(MockGoCardlessFixtureRepository::class, function ($app) {
            return new MockGoCardlessFixtureRepository(
                config('services.gocardless.mock_data_path', base_path('sample_data/gocardless_bank_account_data'))
            );
        });

        $this->app->singleton(GoCardlessClientFactoryInterface::class, function ($app) {
            if (config('services.gocardless.use_mock', false)) {
                return new \App\Services\GoCardless\ClientFactory\MockClientFactory(
                    $app->make(MockGoCardlessFixtureRepository::class)
                );
            }

            return new \App\Services\GoCardless\ClientFactory\ProductionClientFactory(
                $app->make(CredentialsResolver::class)
            );
        });

        $this->app->singleton(GoCardlessService::class, function ($app) {
            return new GoCardlessService(
                $app->make(AccountRepository::class),
                $app->make(TransactionSyncService::class),
                $app->make(GocardlessMapper::class),
                $app->make(GoCardlessClientFactoryInterface::class)
            );
        });

        // Register TokenManager as a factory since it requires a User instance and the secret
        // pair that user's tokens are minted from.
        $this->app->bind(TokenManager::class, function ($app, array $parameters) {
            // Use the provided user, else fall back to the authenticated one.
            $user = $parameters['user'] ?? auth()->user();

            if (! $user instanceof User) {
                throw new \InvalidArgumentException('TokenManager requires a User instance. No authenticated user found.');
            }

            // Callers that already resolved the pair (the production client factory) pass it in so
            // it is not resolved twice; everyone else gets it resolved here.
            $credentials = $parameters['credentials'] ?? $app->make(CredentialsResolver::class)->resolve($user);

            if (! $credentials instanceof GoCardlessCredentials) {
                throw new \InvalidArgumentException('TokenManager requires a GoCardlessCredentials instance.');
            }

            return new TokenManager($user, $credentials);
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
