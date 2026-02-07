<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\Repositories\GoCardlessSyncFailureRepositoryInterface;
use App\Contracts\Repositories\TransactionRepositoryInterface;
use App\Models\Account;
use App\Models\GoCardlessSyncFailure;
use App\Models\Transaction;
use App\Services\GoCardless\GocardlessMapper;
use App\Services\GoCardless\TransactionDataValidator;
use Carbon\Carbon;
use Illuminate\Console\Command;

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
        GocardlessMapper $mapper,
        TransactionDataValidator $validator,
        TransactionRepositoryInterface $transactionRepository
    ): int {
        $accountId = $this->option('account');
        $dryRun = $this->option('dry-run');

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
            $this->info('Dry run: would retry ' . $due->count() . ' failure(s).');

            return self::SUCCESS;
        }

        $syncDate = Carbon::now();
        $resolved = 0;
        $failed = 0;

        foreach ($due as $failure) {
            $account = Account::find($failure->account_id);
            if (! $account) {
                $failure->update(['retry_count' => $failure->retry_count + 1, 'last_retry_at' => now()]);
                $failed++;
                continue;
            }

            $raw = $failure->raw_data;
            if (! is_array($raw)) {
                $failure->update(['retry_count' => $failure->retry_count + 1, 'last_retry_at' => now()]);
                $failed++;
                continue;
            }

            try {
                $mapped = $mapper->mapTransactionData($raw, $account, $syncDate);
                $validation = $validator->validate($mapped, $syncDate);

                if ($validation->hasErrors()) {
                    $failure->update(['retry_count' => $failure->retry_count + 1, 'last_retry_at' => now()]);
                    $failed++;
                    continue;
                }

                $data = $validation->data;
                $data['account_id'] = $account->id;
                $data['needs_manual_review'] = $validation->needsReview;
                $data['review_reason'] = $validation->reviewReasons !== [] ? implode(',', $validation->reviewReasons) : null;

                $existingIds = $transactionRepository->getExistingTransactionIds($account->id, [$data['transaction_id']]);
                if ($existingIds->isNotEmpty()) {
                    $failureRepository->markResolved($failure->id, 'already_imported');
                    $resolved++;
                    continue;
                }

                $data['fingerprint'] = Transaction::generateFingerprint($data);
                $transactionRepository->createOne($data);
                $failureRepository->markResolved($failure->id, 'auto_fixed');
                $resolved++;
            } catch (\Throwable $e) {
                $failure->update(['retry_count' => $failure->retry_count + 1, 'last_retry_at' => now()]);
                $this->warn("Failure {$failure->id}: " . $e->getMessage());
                $failed++;
            }
        }

        $this->info("Resolved: {$resolved}, still failing: {$failed}.");

        return self::SUCCESS;
    }
}
