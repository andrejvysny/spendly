<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\TransactionRepositoryInterface;
use App\Models\Transaction;
use App\Repositories\Concerns\BatchInsert;
use App\Services\TextSimilarity;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
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
            ->with(['account.user', 'tags', 'category', 'counterparty'])
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
            ->with(['category', 'counterparty', 'account', 'tags'])
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
            ->with(['counterparty', 'account'])
            ->whereHas('account', fn ($q) => $q->where('user_id', $userId))
            ->whereBetween('booked_date', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->excludingTransfers();

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

    /**
     * @param  array<string, mixed>  $mappedData
     */
    public function hasPotentialImportMatch(int $accountId, array $mappedData): bool
    {
        return $this->importCandidateQuery($accountId, $mappedData)->exists();
    }

    /**
     * @param  array<string, mixed>  $mappedData
     */
    public function findStrongMatchingImport(int $accountId, array $mappedData): ?Transaction
    {
        $fingerprint = $this->stringValue($mappedData['fingerprint'] ?? null);
        $description = $this->stringValue($mappedData['description'] ?? null);
        $partner = $this->stringValue($mappedData['partner'] ?? null);

        $candidates = $this->importCandidateQuery($accountId, $mappedData)->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        if ($fingerprint !== '') {
            $exactFingerprintMatches = $candidates
                ->filter(fn (Transaction $candidate) => $candidate->fingerprint === $fingerprint)
                ->values();

            if ($exactFingerprintMatches->count() === 1) {
                return $exactFingerprintMatches->first();
            }

            if ($exactFingerprintMatches->count() > 1) {
                return null;
            }
        }

        $strongMatches = $candidates->filter(function (Transaction $candidate) use ($description, $partner) {
            $descriptionSimilarity = TextSimilarity::similarity($this->stringValue($candidate->description), $description);
            $partnerSimilarity = TextSimilarity::similarity($this->stringValue($candidate->partner), $partner);

            return max($descriptionSimilarity, $partnerSimilarity) >= 0.9;
        })->values();

        return $strongMatches->count() === 1 ? $strongMatches->first() : null;
    }

    /**
     * Same-account rows that could be the CSV-imported counterpart of a synced movement.
     *
     * Rows already produced by a GoCardless sync are excluded: the mapper stamps
     * `import_data` on every synced row, so without this narrowing a previously
     * synced transaction would look like an import candidate and be overwritten
     * by the next identical-but-distinct movement.
     *
     * @param  array<string, mixed>  $mappedData
     * @return Builder<Transaction>
     */
    private function importCandidateQuery(int $accountId, array $mappedData): Builder
    {
        $bookedDate = $this->resolveBookedDate($mappedData);

        return Transaction::query()
            ->where('account_id', $accountId)
            ->whereDate('booked_date', $bookedDate->format('Y-m-d'))
            ->whereRaw('ABS(amount - ?) <= ?', [$this->floatValue($mappedData['amount'] ?? null), 0.01])
            ->where('currency', $this->stringValue($mappedData['currency'] ?? null))
            ->where('is_gocardless_synced', false)
            ->where(function ($query) {
                $query->where('transaction_id', 'like', 'IMP-%')
                    ->orWhereNotNull('import_data');
            });
    }

    /**
     * @param  array<string, mixed>  $mappedData
     */
    private function resolveBookedDate(array $mappedData): Carbon
    {
        $bookedDate = $mappedData['booked_date'] ?? null;

        if ($bookedDate instanceof Carbon) {
            return $bookedDate;
        }

        if (is_scalar($bookedDate) && trim((string) $bookedDate) !== '') {
            return Carbon::parse((string) $bookedDate);
        }

        return Carbon::now();
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function floatValue(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
