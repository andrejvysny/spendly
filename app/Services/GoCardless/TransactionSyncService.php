<?php

namespace App\Services\GoCardless;

use App\Contracts\Repositories\TransactionRepositoryInterface;
use App\Models\Account;
use Illuminate\Support\Facades\Log;

class TransactionSyncService
{
    private const int BATCH_SIZE = 100;

    public function __construct(
        private readonly TransactionRepositoryInterface $transactionRepository,
        private readonly GocardlessMapper $mapper
    ) {}

    /**
     * Sync transactions for an account.
     *
     * @param  bool  $updateExisting  Whether to update already imported transactions (default: true)
     * @return array Statistics about the sync
     */
    public function syncTransactions(array $transactions, Account $account, bool $updateExisting = true): array
    {
        $stats = [
            'total' => count($transactions),
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        if (empty($transactions)) {
            return $stats;
        }

        // Process transactions in batches
        foreach (array_chunk($transactions, self::BATCH_SIZE) as $batch) {
            $batchStats = $this->processBatch($batch, $account, $updateExisting);

            $stats['created'] += $batchStats['created'];
            $stats['updated'] += $batchStats['updated'];
            $stats['skipped'] += $batchStats['skipped'];
            $stats['errors'] += $batchStats['errors'];
        }

        return $stats;
    }

    /**
     * Process a batch of transactions.
     *
     * @param  bool  $updateExisting  Whether to update already imported transactions
     */
    private function processBatch(array $batch, Account $account, bool $updateExisting = true): array
    {
        $stats = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        // Extract transaction IDs
        $transactionIds = array_map(function ($transaction) {
            return $transaction['transactionId'] ?? null;
        }, $batch);

        $transactionIds = array_filter($transactionIds);

        // Get existing transaction IDs
        $existingIds = $this->transactionRepository->getExistingTransactionIds($transactionIds);

        $toCreate = [];
        $toUpdate = [];

        foreach ($batch as $transaction) {
            try {
                $transactionId = $transaction['transactionId'] ?? null;

                if (! $transactionId) {
                    Log::warning('Transaction without ID', ['transaction' => $transaction]);
                    $stats['errors']++;

                    continue;
                }

                $mappedData = $this->mapper->mapTransactionData($transaction, $account);

                if ($existingIds->contains($transactionId)) {
                    if ($updateExisting) {
                        // Prepare for update
                        $toUpdate[$transactionId] = $mappedData;
                    } else {
                        // Skip existing transactions if updateExisting is false
                        $stats['skipped']++;
                        Log::info('Skipping existing transaction', [
                            'transaction_id' => $transactionId,
                            'account_id' => $account->id,
                            'reason' => 'updateExisting is false',
                        ]);
                    }
                } else {
                    // Prepare for creation
                    $mappedData['created_at'] = now();
                    $mappedData['updated_at'] = now();
                    $toCreate[] = $mappedData;
                }
            } catch (\Exception $e) {
                Log::error('Error mapping transaction', [
                    'error' => $e->getMessage(),
                    'transaction' => $transaction,
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

            // Batch update
            if (! empty($toUpdate)) {
                $updated = $this->transactionRepository->updateBatch($toUpdate);
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
