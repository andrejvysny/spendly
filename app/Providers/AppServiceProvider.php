<?php

namespace App\Providers;

use App\Contracts\Repositories\AccountRepositoryInterface;
use App\Services\AccountBalanceService;
use App\Services\Csv\CsvProcessor;
use App\Services\DuplicateTransactionService;
use App\Services\GoCardless\BankDataClientInterface;
use App\Services\TransactionImport\ImportFailurePersister;
use App\Services\TransactionImport\ImportMappingService;
use App\Services\TransactionImport\TransactionDataParser;
use App\Services\TransactionImport\TransactionImportService;
use App\Services\TransactionImport\TransactionPersister;
use App\Services\TransactionImport\TransactionRowProcessor;
use App\Services\TransactionImport\TransactionValidator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->register(AuthServiceProvider::class);

        // Configure DuplicateTransactionService with field mappings
        $this->app->singleton(DuplicateTransactionService::class, function ($app) {
            return new DuplicateTransactionService([
                'description' => ['partner', 'merchant', 'details', 'note'],
                'booked_date' => ['date', 'value_date', 'transaction_date'],
                'reference_id' => ['transaction_id', 'reference', 'id'],
            ]);
        });

        // Register CSV processor
        $this->app->singleton(CsvProcessor::class);

        // Register import mapping service
        $this->app->singleton(ImportMappingService::class);

        // Register transaction import services
        $this->app->singleton(TransactionDataParser::class);
        $this->app->singleton(TransactionValidator::class);
        $this->app->singleton(TransactionPersister::class);
        $this->app->singleton(ImportFailurePersister::class);

        $this->app->singleton(TransactionRowProcessor::class, function ($app) {
            return new TransactionRowProcessor(
                $app->make(TransactionDataParser::class),
                $app->make(TransactionValidator::class),
                $app->make(DuplicateTransactionService::class)
            );
        });

        // Register AccountBalanceService (before TransactionImportService since it depends on it)
        $this->app->singleton(AccountBalanceService::class, function ($app) {
            // BankDataClient is optional (may not be configured for GoCardless)
            $bankDataClient = null;

            try {
                if ($app->bound(BankDataClientInterface::class)) {
                    $bankDataClient = $app->make(BankDataClientInterface::class);
                }
            } catch (\Exception $e) {
                // GoCardless not configured, continue without it
            }

            return new AccountBalanceService(
                $app->make(AccountRepositoryInterface::class),
                $bankDataClient
            );
        });

        $this->app->singleton(TransactionImportService::class, function ($app) {
            return new TransactionImportService(
                $app->make(CsvProcessor::class),
                $app->make(TransactionRowProcessor::class),
                $app->make(TransactionPersister::class),
                $app->make(ImportFailurePersister::class),
                $app->make(AccountBalanceService::class)
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
