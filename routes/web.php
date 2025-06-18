<?php

use App\Http\Controllers\Accounts\AccountController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\MerchantController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\Transactions\TransactionController;
use App\Http\Controllers\Transactions\TransactionRuleController;
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
    Route::post('/transactions', [TransactionController::class, 'store'])->name('transactions.store');
    Route::post('/transactions/bulk-update', [TransactionController::class, 'bulkUpdate'])->name('transactions.bulk-update');
    Route::put('/transactions/{transaction}', [TransactionController::class, 'updateTransaction'])->name('transactions.update');

    Route::get('/accounts', [AccountController::class, 'index'])->name('accounts.index');
    Route::post('/accounts', [AccountController::class, 'store'])->name('accounts.store');

    Route::get('/accounts/{id}', [AccountController::class, 'show'])->name('accounts.show');
    Route::delete('/accounts/{id}', [AccountController::class, 'destroy'])->name('accounts.destroy');
    Route::put('/accounts/{id}/sync-options', [AccountController::class, 'updateSyncOptions'])->name('accounts.sync-options.update');

    // Transaction Rules
    Route::get('/transaction-rules', [TransactionRuleController::class, 'index'])->name('transaction-rules.index');
    Route::post('/transaction-rules', [TransactionRuleController::class, 'store'])->name('transaction-rules.store');
    Route::put('/transaction-rules/{rule}', [TransactionRuleController::class, 'update'])->name('transaction-rules.update');
    Route::delete('/transaction-rules/{rule}', [TransactionRuleController::class, 'destroy'])->name('transaction-rules.destroy');
    Route::post('/transaction-rules/reorder', [TransactionRuleController::class, 'reorder'])->name('transaction-rules.reorder');

    // Import Routes
    Route::get('/imports', [ImportController::class, 'index'])->name('imports.index');
    Route::post('/imports/upload', [ImportController::class, 'upload'])->name('imports.upload');
    Route::post('/imports/{import}/configure', [ImportController::class, 'configure'])->name('imports.configure');
    Route::post('/imports/{import}/process', [ImportController::class, 'process'])->name('imports.process');
    Route::get('/imports/categories', [ImportController::class, 'getCategories'])->name('imports.categories');
    Route::get('/imports/mappings', [ImportController::class, 'getSavedMappings'])->name('imports.mappings.get');
    Route::post('/imports/mappings', [ImportController::class, 'saveMapping'])->name('imports.mappings.save');
    Route::put('/imports/mappings/{mapping}', [ImportController::class, 'updateMappingUsage'])->name('imports.mappings.usage');
    Route::delete('/imports/mappings/{mapping}', [ImportController::class, 'deleteMapping'])->name('imports.mappings.delete');
    Route::post('/imports/revert/{id}', [ImportController::class, 'revertImport'])->name('imports.revert');

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
