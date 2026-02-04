<?php

namespace App\Repositories;

use App\Contracts\Repositories\TransactionRepositoryInterface;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TransactionRepository extends BaseRepository implements TransactionRepositoryInterface
{
    public function __construct(Transaction $model)
    {
        parent::__construct($model);
    }

    /**
     * Find a transaction by its GoCardless transaction ID.
     */
    public function findByTransactionId(string $transactionId): ?Transaction
    {
        return Transaction::where('transaction_id', $transactionId)->first();
    }

    /**
     * Create multiple transactions in a batch.
     *
     * @return int Number of created transactions
     */
    public function createBatch(array $transactions): int
    {
        if (empty($transactions)) {
            return 0;
        }

        // Process each transaction to ensure proper JSON encoding
        $processedTransactions = array_map(function ($transaction) {
            // JSON encode metadata if it's an array
            if (isset($transaction['metadata']) && is_array($transaction['metadata'])) {
                $transaction['metadata'] = json_encode($transaction['metadata']);
            }

            // JSON encode import_data if it's an array (shouldn't happen now since mapper encodes it)
            if (isset($transaction['import_data']) && is_array($transaction['import_data'])) {
                $transaction['import_data'] = json_encode($transaction['import_data']);
            }

            return $transaction;
        }, $transactions);

        DB::table('transactions')->insert($processedTransactions);

        return count($transactions);
    }

    /**
     * Create a single transaction.
     */
    public function createOne(array $data): Transaction
    {
        return Transaction::create($data);
    }

    /**
     * Update or create a transaction.
     */
    public function updateOrCreate(array $attributes, array $values): Transaction
    {
        return Transaction::updateOrCreate($attributes, $values);
    }

    /**
     * Get existing transaction IDs from a list.
     */
    public function getExistingTransactionIds(array $transactionIds): Collection
    {
        return Transaction::whereIn('transaction_id', $transactionIds)
            ->pluck('transaction_id');
    }

    /**
     * Update multiple transactions.
     *
     * @param  array  $updates  Array of updates with transaction_id as key
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

    /**
     * Find transactions by composite (account_id, transaction_id) pairs, with relations loaded.
     *
     * @param  array<int, array{0:int,1:string}>  $pairs
     */
    public function findByAccountAndTransactionIdPairs(array $pairs): Collection
    {
        if (empty($pairs)) {
            return collect();
        }

        return Transaction::query()
            ->with(['account.user', 'tags', 'category', 'merchant'])
            ->where(function ($q) use ($pairs) {
                foreach ($pairs as [$accId, $txId]) {
                    $q->orWhere(function ($qq) use ($accId, $txId) {
                        $qq->where('account_id', $accId)
                            ->where('transaction_id', $txId);
                    });
                }
            })
            ->get();
    }

    /**
     * Get recent transactions for given account IDs.
     */
    public function getRecentByAccounts(array $accountIds, int $limit = 10): Collection
    {
        return $this->model->whereIn('account_id', $accountIds)
            ->with(['category', 'merchant', 'account', 'tags'])
            ->orderBy('booked_date', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Find all transactions for a user.
     */
    public function findByUser(int $userId): Collection
    {
        return $this->model->whereHas('account', function ($q) use ($userId) {
            $q->where('user_id', $userId);
        })->get();
    }

    /**
     * Find transactions by account IDs.
     */
    public function findByAccountIds(array $accountIds): Collection
    {
        return $this->model->whereIn('account_id', $accountIds)->get();
    }
}
