<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Account;
use App\Models\Transaction;
use App\Services\AccountBalanceService;
use Illuminate\Support\Facades\Log;

/**
 * Observer for Transaction model to handle balance recalculation.
 *
 * Note: This observer only handles single-row Eloquent operations.
 * Batch operations (createBatch, updateBatch) do not fire model events
 * and must handle balance updates explicitly.
 */
class TransactionObserver
{
    public function __construct(
        private readonly AccountBalanceService $balanceService
    ) {}

    /**
     * Handle the Transaction "created" event.
     */
    public function created(Transaction $transaction): void
    {
        $this->recalculateAccountBalance($transaction, 'created');
    }

    /**
     * Handle the Transaction "updated" event.
     */
    public function updated(Transaction $transaction): void
    {
        // Only recalculate if amount or booked_date changed
        if ($transaction->wasChanged(['amount', 'booked_date', 'balance_after_transaction'])) {
            $this->recalculateAccountBalance($transaction, 'updated');
        }
    }

    /**
     * Handle the Transaction "deleted" event.
     */
    public function deleted(Transaction $transaction): void
    {
        $this->recalculateAccountBalance($transaction, 'deleted');
    }

    /**
     * Handle the Transaction "restored" event (for soft deletes).
     */
    public function restored(Transaction $transaction): void
    {
        $this->recalculateAccountBalance($transaction, 'restored');
    }

    /**
     * Recalculate the account balance after a transaction change.
     */
    private function recalculateAccountBalance(Transaction $transaction, string $event): void
    {
        try {
            /** @var Account|null $account */
            $account = $transaction->account;

            if (! $account instanceof Account) {
                Log::warning('Transaction has no associated account for balance recalculation', [
                    'transaction_id' => $transaction->id,
                    'event' => $event,
                ]);

                return;
            }

            $success = $this->balanceService->recalculateForAccount($account);

            Log::debug('Account balance recalculated after transaction change', [
                'transaction_id' => $transaction->id,
                'account_id' => $account->id,
                'event' => $event,
                'success' => $success,
                'new_balance' => $account->fresh()?->balance,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to recalculate account balance after transaction change', [
                'transaction_id' => $transaction->id,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
