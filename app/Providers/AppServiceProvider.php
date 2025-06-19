<?php

namespace App\Providers;

use App\Services\DuplicateTransactionService;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
