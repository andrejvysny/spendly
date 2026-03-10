<?php

use App\Http\Controllers\Settings\BankDataController;
use App\Http\Controllers\Settings\GoCardlessCredentialController;
use App\Http\Controllers\Settings\GoCardlessRequisitionController;
use App\Http\Controllers\Settings\GoCardlessSyncController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
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
        Route::get('/institutions', [GoCardlessRequisitionController::class, 'getInstitutions']);
        Route::get('/requisitions', [GoCardlessRequisitionController::class, 'getRequisitions']);
        Route::post('/requisitions', [GoCardlessRequisitionController::class, 'createRequisition']);
        Route::delete('/requisitions/{id}', [GoCardlessRequisitionController::class, 'deleteRequisition']);
        Route::get('/requisition/callback', [GoCardlessRequisitionController::class, 'handleRequisitionCallback'])
            ->withoutMiddleware(['auth']);
        Route::post('/import/account', [GoCardlessRequisitionController::class, 'importAccount']);
        Route::post('/accounts/{account}/sync-transactions', [GoCardlessSyncController::class, 'syncAccountTransactions'])
            ->name('bank_data.syncAccountTransactions');
        Route::post('/accounts/sync-all', [GoCardlessSyncController::class, 'syncAllAccounts'])
            ->name('bank_data.syncAllAccounts');
        Route::post('/accounts/{account}/refresh-balance', [GoCardlessSyncController::class, 'refreshAccountBalance'])
            ->name('bank_data.refreshAccountBalance');
    });

});
