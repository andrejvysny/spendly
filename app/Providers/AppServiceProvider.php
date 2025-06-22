<?php

namespace App\Providers;

use App\Services\Csv\CsvProcessor;
use App\Services\DuplicateTransactionService;
use App\Services\ImportMappingService;
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
        $this->app->singleton(TransactionPersister::class, function ($app) {
            return new TransactionPersister(
                $app->make(DuplicateTransactionService::class)
            );
        });

        $this->app->singleton(TransactionRowProcessor::class, function ($app) {
            return new TransactionRowProcessor(
                $app->make(TransactionDataParser::class),
                $app->make(TransactionValidator::class),
                $app->make(DuplicateTransactionService::class)
            );
        });

        $this->app->singleton(TransactionImportService::class, function ($app) {
            return new TransactionImportService(
                $app->make(CsvProcessor::class),
                $app->make(TransactionRowProcessor::class),
                $app->make(TransactionPersister::class)
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
