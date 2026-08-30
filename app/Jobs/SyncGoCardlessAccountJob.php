<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\Repositories\AccountRepositoryInterface;
use App\Exceptions\GoCardlessConsentExpiredException;
use App\Exceptions\GoCardlessRateLimitException;
use App\Models\Account;
use App\Models\RecurringDetectionSetting;
use App\Models\User;
use App\Services\GoCardless\GoCardlessService;
use App\Support\SensitiveDataRedactor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Syncs one GoCardless account off the request thread.
 *
 * A sync is a multi-minute conversation with a bank that rate-limits hard and revokes access
 * without warning, so it cannot live in an HTTP request. Everything a caller used to learn from
 * the response body is now written to the account row (gocardless_sync_* columns) and read back
 * through the sync-status endpoints.
 *
 * Retry policy, in one place, because three mechanisms interact:
 *
 * - No $tries. retryUntil() is set, and when a job defines a retry-until deadline the worker
 *   ignores maxTries entirely (Worker::markJobAsFailedIfAlreadyExceedsMaxAttempts). Declaring
 *   both would be a lie about which one governs. The 12-hour deadline is the real bound: it
 *   comfortably outlives a GoCardless daily quota reset without letting a dead account retry
 *   forever.
 * - $maxExceptions caps how many *thrown* failures are tolerated inside that window, so a
 *   genuinely broken account gives up after three real errors rather than grinding for 12 hours.
 * - A 429 is released, not thrown, so it costs no exception budget — the bank told us exactly
 *   when to come back and that is not a failure of ours.
 *
 * $timeout is 280s against a 300s worker --timeout, so the job's own guard fires first and its
 * failed() hook can record a status.
 */
final class SyncGoCardlessAccountJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 280;

    public bool $failOnTimeout = true;

    public int $maxExceptions = 3;

    /**
     * Uniqueness window. Outlives the longest release delay below (900s) plus a run, so a
     * rate-limited job waiting to come back still holds the lock against a duplicate dispatch.
     */
    public int $uniqueFor = 1800;

    public function __construct(
        public readonly int $accountId,
        public readonly int $userId,
        public readonly bool $updateExisting = true,
        public readonly bool $forceMaxDateRange = false,
        public readonly bool $runRecurringDetection = true,
    ) {
        $this->onQueue('gocardless');
    }

    /**
     * Keyed on the account alone: two syncs of the same account are the same work no matter
     * which flags or which entry point asked for them.
     */
    public function uniqueId(): string
    {
        return 'gocardless-sync:'.$this->accountId;
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(12);
    }

    /**
     * @return non-empty-list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(GoCardlessService $service, AccountRepositoryInterface $accounts): void
    {
        $account = $accounts->findByIdForUser($this->accountId, $this->userId);

        if (! $account instanceof Account) {
            // Deleted, or ownership changed, between dispatch and execution. Nothing to sync and
            // nothing to record — the row this job would report on no longer exists.
            Log::info('GoCardless sync job: account gone, skipping', [
                'account_id' => $this->accountId,
                'user_id' => $this->userId,
            ]);

            return;
        }

        $user = User::find($this->userId);

        if (! $user instanceof User) {
            $accounts->markSyncFailed($account, Account::SYNC_STATUS_FAILED, null, 'Owning user no longer exists');

            return;
        }

        $accounts->markSyncStarted($account);

        try {
            $service->syncAccountTransactions($this->accountId, $user, $this->updateExisting, $this->forceMaxDateRange);
        } catch (GoCardlessRateLimitException $e) {
            // The bank named a time; honour it rather than burning an attempt. Released (not
            // failed) so the account keeps its place, bounded by retryUntil().
            $delay = max(60, $e->retryAfterSeconds);

            $accounts->markSyncFailed(
                $account,
                Account::SYNC_STATUS_RATE_LIMITED,
                now()->addSeconds($delay),
                'Rate limited by the bank; retrying automatically.'
            );

            Log::warning('GoCardless sync job rate limited', [
                'account_id' => $this->accountId,
                'user_id' => $this->userId,
                'retry_after_seconds' => $delay,
            ]);

            $this->release($delay);

            return;
        } catch (GoCardlessConsentExpiredException $e) {
            // Retrying can never fix this — only the user re-authorizing can. GoCardlessService
            // has already flagged the account and its requisition; record it and stop.
            $accounts->markSyncFailed(
                $account,
                Account::SYNC_STATUS_NEEDS_RECONNECT,
                null,
                'Bank access ended. Reconnect the bank to resume syncing.'
            );

            Log::warning('GoCardless sync job hit expired consent', [
                'account_id' => $this->accountId,
                'user_id' => $this->userId,
            ]);

            $this->fail($e);

            return;
        } catch (\Throwable $e) {
            // The message may carry a slice of an API body, so it is redacted and clipped before
            // it lands in a column the UI renders.
            $accounts->markSyncFailed($account, Account::SYNC_STATUS_FAILED, null, $this->shortError($e));

            Log::error('GoCardless sync job failed', [
                'account_id' => $this->accountId,
                'user_id' => $this->userId,
                'exception' => $e::class,
                'message' => SensitiveDataRedactor::redact($e->getMessage()),
            ]);

            // Rethrown so the worker applies backoff() and counts it against maxExceptions.
            throw $e;
        }

        $accounts->markSyncSucceeded($account);

        if ($this->runRecurringDetection && RecurringDetectionSetting::forUser($this->userId)->run_after_import) {
            RecurringDetectionJob::dispatch($this->userId, $this->accountId);
        }
    }

    /**
     * Last-resort status write for failures that never reached (or never returned from) handle():
     * a timeout, a serialization failure, or the final attempt giving up.
     *
     * Best effort by design — a status that is already terminal was written by the code that
     * actually knows what went wrong, and must not be flattened to a generic "failed".
     */
    public function failed(?\Throwable $e): void
    {
        $accounts = app(AccountRepositoryInterface::class);
        $account = $accounts->findByIdForUser($this->accountId, $this->userId);

        if (! $account instanceof Account) {
            return;
        }

        $status = $account->gocardless_sync_status;
        if (in_array($status, [Account::SYNC_STATUS_FAILED, Account::SYNC_STATUS_NEEDS_RECONNECT], true)) {
            return;
        }

        $accounts->markSyncFailed(
            $account,
            Account::SYNC_STATUS_FAILED,
            null,
            $e !== null ? $this->shortError($e) : 'Sync job failed'
        );
    }

    private function shortError(\Throwable $e): string
    {
        return Str::limit(SensitiveDataRedactor::redact($e->getMessage()), 200);
    }
}
