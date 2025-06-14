<?php

use App\Http\Controllers\Settings\BankDataController;
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

    Route::get('settings/bank_data', [BankDataController::class, 'edit'])->name('bank_data.edit');
    Route::patch('settings/bank_data', [BankDataController::class, 'update'])->name('bank_data.update');
    Route::delete('settings/bank_data/credentials', [BankDataController::class, 'purgeGoCardlessCredentials'])->name('bank_data.purgeGoCardlessCredentials');
    Route::delete('settings/bank_data', [BankDataController::class, 'destroy'])->name('bank_data.destroy');

    Route::prefix('/api/bank-data/gocardless')->group(function () {

        Route::get('/institutions', [BankDataController::class, 'getInstitutions']);
        Route::get('/requisitions', [BankDataController::class, 'getRequisitions']);
        Route::post('/requisitions', [BankDataController::class, 'createRequisition']);
        Route::delete('/requisitions/{id}', [BankDataController::class, 'deleteRequisition']);
        Route::get('/requisition/callback', [BankDataController::class, 'handleRequisitionCallback'])
            ->withoutMiddleware(['auth'])
            // TODO: Uncomment this when we have a proper signed URL
            // ->middleware('signed'); // or create a custom token/verify middleware
        Route::post('/import/account', [BankDataController::class, 'importAccount']);
        Route::post('/accounts/{account}/sync-transactions', [BankDataController::class, 'syncAccountTransactions']);
    });

});
