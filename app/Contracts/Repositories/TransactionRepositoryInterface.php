<?php

namespace App\Contracts\Repositories;

use App\Models\Transaction;
use Illuminate\Support\Collection;

interface TransactionRepositoryInterface extends BaseRepositoryContract
{
    public function findByTransactionId(string $transactionId): ?Transaction;

    public function createBatch(array $transactions): int;

    public function createOne(array $data): Transaction;

    public function updateOrCreate(array $attributes, array $values): Transaction;

    public function getExistingTransactionIds(array $transactionIds): Collection;

    public function updateBatch(array $updates): int;

    /**
     * Find transactions by composite (account_id, transaction_id) pairs, with relations loaded.
     *
     * @param  array<int, array{0:int,1:string}>  $pairs
     */
    public function findByAccountAndTransactionIdPairs(array $pairs): Collection;

    public function getRecentByAccounts(array $accountIds, int $limit = 10): Collection;

    public function findByUser(int $userId): Collection;

    public function findByAccountIds(array $accountIds): Collection;
}
