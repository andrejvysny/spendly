<?php

use App\Http\Controllers\Accounts\AccountController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Import\ImportController;
use App\Http\Controllers\Import\ImportFailureController;
use App\Http\Controllers\Import\ImportMappingsController;
use App\Http\Controllers\Import\ImportWizardController;
use App\Http\Controllers\MerchantController;
use App\Http\Controllers\RuleEngine\RuleController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\Transactions\TransactionController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (! Auth::check()) {
        return redirect()->route('login');
    }

    return redirect()->route('dashboard');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics');

    Route::get('/transactions', [TransactionController::class, 'index'])->name('transactions.index');
    Route::post('/transactions-store', [TransactionController::class, 'store'])->name('transactions.store');
    Route::post('/transactions/bulk-update', [TransactionController::class, 'bulkUpdate'])->name('transactions.bulk-update');
    Route::post('/transactions/bulk-note-update', [TransactionController::class, 'bulkNoteUpdate'])->name('transactions.bulk-note-update');
    Route::put('/transactions/{transaction}', [TransactionController::class, 'updateTransaction'])->name('transactions.update');

    Route::get('/accounts', [AccountController::class, 'index'])->name('accounts.index');
    Route::post('/accounts', [AccountController::class, 'store'])->name('accounts.store');

    Route::get('/accounts/{account}', [AccountController::class, 'show'])->name('accounts.show');
    Route::delete('/accounts/{id}', [AccountController::class, 'destroy'])->name('accounts.destroy');
    Route::put('/accounts/{id}/sync-options', [AccountController::class, 'updateSyncOptions'])->name('accounts.sync-options.update');

    // Rule Engine (New) - Web page route
    Route::get('/rules', [RuleController::class, 'indexPage'])->name('rules.index');

    // Category routes
    Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
    Route::put('/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
    Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

    // Merchant routes
    Route::get('/merchants', [MerchantController::class, 'index'])->name('merchants.index');
    Route::post('/merchants', [MerchantController::class, 'store'])->name('merchants.store');
    Route::put('/merchants/{merchant}', [MerchantController::class, 'update'])->name('merchants.update');
    Route::delete('/merchants/{merchant}', [MerchantController::class, 'destroy'])->name('merchants.destroy');

    // Tag routes
    Route::get('/tags', [TagController::class, 'index'])->name('tags.index');
    Route::post('/tags', [TagController::class, 'store'])->name('tags.store');
    Route::put('/tags/{tag}', [TagController::class, 'update'])->name('tags.update');
    Route::delete('/tags/{tag}', [TagController::class, 'destroy'])->name('tags.destroy');

    // Import routes
    Route::group(['prefix' => 'imports', 'as' => 'imports.'], function () {
        Route::get('/', [ImportController::class, 'index'])->name('index');
        Route::get('/{import}/failures', [ImportFailureController::class, 'failuresPage'])->name('failures');
        Route::post('/revert/{import}', [ImportController::class, 'revertImport'])->name('revert');
        Route::delete('/{import}', [ImportController::class, 'deleteImport'])->name('delete');

        Route::group(['prefix' => '/wizard', 'as' => 'wizard.'], function () {
            Route::post('/upload', [ImportWizardController::class, 'upload'])->name('upload');
            Route::post('/{import}/configure', [ImportWizardController::class, 'configure'])->name('configure');
            Route::post('/{account}/{import}/process', [ImportWizardController::class, 'process'])->name('process');
            Route::get('/categories', [ImportWizardController::class, 'getCategories'])->name('categories');
            Route::get('/{import}/rows', [ImportWizardController::class, 'getRows'])->name('rows');
            Route::patch('/{import}/rows/{row}', [ImportWizardController::class, 'updateRow'])->name('rows.update');
            Route::get('/{import}/columns/{column}/stats', [ImportWizardController::class, 'getColumnStats'])->name('columns.stats');
        });

        Route::group(['prefix' => '/mappings', 'as' => 'mappings.'], function () {
            Route::get('/', [ImportMappingsController::class, 'index'])->name('get');
            Route::post('/', [ImportMappingsController::class, 'store'])->name('save');
            Route::post('/apply', [ImportMappingsController::class, 'applyMapping'])->name('apply');
            Route::post('/auto-detect', [ImportMappingsController::class, 'autoDetect'])->name('auto-detect');
            Route::post('/compatible', [ImportMappingsController::class, 'getCompatible'])->name('compatible');
            Route::put('/{mapping}', [ImportMappingsController::class, 'updateLastUsed'])->name('usage');
            Route::delete('/{mapping}', [ImportMappingsController::class, 'delete'])->name('delete');
        });
    });
});

// Health check endpoint for container health monitoring
Route::get('/health', function () {
    return response('OK', 200);
});

// Add this route near the end, before any catch-all routes
Route::get('/transactions/filter', [App\Http\Controllers\Transactions\TransactionController::class, 'filter']);
Route::get('/transactions/load-more', [App\Http\Controllers\Transactions\TransactionController::class, 'loadMore']);

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
