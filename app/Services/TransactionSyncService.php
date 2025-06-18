<?php

namespace App\Services;

use App\Models\Account;
use App\Repositories\TransactionRepository;
use App\Services\GocardlessMapper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TransactionSyncService
{
    private const BATCH_SIZE = 100;

    public function __construct(
        private TransactionRepository $transactionRepository,
        private GocardlessMapper $mapper
    ) {}

    /**
     * Sync transactions for an account.
     *
     * @param array $transactions
     * @param Account $account
     * @return array Statistics about the sync
     */
    public function syncTransactions(array $transactions, Account $account): array
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
            $batchStats = $this->processBatch($batch, $account);

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
     * @param array $batch
     * @param Account $account
     * @return array
     */
    private function processBatch(array $batch, Account $account): array
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

                if (!$transactionId) {
                    Log::warning('Transaction without ID', ['transaction' => $transaction]);
                    $stats['errors']++;
                    continue;
                }

                $mappedData = $this->mapper->mapTransactionData($transaction, $account);

                if ($existingIds->contains($transactionId)) {
                    // Prepare for update
                    $toUpdate[$transactionId] = $mappedData;
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
        DB::transaction(function () use ($toCreate, $toUpdate, &$stats) {
            // Batch create
            if (!empty($toCreate)) {
                $created = $this->transactionRepository->createBatch($toCreate);
                $stats['created'] = $created;
            }

            // Batch update
            if (!empty($toUpdate)) {
                $updated = $this->transactionRepository->updateBatch($toUpdate);
                $stats['updated'] = $updated;
            }
        });

        return $stats;
    }

    /**
     * Calculate date range for sync.
     *
     * @param Account $account
     * @param int $maxDays
     * @return array
     */
    public function calculateDateRange(Account $account, int $maxDays = 90): array
    {
        $dateTo = now()->format('Y-m-d');

        //TODO validate

        // If account has been synced before, sync from last sync date
        if ($account->gocardless_last_synced_at) {
            // Ensure last synced date is not more than max days ago
            if (!$account->gocardless_last_synced_at->isBefore(now()->subDays($maxDays))) {
                // If last synced date is not more than max days ago, sync from that date subtracting one day for safety
                $dateFrom = $account->gocardless_last_synced_at->subDays(1)->format('Y-m-d');
            } else {
                // If last synced date is more than max days ago, sync from max days ago
                $dateFrom = now()->subDays($maxDays)->format('Y-m-d');
            }

        } else {
            // Otherwise, sync from max days ago
            $dateFrom = now()->subDays($maxDays)->format('Y-m-d');
        }

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];
    }
}
