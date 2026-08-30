<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\AccountRepositoryInterface;
use App\Models\Account;
use App\Repositories\Concerns\UserScoped;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
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

    public function reapStaleSync(Account $account): bool
    {
        $status = $account->gocardless_sync_status;

        $threshold = match ($status) {
            Account::SYNC_STATUS_SYNCING => Account::SYNC_STALE_SYNCING_SECONDS,
            Account::SYNC_STATUS_QUEUED => Account::SYNC_STALE_QUEUED_SECONDS,
            default => null,
        };

        if ($threshold === null) {
            return false;
        }

        // `syncing` is timed from when the run actually started; `queued` from when it was booked.
        $since = $status === Account::SYNC_STATUS_SYNCING
            ? $account->getAttribute('gocardless_sync_started_at')
            : $account->getAttribute('gocardless_sync_queued_at');

        if (! $since instanceof CarbonInterface || $since->gt(now()->subSeconds($threshold))) {
            return false;
        }

        // The cooldown is carried over rather than cleared: an account can be queued while still
        // holding a rate-limit cooldown (markSyncQueued deliberately leaves it alone), and losing
        // it here would let the next dispatch walk straight back into the bank's 429.
        $retryAfter = $account->getAttribute('gocardless_sync_retry_after');

        Log::warning('Reaping stale GoCardless sync', [
            'account_id' => $account->id,
            'status' => $status,
            'stale_since' => $since->toIso8601String(),
        ]);

        return $this->markSyncFailed(
            $account,
            Account::SYNC_STATUS_FAILED,
            $retryAfter instanceof CarbonInterface ? $retryAfter : null,
            'Sync stopped unexpectedly and did not report back. You can start it again.'
        );
    }

    public function markSyncSucceeded(Account $account, array $stats = []): bool
    {
        $lost = $this->intStat($stats, 'errors') + $this->intStat($stats, 'dropped');

        return $account->update([
            // A run that could not store everything it fetched is not a success. Saying so is the
            // difference between "142 imported" and "142 imported, 3 failed" in the UI, and the
            // watermark has already been held back for the same rows.
            'gocardless_sync_status' => $lost > 0
                ? Account::SYNC_STATUS_INCOMPLETE
                : Account::SYNC_STATUS_SUCCESS,
            'gocardless_sync_finished_at' => now(),
            'gocardless_sync_error' => $lost > 0
                ? $lost.' transaction(s) could not be imported and will be retried.'
                : null,
            'gocardless_sync_retry_after' => null,
            'gocardless_sync_stats' => $stats === [] ? null : $stats,
        ]);
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function intStat(array $stats, string $key): int
    {
        return is_numeric($stats[$key] ?? null) ? (int) $stats[$key] : 0;
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
