<?php

use App\Http\Controllers\Settings\BankDataController;
use App\Http\Controllers\Settings\GoCardlessCredentialController;
use App\Http\Controllers\Settings\GoCardlessRequisitionController;
use App\Http\Controllers\Settings\GoCardlessSyncController;
use App\Http\Controllers\Settings\MlPersonalizationController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/appearance');
    })->name('appearance');

    Route::get('settings/recurring', fn () => \Inertia\Inertia::render('settings/recurring'))->name('recurring_settings.edit');

    Route::get('settings/bank_data', [GoCardlessCredentialController::class, 'edit'])->name('bank_data.edit');
    Route::patch('settings/bank_data', [GoCardlessCredentialController::class, 'update'])->name('bank_data.update');
    Route::delete('settings/bank_data/credentials', [GoCardlessCredentialController::class, 'purgeGoCardlessCredentials'])->name('bank_data.purgeGoCardlessCredentials');
    Route::delete('settings/bank_data', [BankDataController::class, 'destroy'])->name('bank_data.destroy');

    Route::prefix('/api/bank-data/gocardless')->group(function () {
        Route::get('/institutions', [GoCardlessRequisitionController::class, 'getInstitutions'])
            ->middleware('throttle:gocardless-read');
        Route::get('/requisitions', [GoCardlessRequisitionController::class, 'getRequisitions'])
            ->middleware('throttle:gocardless-read');
        Route::post('/requisitions', [GoCardlessRequisitionController::class, 'createRequisition'])
            ->middleware('throttle:gocardless-write');
        Route::delete('/requisitions/{id}', [GoCardlessRequisitionController::class, 'deleteRequisition'])
            ->middleware('throttle:gocardless-write');
        // {requisitionRow} is the local row's primary key (implicit model binding), deliberately
        // named differently from the {id} above — that one is the opaque GoCardless requisition id.
        Route::post('/requisitions/{requisitionRow}/reconnect', [GoCardlessRequisitionController::class, 'reconnect'])
            ->name('bank_data.gocardless.reconnect')
            ->middleware('throttle:gocardless-write');
        Route::get('/requisition/callback', [GoCardlessRequisitionController::class, 'handleRequisitionCallback'])
            ->name('bank_data.gocardless.callback')
            ->withoutMiddleware(['auth', 'verified'])
            ->middleware('throttle:gocardless-callback');
        Route::post('/import/account', [GoCardlessRequisitionController::class, 'importAccount'])
            ->middleware('throttle:gocardless-write');
        Route::post('/accounts/{account}/sync-transactions', [GoCardlessSyncController::class, 'syncAccountTransactions'])
            ->name('bank_data.syncAccountTransactions')
            ->middleware('throttle:gocardless-sync');
        Route::post('/accounts/sync-all', [GoCardlessSyncController::class, 'syncAllAccounts'])
            ->name('bank_data.syncAllAccounts')
            ->middleware('throttle:gocardless-sync');
        // Sync is queued, so these are how a client learns the outcome. Read-throttled, not
        // sync-throttled: polling every few seconds must not eat the sync budget.
        Route::get('/accounts/sync-status', [GoCardlessSyncController::class, 'syncStatusAll'])
            ->name('bank_data.syncStatusAll')
            ->middleware('throttle:gocardless-read');
        Route::get('/accounts/{account}/sync-status', [GoCardlessSyncController::class, 'syncStatus'])
            ->name('bank_data.syncStatus')
            ->middleware('throttle:gocardless-read');
        Route::post('/accounts/{account}/refresh-balance', [GoCardlessSyncController::class, 'refreshAccountBalance'])
            ->name('bank_data.refreshAccountBalance')
            ->middleware('throttle:gocardless-write');
    });

    Route::get('settings/ml_engine', [MlPersonalizationController::class, 'edit'])->name('ml_engine.edit');
    Route::patch('settings/ml_engine', [MlPersonalizationController::class, 'update'])->name('ml_engine.update');
    Route::post('settings/ml_engine/retrain', [MlPersonalizationController::class, 'retrain'])->name('ml_engine.retrain');

});
