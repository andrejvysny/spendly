<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * @extends BaseRepositoryContract<Transaction>
 */
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
     * Get existing transaction IDs for an account (scoped by account_id to avoid cross-account collisions).
     *
     * @param  array<string>  $transactionIds
     * @return Collection<int, string>
     */
    public function getExistingTransactionIds(int $accountId, array $transactionIds): Collection;

    /**
     * Update multiple transactions for an account (scoped by account_id).
     *
     * @param  array<mixed>  $updates  Map of transaction_id => data to update
     */
    public function updateBatch(int $accountId, array $updates): int;

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

    /**
     * Get transactions for recurring detection within a date range.
     *
     * @return Collection<int, Transaction>
     */
    public function getForRecurringDetection(int $userId, Carbon $from, Carbon $to, ?int $accountId = null): Collection;

    public function fingerprintExists(int $accountId, string $fingerprint): bool;
}
