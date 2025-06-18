<?php

namespace App\Repositories;

use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TransactionRepository
{
    /**
     * Find a transaction by its GoCardless transaction ID.
     *
     * @param string $transactionId
     * @return Transaction|null
     */
    public function findByTransactionId(string $transactionId): ?Transaction
    {
        return Transaction::where('transaction_id', $transactionId)->first();
    }

    /**
     * Create multiple transactions in a batch.
     *
     * @param array $transactions
     * @return int Number of created transactions
     */
    public function createBatch(array $transactions): int
    {
        if (empty($transactions)) {
            return 0;
        }

        DB::table('transactions')->insert($transactions);
        return count($transactions);
    }

    /**
     * Update or create a transaction.
     *
     * @param array $attributes
     * @param array $values
     * @return Transaction
     */
    public function updateOrCreate(array $attributes, array $values): Transaction
    {
        return Transaction::updateOrCreate($attributes, $values);
    }

    /**
     * Get existing transaction IDs from a list.
     *
     * @param array $transactionIds
     * @return Collection
     */
    public function getExistingTransactionIds(array $transactionIds): Collection
    {
        return Transaction::whereIn('transaction_id', $transactionIds)
            ->pluck('transaction_id');
    }

    /**
     * Update multiple transactions.
     *
     * @param array $updates Array of updates with transaction_id as key
     * @return int Number of updated transactions
     */
    public function updateBatch(array $updates): int
    {
        $count = 0;
        
        DB::transaction(function () use ($updates, &$count) {
            foreach ($updates as $transactionId => $data) {
                $updated = Transaction::where('transaction_id', $transactionId)
                    ->update($data);
                if ($updated) {
                    $count++;
                }
            }
        });

        return $count;
    }
} 