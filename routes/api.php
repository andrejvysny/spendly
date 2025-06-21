<?php

use App\Http\Controllers\Transactions\TransactionController;
use App\Http\Controllers\RuleEngine\RuleController;
use App\Http\Controllers\RuleEngine\RuleExecutionController;
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
// Route::get('/transactions/filter', [TransactionController::class, 'filter']);

// Rule Engine API Routes - JSON responses for CRUD operations
Route::middleware(['web', 'auth'])->prefix('rules')->group(function () {
    // Rule management
    Route::get('/', [RuleController::class, 'index'])->name('rules.api.index');
    Route::get('/options', [RuleController::class, 'getOptions'])->name('rules.options');
    Route::post('/groups', [RuleController::class, 'storeGroup'])->name('rules.groups.store');
    Route::put('/groups/{id}', [RuleController::class, 'updateGroup'])->name('rules.groups.update');
    Route::delete('/groups/{id}', [RuleController::class, 'destroyGroup'])->name('rules.groups.destroy');
    Route::patch('/groups/{id}/toggle-activation', [RuleController::class, 'toggleGroupActivation'])->name('rules.groups.toggle-activation');
    Route::post('/', [RuleController::class, 'store'])->name('rules.store');
    Route::get('/{id}', [RuleController::class, 'show'])->name('rules.show');
    Route::put('/{id}', [RuleController::class, 'update'])->name('rules.update');
    Route::delete('/{id}', [RuleController::class, 'destroy'])->name('rules.destroy');
    Route::post('/{id}/duplicate', [RuleController::class, 'duplicate'])->name('rules.duplicate');
    Route::get('/{id}/statistics', [RuleController::class, 'statistics'])->name('rules.statistics');
    Route::post('/reorder', [RuleController::class, 'reorder'])->name('rules.reorder');

    // Rule execution
    Route::post('/execute/transactions', [RuleExecutionController::class, 'executeOnTransactions'])
        ->name('rules.execute.transactions');
    Route::post('/execute/date-range', [RuleExecutionController::class, 'executeOnDateRange'])
        ->name('rules.execute.date-range');
    Route::post('/test', [RuleExecutionController::class, 'testRule'])
        ->name('rules.test');
});

