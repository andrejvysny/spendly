<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\AccountRepositoryInterface;
use App\Models\Account;
use App\Repositories\Concerns\UserScoped;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

class AccountRepository extends BaseRepository implements AccountRepositoryInterface
{
    use UserScoped;

    public function __construct(Account $model)
    {
        parent::__construct($model);
    }

    public function findByIdForUser(int $accountId, int $userId): ?Account
    {
        $account = $this->model->where('id', $accountId)
            ->where('user_id', $userId)
            ->first();

        return $account instanceof Account ? $account : null;
    }

    public function findByGocardlessId(string $gocardlessAccountId, int $userId): ?Account
    {
        $account = $this->model->where('gocardless_account_id', $gocardlessAccountId)
            ->where('user_id', $userId)
            ->first();

        return $account instanceof Account ? $account : null;
    }

    /**
     * @return Collection<int, Account>
     */
    public function getGocardlessSyncedAccounts(int $userId): Collection
    {
        return $this->model->where('user_id', $userId)
            ->where('is_gocardless_synced', true)
            ->get();
    }

    /**
     * @return LazyCollection<int, Account>
     */
    public function getAllGocardlessSyncedAccounts(): LazyCollection
    {
        return Account::query()
            ->where('is_gocardless_synced', true)
            ->orderBy('id')
            ->cursor();
    }

    public function updateSyncTimestamp(Account $account, ?Carbon $syncedAt = null): bool
    {
        return $account->update([
            'gocardless_last_synced_at' => $syncedAt ?? now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Account
    {
        return $this->model->create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createForUser(int $userId, array $data): Account
    {
        $account = new Account($data);
        $account->user_id = $userId;
        $account->save();

        return $account;
    }

    public function gocardlessAccountExists(string $gocardlessAccountId, int $userId): bool
    {
        return $this->model->where('gocardless_account_id', $gocardlessAccountId)
            ->where('user_id', $userId)
            ->exists();
    }

    /**
     * Update the balance of an account.
     *
     * @param  Account  $account  The account to update
     * @param  float|string  $balance  The new balance value
     */
    public function updateBalance(Account $account, float|string $balance): bool
    {
        return $account->update([
            'balance' => $balance,
        ]);
    }

    public function markNeedsReconnect(Account $account, bool $flag = true): bool
    {
        return $account->update([
            'gocardless_needs_reconnect' => $flag,
        ]);
    }

    public function markSyncQueued(Account $account): bool
    {
        // gocardless_sync_retry_after is deliberately left alone: it is the cooldown gate the
        // caller just cleared, and wiping it here would let a retry loop bypass its own limit.
        return $account->update([
            'gocardless_sync_status' => Account::SYNC_STATUS_QUEUED,
            'gocardless_sync_queued_at' => now(),
            'gocardless_sync_error' => null,
        ]);
    }

    public function markSyncStarted(Account $account): bool
    {
        return $account->update([
            'gocardless_sync_status' => Account::SYNC_STATUS_SYNCING,
            'gocardless_sync_started_at' => now(),
        ]);
    }

    public function markSyncSucceeded(Account $account): bool
    {
        return $account->update([
            'gocardless_sync_status' => Account::SYNC_STATUS_SUCCESS,
            'gocardless_sync_finished_at' => now(),
            'gocardless_sync_error' => null,
            'gocardless_sync_retry_after' => null,
        ]);
    }

    public function markSyncFailed(Account $account, string $status, ?CarbonInterface $retryAfter = null, ?string $error = null): bool
    {
        return $account->update([
            'gocardless_sync_status' => $status,
            'gocardless_sync_finished_at' => now(),
            'gocardless_sync_error' => $error,
            'gocardless_sync_retry_after' => $retryAfter,
        ]);
    }
}
