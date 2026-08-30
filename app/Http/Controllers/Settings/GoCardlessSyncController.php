<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Contracts\Repositories\AccountRepositoryInterface;
use App\Exceptions\GoCardlessApiException;
use App\Exceptions\GoCardlessConsentExpiredException;
use App\Exceptions\GoCardlessRateLimitException;
use App\Http\Controllers\Controller;
use App\Jobs\SyncGoCardlessAccountJob;
use App\Models\Account;
use App\Models\User;
use App\Services\AccountBalanceService;
use App\Services\GoCardless\GoCardlessService;
use App\Support\SensitiveDataRedactor;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GoCardlessSyncController extends Controller
{
    private GoCardlessService $gocardlessService;

    private AccountBalanceService $balanceService;

    private AccountRepositoryInterface $accountRepository;

    public function __construct(
        GoCardlessService $gocardlessService,
        AccountBalanceService $balanceService,
        AccountRepositoryInterface $accountRepository
    ) {
        $this->gocardlessService = $gocardlessService;
        $this->balanceService = $balanceService;
        $this->accountRepository = $accountRepository;
    }

    /**
     * Build a structured, secret-safe log context for a GoCardless failure.
     *
     * Deliberately excludes a stack trace: frames on this path include the arguments to
     * Http::post()/Http::get() calls, which can contain requisition or refresh secrets.
     *
     * @return array<string, mixed>
     */
    private function exceptionContext(\Throwable $e, mixed $userId = null): array
    {
        return [
            'user_id' => is_int($userId) || is_string($userId) ? $userId : null,
            'exception' => $e::class,
            'message' => SensitiveDataRedactor::redact($e->getMessage()),
            'correlation_id' => $e instanceof GoCardlessApiException ? $e->correlationId : null,
            'at' => $e->getFile().':'.$e->getLine(),
        ];
    }

    /**
     * Build the browser-facing error payload for an unhandled GoCardless failure — never the raw
     * exception message, only a correlation id (when one exists) an operator can look up in logs.
     *
     * @return array<string, mixed>
     */
    private function genericErrorPayload(string $message, \Throwable $e): array
    {
        return array_filter([
            'success' => false,
            'error' => $message,
            'ref' => $e instanceof GoCardlessApiException ? $e->correlationId : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * The bank connection is dead until the user re-authorizes. 409 (not 500) so the client can
     * tell "do something about it" apart from "try again later".
     */
    private function reconnectRequiredResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => 'reconnect_required',
            'message' => 'Your bank has ended access to this account. Reconnect the bank in Settings to resume syncing.',
        ], 409);
    }

    /**
     * Seconds left on an account's sync cooldown, or null when it is free to sync.
     */
    private function cooldownSeconds(Account $account): ?int
    {
        $retryAfter = $account->getAttribute('gocardless_sync_retry_after');

        if (! $retryAfter instanceof CarbonInterface || ! $retryAfter->isFuture()) {
            return null;
        }

        return max(1, (int) ceil(now()->diffInSeconds($retryAfter, true)));
    }

    /**
     * The only account fields a client may see. Whitelisted rather than filtered: the account row
     * carries IBANs and provider identifiers that have no business in a polling response.
     *
     * @return array<string, mixed>
     */
    private function syncStatusPayload(Account $account): array
    {
        $lastSynced = $account->getAttribute('gocardless_last_synced_at');
        $finished = $account->getAttribute('gocardless_sync_finished_at');

        return [
            'id' => $account->id,
            'sync_status' => $account->gocardless_sync_status,
            'last_synced_at' => $lastSynced instanceof CarbonInterface ? $lastSynced->toIso8601String() : null,
            'finished_at' => $finished instanceof CarbonInterface ? $finished->toIso8601String() : null,
            'error' => $account->gocardless_sync_error,
            'retry_after_seconds' => $this->cooldownSeconds($account),
            'needs_reconnect' => (bool) $account->gocardless_needs_reconnect,
        ];
    }

    /**
     * Book a sync for one account.
     *
     * Returns 202, never sync results: the work now happens on the queue, and the client learns
     * the outcome by polling syncStatus(). Repeating the request while a job is already booked is
     * a no-op rather than a second dispatch — the job's unique lock would collapse it anyway, and
     * re-queuing would only reset the timestamps the client is watching.
     */
    public function syncAccountTransactions(Request $request, int $account): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json([
                'success' => false,
                'error' => 'User not authenticated or invalid user type',
            ], 401);
        }

        $model = $this->accountRepository->findByIdForUser($account, $user->id);
        if (! $model instanceof Account) {
            return response()->json([
                'success' => false,
                'error' => 'Account not found',
            ], 404);
        }

        if ($model->gocardless_needs_reconnect) {
            return $this->reconnectRequiredResponse();
        }

        $cooldown = $this->cooldownSeconds($model);
        if ($cooldown !== null) {
            return response()->json([
                'success' => false,
                'error' => 'rate_limited',
                'message' => 'This account is still cooling down after a rate limit from the bank.',
                'retry_after' => $cooldown,
            ], 429);
        }

        if (in_array($model->gocardless_sync_status, Account::SYNC_STATUSES_IN_PROGRESS, true)) {
            return response()->json([
                'success' => true,
                'status' => $model->gocardless_sync_status,
                'data' => $this->queuedPayload($model),
            ], 202);
        }

        $this->dispatchSync($model, $user, $request);

        return response()->json([
            'success' => true,
            'status' => Account::SYNC_STATUS_QUEUED,
            'data' => $this->queuedPayload($model->refresh()),
        ], 202);
    }

    /**
     * Book a sync for every GoCardless-linked account the user owns.
     *
     * Accounts that cannot be queued are reported in the array rather than dropped, so the client
     * can tell "nothing to do" apart from "your bank connection is dead".
     */
    public function syncAllAccounts(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json([
                'success' => false,
                'error' => 'User not authenticated or invalid user type',
            ], 401);
        }

        $configuredStagger = config('services.gocardless.dispatch_stagger_seconds', 20);
        $staggerSeconds = is_numeric($configuredStagger) ? (int) $configuredStagger : 20;
        $accounts = $this->accountRepository->getGocardlessSyncedAccounts($user->id);

        $results = [];
        $queued = 0;

        foreach ($accounts as $model) {
            if ($model->gocardless_needs_reconnect) {
                $results[] = $this->queuedPayload($model, Account::SYNC_STATUS_NEEDS_RECONNECT);

                continue;
            }

            $cooldown = $this->cooldownSeconds($model);
            if ($cooldown !== null) {
                $results[] = $this->queuedPayload($model, Account::SYNC_STATUS_RATE_LIMITED) + ['retry_after' => $cooldown];

                continue;
            }

            if (in_array($model->gocardless_sync_status, Account::SYNC_STATUSES_IN_PROGRESS, true)) {
                $results[] = $this->queuedPayload($model);

                continue;
            }

            $this->dispatchSync($model, $user, $request, $queued * $staggerSeconds);
            $queued++;
            $results[] = $this->queuedPayload($model->refresh());
        }

        return response()->json([
            'success' => true,
            'status' => Account::SYNC_STATUS_QUEUED,
            'message' => sprintf('Sync queued for %d account(s).', $queued),
            'data' => $results,
        ], 202);
    }

    /**
     * Current sync state of every GoCardless-linked account the user owns.
     */
    public function syncStatusAll(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json([
                'success' => false,
                'error' => 'User not authenticated or invalid user type',
            ], 401);
        }

        $data = $this->accountRepository->getGocardlessSyncedAccounts($user->id)
            ->map(fn (Account $account): array => $this->syncStatusPayload($account))
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Current sync state of one account. This is what the detail page polls after a 202.
     */
    public function syncStatus(Request $request, int $account): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json([
                'success' => false,
                'error' => 'User not authenticated or invalid user type',
            ], 401);
        }

        $model = $this->accountRepository->findByIdForUser($account, $user->id);
        if (! $model instanceof Account) {
            return response()->json([
                'success' => false,
                'error' => 'Account not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->syncStatusPayload($model),
        ]);
    }

    /**
     * Mark the account queued and push the job. Order matters: the row is stamped first so a
     * client polling immediately after the 202 never sees a stale 'idle'.
     */
    private function dispatchSync(Account $account, User $user, Request $request, int $delaySeconds = 0): void
    {
        $this->accountRepository->markSyncQueued($account);

        $job = SyncGoCardlessAccountJob::dispatch(
            (int) $account->id,
            (int) $user->id,
            $request->boolean('update_existing', true),
            $request->boolean('force_max_date_range', false),
        );

        if ($delaySeconds > 0) {
            $job->delay(now()->addSeconds($delaySeconds));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function queuedPayload(Account $account, ?string $status = null): array
    {
        $queuedAt = $account->getAttribute('gocardless_sync_queued_at');

        return [
            'account_id' => $account->id,
            'sync_status' => $status ?? $account->gocardless_sync_status,
            'queued_at' => $queuedAt instanceof CarbonInterface ? $queuedAt->toIso8601String() : null,
        ];
    }

    /**
     * Refresh account balance from GoCardless API without syncing transactions.
     */
    public function refreshAccountBalance(Request $request, int $accountId): JsonResponse
    {
        // Hoisted out of the try so the rate-limit handler below can still record the cooldown
        // on the row it was working on.
        $account = null;

        try {
            $user = $request->user();
            if (! $user instanceof User) {
                return response()->json([
                    'success' => false,
                    'error' => 'User not authenticated',
                ], 401);
            }

            $account = $this->accountRepository->findByIdForUser($accountId, $user->id);

            if (! $account) {
                return response()->json([
                    'success' => false,
                    'error' => 'Account not found',
                ], 404);
            }

            if (! $account->is_gocardless_synced) {
                // For non-GoCardless accounts, recalculate from transactions
                $success = $this->balanceService->recalculateForAccount($account);
                $source = 'transactions';
            } elseif (($cooldown = $this->cooldownSeconds($account)) !== null) {
                // This call hits the same per-institution quota the sync job does, so it honours
                // the cooldown the job recorded instead of spending the account's way back into one.
                return response()->json([
                    'success' => false,
                    'error' => 'rate_limited',
                    'message' => 'This account is still cooling down after a rate limit from the bank.',
                    'retry_after' => $cooldown,
                ], 429);
            } else {
                // For GoCardless accounts, refresh from API
                $success = $this->gocardlessService->refreshAccountBalance($account);
                $source = 'gocardless_api';
            }

            if ($success) {
                $account->refresh();

                return response()->json([
                    'success' => true,
                    'message' => 'Balance refreshed successfully',
                    'data' => [
                        'account_id' => $account->id,
                        'balance' => (float) $account->balance,
                        'currency' => $account->currency,
                        'source' => $source,
                    ],
                ]);
            }

            return response()->json([
                'success' => false,
                'error' => 'Could not refresh balance',
            ], 500);
        } catch (GoCardlessRateLimitException $e) {
            // Record the cooldown so the next sync dispatch backs off too, rather than each entry
            // point discovering the same limit on its own.
            if ($account instanceof Account) {
                $this->accountRepository->markSyncFailed(
                    $account,
                    Account::SYNC_STATUS_RATE_LIMITED,
                    now()->addSeconds(max(60, $e->retryAfterSeconds)),
                    'Rate limited by the bank.'
                );
            }

            return response()->json([
                'success' => false,
                'error' => 'rate_limited',
                'retry_after' => $e->retryAfterSeconds,
            ], 429);
        } catch (GoCardlessConsentExpiredException $e) {
            Log::warning('GoCardless consent expired during balance refresh', array_merge(
                ['account_id' => $accountId],
                $this->exceptionContext($e, $request->user()?->getKey())
            ));

            return $this->reconnectRequiredResponse();
        } catch (\Exception $e) {
            Log::error('Failed to refresh account balance', array_merge(
                ['account_id' => $accountId],
                $this->exceptionContext($e, $request->user()?->getKey())
            ));

            return response()->json($this->genericErrorPayload('Failed to refresh balance', $e), 500);
        }
    }
}
