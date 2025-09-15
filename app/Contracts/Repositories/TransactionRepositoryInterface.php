<?php

namespace App\Contracts\Repositories;

use App\Models\Transaction;
use Illuminate\Support\Collection;

interface TransactionRepositoryInterface extends BaseRepositoryContract
{
    public function findByTransactionId(string $transactionId): ?Transaction;

    /**
     * @param  array<mixed>  $transactions
     */
    public function createBatch(array $transactions): int;

    /**
     * @param  array<string, mixed>  $data
     */
    public function createOne(array $data): Transaction;

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $values
     */
    public function updateOrCreate(array $attributes, array $values): Transaction;

    /**
     * @param  array<string>  $transactionIds
     * @return Collection<int, string>
     */
    public function getExistingTransactionIds(array $transactionIds): Collection;

    /**
     * @param  array<mixed>  $updates
     */
    public function updateBatch(array $updates): int;

    /**
     * Find transactions by composite (account_id, transaction_id) pairs, with relations loaded.
     *
     * @param  array<int, array{0:int,1:string}>  $pairs
     * @return Collection<int, Transaction>
     */
    public function findByAccountAndTransactionIdPairs(array $pairs): Collection;

    /**
     * @param  array<int>  $accountIds
     * @return Collection<int, Transaction>
     */
    public function getRecentByAccounts(array $accountIds, int $limit = 10): Collection;

    /**
     * @return Collection<int, Transaction>
     */
    public function findByUser(int $userId): Collection;

    /**
     * @param  array<int>  $accountIds
     * @return Collection<int, Transaction>
     */
    public function findByAccountIds(array $accountIds): Collection;
}
