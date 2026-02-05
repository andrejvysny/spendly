<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\TransactionRepositoryInterface;
use App\Models\Transaction;
use App\Repositories\Concerns\BatchInsert;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class TransactionRepository extends BaseRepository implements TransactionRepositoryInterface
{
    use BatchInsert;

    public function __construct(Transaction $model)
    {
        parent::__construct($model);
    }

    public function findByTransactionId(string $transactionId): ?Transaction
    {
        $model = $this->model->where('transaction_id', $transactionId)->first();

        return $model instanceof Transaction ? $model : null;
    }

    /**
     * @param  array<mixed>  $transactions
     */
    public function createBatch(array $transactions): int
    {
        return $this->batchInsert(
            'transactions',
            $transactions,
            ['metadata', 'import_data']
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createOne(array $data): Transaction
    {
        $model = $this->model->create($data);

        return $model instanceof Transaction ? $model : $this->model->find($model->getKey());
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $values
     */
    public function updateOrCreate(array $attributes, array $values): Transaction
    {
        $model = $this->model->updateOrCreate($attributes, $values);

        return $model instanceof Transaction ? $model : $this->model->find($model->getKey());
    }

    /**
     * @param  array<string>  $transactionIds
     * @return Collection<int, string>
     */
    public function getExistingTransactionIds(int $accountId, array $transactionIds): Collection
    {
        if (empty($transactionIds)) {
            return collect();
        }

        return $this->model->where('account_id', $accountId)
            ->whereIn('transaction_id', $transactionIds)
            ->pluck('transaction_id');
    }

    /**
     * @param  array<mixed>  $updates
     */
    public function updateBatch(int $accountId, array $updates): int
    {
        $count = 0;

        $this->transaction(function () use ($accountId, $updates, &$count) {
            foreach ($updates as $transactionId => $data) {
                $updated = $this->model->where('account_id', $accountId)
                    ->where('transaction_id', $transactionId)
                    ->update($data);
                if ($updated) {
                    $count++;
                }
            }
        });

        return $count;
    }

    /**
     * @param  array<int, array{0:int,1:string}>  $pairs
     * @return Collection<int, Transaction>
     */
    public function findByAccountAndTransactionIdPairs(array $pairs): Collection
    {
        if (empty($pairs)) {
            return collect();
        }

        return $this->model->query()
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
     * @param  array<int>  $accountIds
     * @return Collection<int, Transaction>
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
     * @return Collection<int, Transaction>
     */
    public function findByUser(int $userId): Collection
    {
        return $this->model->whereHas('account', function ($q) use ($userId) {
            $q->where('user_id', $userId);
        })->get();
    }

    /**
     * @param  array<int>  $accountIds
     * @return Collection<int, Transaction>
     */
    public function findByAccountIds(array $accountIds): Collection
    {
        return $this->model->whereIn('account_id', $accountIds)->get();
    }

    /**
     * @return Collection<int, Transaction>
     */
    public function getForRecurringDetection(int $userId, Carbon $from, Carbon $to, ?int $accountId = null): Collection
    {
        $query = $this->model
            ->with(['merchant', 'account'])
            ->whereHas('account', fn ($q) => $q->where('user_id', $userId))
            ->whereBetween('booked_date', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);

        if ($accountId !== null) {
            $query->where('account_id', $accountId);
        }

        return $query->orderBy('booked_date')->get();
    }

    public function fingerprintExists(int $accountId, string $fingerprint): bool
    {
        return $this->model
            ->where('account_id', $accountId)
            ->where('fingerprint', $fingerprint)
            ->whereNotNull('fingerprint')
            ->exists();
    }
}
