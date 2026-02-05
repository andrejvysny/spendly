<?php

declare(strict_types=1);

namespace App\Services\GoCardless;

use App\Contracts\Repositories\GoCardlessSyncFailureRepositoryInterface;
use App\Contracts\Repositories\TransactionRepositoryInterface;
use App\Models\Account;
use App\Models\GoCardlessSyncFailure;
use App\Models\Transaction;
use App\Services\TransferDetectionService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class TransactionSyncService
{
    private const int BATCH_SIZE = 100;

    public function __construct(
        private readonly TransactionRepositoryInterface $transactionRepository,
        private readonly GocardlessMapper $mapper,
        private readonly TransferDetectionService $transferDetectionService,
        private readonly TransactionDataValidator $validator,
        private readonly GoCardlessSyncFailureRepositoryInterface $failureRepository
    ) {}

    /**
     * Sync transactions for an account.
     *
     * @param  bool  $updateExisting  Whether to update already imported transactions (default: true)
     * @return array Statistics about the sync
     */
    public function syncTransactions(array $transactions, Account $account, bool $updateExisting = true): array
    {
        $syncDate = Carbon::now();
        $stats = [
            'total' => count($transactions),
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'needs_review' => 0,
        ];

        if (empty($transactions)) {
            return $stats;
        }

        // Process transactions in batches
        foreach (array_chunk($transactions, self::BATCH_SIZE) as $batch) {
            $batchStats = $this->processBatch($batch, $account, $updateExisting, $syncDate);

            $stats['created'] += $batchStats['created'];
            $stats['updated'] += $batchStats['updated'];
            $stats['skipped'] += $batchStats['skipped'];
            $stats['errors'] += $batchStats['errors'];
            $stats['needs_review'] += $batchStats['needs_review'] ?? 0;
        }

        // Run transfer detection for this user so new same-day pairs are marked
        try {
            $this->transferDetectionService->detectAndMarkTransfersForUser((int) $account->user_id);
        } catch (\Throwable $e) {
            Log::warning('Transfer detection after sync failed', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $stats;
    }

    /**
     * Process a batch of transactions.
     *
     * @param  bool  $updateExisting  Whether to update already imported transactions
     */
    private function processBatch(array $batch, Account $account, bool $updateExisting = true, ?Carbon $syncDate = null): array
    {
        $syncDate = $syncDate ?? Carbon::now();
        $stats = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'needs_review' => 0,
        ];

        // Extract transaction IDs (including fallback IDs from validator)
        $transactionIds = array_map(function ($transaction) {
            return $transaction['transactionId'] ?? null;
        }, $batch);

        $transactionIds = array_filter($transactionIds);

        // Get existing transaction IDs (scoped by account to avoid cross-account collisions)
        $existingIds = $this->transactionRepository->getExistingTransactionIds($account->id, $transactionIds);

        $toCreate = [];
        $toUpdate = [];

        foreach ($batch as $transaction) {
            $externalTransactionId = $transaction['transactionId'] ?? null;

            try {
                $mappedData = $this->mapper->mapTransactionData($transaction, $account, $syncDate);

                $validation = $this->validator->validate($mappedData, $syncDate);

                if ($validation->hasErrors()) {
                    $this->failureRepository->create([
                        'account_id' => $account->id,
                        'user_id' => (int) $account->user_id,
                        'external_transaction_id' => $externalTransactionId,
                        'error_type' => GoCardlessSyncFailure::ERROR_TYPE_VALIDATION,
                        'error_message' => implode(', ', $validation->errors),
                        'raw_data' => $transaction,
                        'validation_errors' => $validation->errors,
                    ]);
                    $stats['errors']++;
                    continue;
                }

                $mappedData = $validation->data;
                $mappedData['needs_manual_review'] = $validation->needsReview;
                $mappedData['review_reason'] = $validation->reviewReasons !== []
                    ? implode(',', $validation->reviewReasons)
                    : null;

                if ($validation->needsReview) {
                    $stats['needs_review']++;
                }

                $mappedData['fingerprint'] = Transaction::generateFingerprint($mappedData);

                $transactionId = $mappedData['transaction_id'];

                $fingerprintExists = $this->transactionRepository->fingerprintExists(
                    $account->id,
                    $mappedData['fingerprint']
                );
                if ($fingerprintExists && ! $existingIds->contains($transactionId)) {
                    $stats['skipped']++;
                    continue;
                }

                if ($existingIds->contains($transactionId)) {
                    if ($updateExisting) {
                        $toUpdate[$transactionId] = $mappedData;
                    } else {
                        $stats['skipped']++;
                        Log::info('Skipping existing transaction', [
                            'transaction_id' => $transactionId,
                            'account_id' => $account->id,
                            'reason' => 'updateExisting is false',
                        ]);
                    }
                } else {
                    $mappedData['created_at'] = now();
                    $mappedData['updated_at'] = now();
                    $toCreate[] = $mappedData;
                }
            } catch (\Throwable $e) {
                Log::error('Error mapping transaction', [
                    'error' => $e->getMessage(),
                    'transaction' => $transaction,
                ]);
                $this->failureRepository->create([
                    'account_id' => $account->id,
                    'user_id' => (int) $account->user_id,
                    'external_transaction_id' => $externalTransactionId,
                    'error_type' => GoCardlessSyncFailure::ERROR_TYPE_MAPPING,
                    'error_message' => $e->getMessage(),
                    'raw_data' => $transaction,
                ]);
                $stats['errors']++;
            }
        }

        // Perform batch operations
        $this->transactionRepository->transaction(function () use ($toCreate, $toUpdate, &$stats) {
            // Batch create
            if (! empty($toCreate)) {
                $created = $this->transactionRepository->createBatch($toCreate);
                $stats['created'] = $created;
            }

            // Batch update (scoped by account)
            if (! empty($toUpdate)) {
                $updated = $this->transactionRepository->updateBatch($account->id, $toUpdate);
                $stats['updated'] = $updated;
            }
        });

        return $stats;
    }

    /**
     * Calculate date range for sync.
     *
     * @throws \InvalidArgumentException When invalid parameters are provided
     * @throws \RuntimeException When date range calculation fails
     */
    public function calculateDateRange(Account $account, int $maxDays = 90, bool $forceMax = false): array
    {
        // Validate input parameters
        if ($maxDays <= 0) {
            throw new \InvalidArgumentException('maxDays must be greater than 0');
        }

        if ($maxDays > 365) {
            throw new \InvalidArgumentException('maxDays cannot exceed 365 days');
        }

        // Calculate dateTo (today)
        $dateTo = now();
        if (! $dateTo->isValid()) {
            throw new \RuntimeException('Failed to create valid dateTo');
        }

        $dateToFormatted = $dateTo->format('Y-m-d');

        // Calculate dateFrom based on account sync history
        $dateFrom = null;

        if ($account->gocardless_last_synced_at && ! $forceMax) {
            // Validate the last synced date
            if (! $account->gocardless_last_synced_at->isValid()) {
                Log::warning('Invalid last synced date for account', [
                    'account_id' => $account->id,
                    'last_synced_at' => $account->gocardless_last_synced_at,
                ]);
                // Fall back to max days ago
                $dateFrom = $dateTo->copy()->subDays($maxDays);
            } else {
                // Check if last synced date is in the future (invalid scenario)
                if ($account->gocardless_last_synced_at->isAfter($dateTo)) {
                    Log::warning('Last synced date is in the future for account', [
                        'account_id' => $account->id,
                        'last_synced_at' => $account->gocardless_last_synced_at,
                        'current_date' => $dateTo,
                    ]);
                    // Fall back to max days ago
                    $dateFrom = $dateTo->copy()->subDays($maxDays);
                } else {
                    // Ensure last synced date is not more than max days ago
                    $maxDaysAgo = $dateTo->copy()->subDays($maxDays);

                    if (! $account->gocardless_last_synced_at->isBefore($maxDaysAgo)) {
                        // If last synced date is not more than max days ago, sync from that date subtracting one day for safety
                        $dateFrom = $account->gocardless_last_synced_at->copy()->subDays(1);
                    } else {
                        // If last synced date is more than max days ago, sync from max days ago
                        $dateFrom = $maxDaysAgo;
                    }
                }
            }
        } else {
            // Otherwise, sync from max days ago
            $dateFrom = $dateTo->copy()->subDays($maxDays);
        }

        // Validate calculated dateFrom
        if (! $dateFrom || ! $dateFrom->isValid()) {
            throw new \RuntimeException('Failed to calculate valid dateFrom');
        }

        $dateFromFormatted = $dateFrom->format('Y-m-d');

        // Validate that dateFrom is not after dateTo
        if ($dateFrom->isAfter($dateTo)) {
            throw new \RuntimeException('dateFrom cannot be after dateTo');
        }

        // Validate that the date range is not too large (safety check)
        $daysDifference = $dateFrom->diffInDays($dateTo);
        if ($daysDifference > $maxDays) {
            Log::warning('Calculated date range exceeds maxDays', [
                'account_id' => $account->id,
                'date_from' => $dateFromFormatted,
                'date_to' => $dateToFormatted,
                'days_difference' => $daysDifference,
                'max_days' => $maxDays,
            ]);
        }

        // Log the calculated date range for debugging
        Log::info('Calculated date range for sync', [
            'account_id' => $account->id,
            'date_from' => $dateFromFormatted,
            'date_to' => $dateToFormatted,
            'days_difference' => $daysDifference,
            'max_days' => $maxDays,
            'force_max' => $forceMax,
        ]);

        return [
            'date_from' => $dateFromFormatted,
            'date_to' => $dateToFormatted,
        ];
    }
}
