<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\Repositories\GoCardlessSyncFailureRepositoryInterface;
use App\Contracts\Repositories\TransactionRepositoryInterface;
use App\Models\Account;
use App\Models\GoCardlessSyncFailure;
use App\Services\GoCardless\TransactionSyncService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class RetryGoCardlessSyncFailuresCommand extends Command
{
    private const int MAX_RETRIES = 5;

    private const int MAX_BACKOFF_MINUTES = 24 * 60;

    protected $signature = 'gocardless:retry-failures
                            {--account= : Retry only for this account ID}
                            {--dry-run : List failures that would be retried without processing}';

    protected $description = 'Retry unresolved GoCardless sync failures with exponential backoff';

    public function handle(
        GoCardlessSyncFailureRepositoryInterface $failureRepository,
        TransactionRepositoryInterface $transactionRepository,
        TransactionSyncService $transactionSyncService
    ): int {
        $accountId = $this->option('account');
        $dryRun = (bool) $this->option('dry-run');

        $failures = $accountId
            ? GoCardlessSyncFailure::whereNull('resolved_at')->where('account_id', $accountId)->get()
            : GoCardlessSyncFailure::whereNull('resolved_at')->get();

        $due = $failures->filter(function (GoCardlessSyncFailure $f) {
            if ($f->retry_count >= self::MAX_RETRIES) {
                return false;
            }
            $backoffMinutes = min(2 ** $f->retry_count, self::MAX_BACKOFF_MINUTES);
            $nextRetry = $f->last_retry_at
                ? $f->last_retry_at->copy()->addMinutes($backoffMinutes)
                : $f->created_at->copy()->addMinutes(1);

            return Carbon::now()->greaterThanOrEqualTo($nextRetry);
        });

        if ($due->isEmpty()) {
            $this->info('No failures due for retry.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info('Dry run: would retry '.$due->count().' failure(s).');

            return self::SUCCESS;
        }

        $resolved = 0;
        $failed = 0;

        // Group by account so the canonical pipeline runs once per account instead of
        // once per failure row, and so one account's problem can't abort another's.
        foreach ($due->groupBy('account_id') as $groupAccountId => $accountFailures) {
            /** @var Collection<int, GoCardlessSyncFailure> $accountFailures */
            try {
                $resolvedInGroup = $this->retryAccountGroup(
                    (int) $groupAccountId,
                    $accountFailures,
                    $failureRepository,
                    $transactionRepository,
                    $transactionSyncService
                );
                $resolved += $resolvedInGroup;
                $failed += $accountFailures->count() - $resolvedInGroup;
            } catch (\Throwable $e) {
                Log::error('gocardless:retry-failures: account group failed', [
                    'account_id' => $groupAccountId,
                    'error' => $e->getMessage(),
                ]);
                $this->warn("Account {$groupAccountId}: retry failed - {$e->getMessage()}");
                $this->bumpRetryCount($accountFailures);
                $failed += $accountFailures->count();
            }
        }

        $this->info("Resolved: {$resolved}, still failing: {$failed}.");

        return self::SUCCESS;
    }

    /**
     * Retry every due failure for one account through the canonical sync pipeline
     * (TransactionSyncService::resyncRawTransactions), then resolve each failure row
     * individually based on whether its transaction exists afterward.
     *
     * @param  Collection<int, GoCardlessSyncFailure>  $failures
     * @return int Number of failures resolved
     */
    private function retryAccountGroup(
        int $accountId,
        Collection $failures,
        GoCardlessSyncFailureRepositoryInterface $failureRepository,
        TransactionRepositoryInterface $transactionRepository,
        TransactionSyncService $transactionSyncService
    ): int {
        $account = Account::with('user')->find($accountId);
        if (! $account instanceof Account) {
            $this->bumpRetryCount($failures);

            return 0;
        }

        $rawPayloads = [];
        $retryable = [];

        foreach ($failures as $failure) {
            $raw = $failure->raw_data;
            if (! is_array($raw)) {
                $failure->update(['retry_count' => $failure->retry_count + 1, 'last_retry_at' => now()]);

                continue;
            }

            $rawPayloads[] = $raw;
            $retryable[] = $failure;
        }

        if ($rawPayloads === []) {
            return 0;
        }

        // Known provider IDs are the only reliable way to check post-run existence:
        // rows the provider sent without a transactionId get a fallback ID synthesised
        // deep in the pipeline, which we cannot cheaply recompute here.
        $candidateIds = array_values(array_unique(array_filter(
            array_map(static fn (GoCardlessSyncFailure $f) => $f->external_transaction_id, $retryable)
        )));

        $existingBefore = $transactionRepository->getExistingTransactionIds($accountId, $candidateIds);

        $transactionSyncService->resyncRawTransactions($rawPayloads, $account);

        $existingAfter = $transactionRepository->getExistingTransactionIds($accountId, $candidateIds);

        $resolvedCount = 0;

        foreach ($retryable as $failure) {
            $externalId = $failure->external_transaction_id;

            if ($externalId !== null && $existingAfter->contains($externalId)) {
                // Existed even before this retry ran: some other path (e.g. a normal
                // sync) already imported it and this failure row is stale.
                $resolution = $existingBefore->contains($externalId) ? 'already_imported' : 'auto_fixed';
                $failureRepository->markResolved($failure->id, $resolution);
                $resolvedCount++;

                continue;
            }

            // Still missing (or unverifiable without a provider ID): count as another
            // failed attempt so backoff applies before the next retry.
            $failure->update(['retry_count' => $failure->retry_count + 1, 'last_retry_at' => now()]);
        }

        return $resolvedCount;
    }

    /**
     * @param  Collection<int, GoCardlessSyncFailure>  $failures
     */
    private function bumpRetryCount(Collection $failures): void
    {
        foreach ($failures as $failure) {
            $failure->update(['retry_count' => $failure->retry_count + 1, 'last_retry_at' => now()]);
        }
    }
}
