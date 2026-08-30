<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Models\Account;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * @extends UserScopedRepositoryInterface<Account>
 */
interface AccountRepositoryInterface extends UserScopedRepositoryInterface
{
    public function findByIdForUser(int $accountId, int $userId): ?Account;

    public function findByGocardlessId(string $gocardlessAccountId, int $userId): ?Account;

    /**
     * @return Collection<int, Account>
     */
    public function getGocardlessSyncedAccounts(int $userId): Collection;

    /**
     * Every GoCardless-linked account across all users, streamed.
     *
     * Cursored deliberately: the scheduled dispatcher walks the whole installation and must not
     * hold every account in memory to do it. User-scoped callers want getGocardlessSyncedAccounts().
     *
     * @return LazyCollection<int, Account>
     */
    public function getAllGocardlessSyncedAccounts(): LazyCollection;

    /**
     * Update the account's gocardless_last_synced_at watermark.
     *
     * @param  Carbon|null  $syncedAt  Explicit watermark value (defaults to now()).
     *                                 Callers pass a value pulled back from "now" when
     *                                 the sync had failures or was only partially fetched,
     *                                 so the next run's date range still covers the gap.
     */
    public function updateSyncTimestamp(Account $account, ?Carbon $syncedAt = null): bool;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Account;

    /**
     * Create an account owned by the given user. The user_id is set explicitly
     * rather than accepted via $data, since Account::$fillable excludes user_id
     * to prevent mass-assignment of ownership.
     *
     * @param  array<string, mixed>  $data
     */
    public function createForUser(int $userId, array $data): Account;

    public function gocardlessAccountExists(string $gocardlessAccountId, int $userId): bool;

    /**
     * Update the balance of an account.
     *
     * @param  Account  $account  The account to update
     * @param  float|string  $balance  The new balance value
     */
    public function updateBalance(Account $account, float|string $balance): bool;

    /**
     * Flag (or clear) the account as needing the user to re-authorize with their bank.
     *
     * Set when GoCardless reports the 90-day consent as expired/suspended; cleared by the
     * requisition callback once the account is relinked to a fresh requisition.
     */
    public function markNeedsReconnect(Account $account, bool $flag = true): bool;

    /**
     * Sync moved off the request thread, so these five writes are the only channel through
     * which a client learns what happened to a sync it can no longer watch.
     *
     * None of them touch gocardless_last_synced_at: that watermark tracks how far transaction
     * data is known good and stays owned by GoCardlessService.
     */
    public function markSyncQueued(Account $account): bool;

    public function markSyncStarted(Account $account): bool;

    /**
     * Clears both the previous error and any rate-limit cooldown — a completed sync
     * invalidates whatever the last failure said.
     */
    /**
     * Reset an account wedged in `queued`/`syncing` past its staleness threshold.
     *
     * A worker killed mid-run never reaches the job's failed() hook, so the row keeps reporting
     * in-progress and every dispatch path skips it — permanently unsyncable with no recovery.
     * Any rate-limit cooldown on the row is preserved.
     *
     * @return bool True when this call actually reset the account.
     */
    public function reapStaleSync(Account $account): bool;

    /**
     * Record a finished run. The status is derived from the stats: a run that lost rows to
     * validation, mapping, or the unique index is `incomplete`, not `success`.
     *
     * @param  array<string, mixed>  $stats  Counters from TransactionSyncService, or [] when unknown.
     */
    public function markSyncSucceeded(Account $account, array $stats = []): bool;

    /**
     * @param  string  $status  One of Account::SYNC_STATUS_FAILED, _RATE_LIMITED, _NEEDS_RECONNECT.
     * @param  CarbonInterface|null  $retryAfter  Instant before which no new sync may be dispatched.
     * @param  string|null  $error  Short, redacted, operator-facing reason. Never a raw API body.
     */
    public function markSyncFailed(Account $account, string $status, ?CarbonInterface $retryAfter = null, ?string $error = null): bool;
}
