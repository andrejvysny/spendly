<?php

use App\Http\Controllers\BankProviders\GoCardlessController;
use App\Http\Controllers\Import\ImportFailureController;
use App\Http\Controllers\RuleEngine\RuleController;
use App\Http\Controllers\RuleEngine\RuleExecutionController;
use App\Http\Controllers\Transactions\TransactionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth')->get('/user', function (Request $request) {
    return $request->user();
});

// Transactions endpoints
Route::middleware(['web', 'auth'])->group(function () {
    Route::post('/transactions', [TransactionController::class, 'store'])->name('api.transactions.store');
    Route::get('/transactions/field-definitions', [TransactionController::class, 'getFieldDefinitions'])->name('api.transactions.field-definitions');
});

// GoCardless sync endpoints
Route::middleware(['web', 'auth'])->prefix('gocardless')->name('gocardless.')->group(function () {
    Route::post('/accounts/{accountId}/sync', [GoCardlessController::class, 'syncTransactions'])
        ->name('accounts.sync');
    Route::post('/accounts/sync-all', [GoCardlessController::class, 'syncAllAccounts'])
        ->name('accounts.sync-all');
});

// Rule Engine API Routes - JSON responses for CRUD operations
Route::middleware(['web', 'auth'])->prefix('rules')->group(function () {
    // Rule management
    Route::get('/', [RuleController::class, 'index'])->name('rules.api.index');
    Route::get('/options', [RuleController::class, 'getOptions'])->name('rules.options');
    Route::get('/action-input-config', [RuleController::class, 'getActionInputConfig'])->name('rules.action-input-config');
    Route::post('/groups', [RuleController::class, 'storeGroup'])->name('rules.groups.store');
    Route::put('/groups/{id}', [RuleController::class, 'updateGroup'])->name('rules.groups.update');
    Route::delete('/groups/{id}', [RuleController::class, 'destroyGroup'])->name('rules.groups.destroy');
    Route::patch('/groups/{id}/toggle-activation', [RuleController::class, 'toggleGroupActivation'])->name('rules.groups.toggle-activation');
    Route::post('/', [RuleController::class, 'store'])->name('rules.store');
    Route::get('/{id}', [RuleController::class, 'show'])->name('rules.show');
    Route::put('/{id}', [RuleController::class, 'update'])->name('rules.update');
    Route::delete('/{id}', [RuleController::class, 'destroy'])->name('rules.destroy');
    Route::post('/{id}/duplicate', [RuleController::class, 'duplicate'])->name('rules.duplicate');
    Route::patch('/{id}/toggle-activation', [RuleController::class, 'toggleRuleActivation'])->name('rules.toggle-activation');
    Route::get('/{id}/statistics', [RuleController::class, 'statistics'])->name('rules.statistics');
    Route::post('/reorder', [RuleController::class, 'reorder'])->name('rules.reorder');

    // Rule execution
    Route::post('/execute/transactions', [RuleExecutionController::class, 'executeOnTransactions'])
        ->name('rules.execute.transactions');
    Route::post('/execute/date-range', [RuleExecutionController::class, 'executeOnDateRange'])
        ->name('rules.execute.date-range');
    Route::post('/test', [RuleExecutionController::class, 'testRule'])
        ->name('rules.test');
    Route::post('/{id}/execute', [RuleExecutionController::class, 'executeRule'])
        ->name('rules.execute.rule');
    Route::post('/groups/{id}/execute', [RuleExecutionController::class, 'executeRuleGroup'])
        ->name('rules.execute.group');
});

// Import failure management routes
Route::middleware(['web', 'auth'])->group(function () {
    // Get failures for a specific import
    Route::get('/imports/{import}/failures', [ImportFailureController::class, 'index'])
        ->name('api.imports.failures.index');

    // Get failure statistics
    Route::get('/imports/{import}/failures/stats', [ImportFailureController::class, 'stats'])
        ->name('api.imports.failures.stats');

    // Export failures as CSV
    Route::get('/imports/{import}/failures/export', [ImportFailureController::class, 'export'])
        ->name('api.imports.failures.export');

    // Get a specific failure
    Route::get('/imports/{import}/failures/{failure}', [ImportFailureController::class, 'show'])
        ->name('api.imports.failures.show');

    // Mark failure as reviewed
    Route::patch('/imports/{import}/failures/{failure}/reviewed', [ImportFailureController::class, 'markAsReviewed'])
        ->name('api.imports.failures.reviewed');

    // Mark failure as resolved
    Route::patch('/imports/{import}/failures/{failure}/resolved', [ImportFailureController::class, 'markAsResolved'])
        ->name('api.imports.failures.resolved');

    // Mark failure as ignored
    Route::patch('/imports/{import}/failures/{failure}/ignored', [ImportFailureController::class, 'markAsIgnored'])
        ->name('api.imports.failures.ignored');

    // Mark failure as pending (unmark/revert)
    Route::patch('/imports/{import}/failures/{failure}/pending', [ImportFailureController::class, 'markAsPending'])
        ->name('api.imports.failures.pending');

    // Create transaction from failure review
    Route::post('/imports/{import}/failures/{failure}/create-transaction', [ImportFailureController::class, 'createTransactionFromReview'])
        ->name('api.imports.failures.create-transaction');

    // Bulk update failures
    Route::patch('/imports/{import}/failures/bulk', [ImportFailureController::class, 'bulkUpdate'])
        ->name('api.imports.failures.bulk');
});
