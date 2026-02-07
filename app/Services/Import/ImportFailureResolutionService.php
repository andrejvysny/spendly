<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Contracts\Repositories\TransactionRepositoryInterface;
use App\Models\Import\ImportFailure;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class ImportFailureResolutionService
{
    public function __construct(
        private readonly TransactionRepositoryInterface $transactionRepository
    ) {}

    /**
     * Create a transaction from a failed import row and mark the failure as resolved.
     *
     * @param  array<string, mixed>  $transactionData  Validated transaction attributes
     */
    public function createTransactionFromFailure(
        ImportFailure $failure,
        array $transactionData,
        User $user
    ): Transaction {
        return $this->transactionRepository->transaction(function () use ($failure, $transactionData, $user) {
            $transaction = $this->transactionRepository->createOne($transactionData);
            $failure->markAsResolved($user, 'Transaction created from review');

            Log::info('Transaction created from import failure review', [
                'transaction_id' => $transaction->id,
                'failure_id' => $failure->id,
                'import_id' => $failure->import_id,
                'created_by' => $user->id,
            ]);

            return $transaction;
        });
    }
}
