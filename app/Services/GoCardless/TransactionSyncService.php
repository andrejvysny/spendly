<?php

declare(strict_types=1);

namespace App\Services\GoCardless;

use App\Contracts\Repositories\GoCardlessSyncFailureRepositoryInterface;
use App\Contracts\Repositories\TransactionRepositoryInterface;
use App\Contracts\RuleEngine\RuleEngineInterface;
use App\Events\TransactionCreated;
use App\Models\Account;
use App\Models\GoCardlessSyncFailure;
use App\Models\RuleEngine\Trigger;
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
        private readonly GoCardlessSyncFailureRepositoryInterface $failureRepository,
        private readonly RuleEngineInterface $ruleEngine
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
                    $existingImport = $this->transactionRepository->findStrongMatchingImport(
                        $account->id,
                        $mappedData
                    );

                    if ($existingImport !== null) {
                        $toUpdate[$existingImport->transaction_id] = $mappedData;
                    } elseif ($this->transactionRepository->fingerprintExists($account->id, $mappedData['fingerprint'])) {
                        $stats['skipped']++;
                    } elseif ($this->hasPotentialImportMatch($account->id, $mappedData)) {
                        $mappedData['needs_manual_review'] = true;
                        $mappedData['review_reason'] = $this->appendReviewReason(
                            $mappedData['review_reason'] ?? null,
                            'probable_duplicate'
                        );
                        $mappedData['created_at'] = now();
                        $mappedData['updated_at'] = now();
                        $toCreate[] = $mappedData;
                    } else {
                        $mappedData['created_at'] = now();
                        $mappedData['updated_at'] = now();
                        $toCreate[] = $mappedData;
                    }
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
        $this->transactionRepository->transaction(function () use ($toCreate, $toUpdate, &$stats, $account) {
            // Batch create.
            if (! empty($toCreate)) {
                $created = $this->transactionRepository->createBatch(array_values($toCreate));
                $stats['created'] = $created;
            }

            // Batch update (scoped by account)
            if (! empty($toUpdate)) {
                $updated = $this->transactionRepository->updateBatch($account->id, $toUpdate);
                $stats['updated'] = $updated;
            }
        });

        // Process rules on newly created transactions after the DB transaction commits
        if (! empty($toCreate)) {
            try {
                $transactionIds = array_column($toCreate, 'transaction_id');
                $createdTransactions = Transaction::where('account_id', $account->id)
                    ->whereIn('transaction_id', $transactionIds)
                    ->with(['account.user', 'tags', 'category', 'counterparty'])
                    ->get();

                if ($createdTransactions->isNotEmpty()) {
                    /** @var \App\Models\User $user */
                    $user = $createdTransactions->first()->account->user;
                    $this->ruleEngine
                        ->setUser($user)
                        ->processTransactions($createdTransactions, Trigger::TRANSACTION_CREATED);

                    // Fire events for other subscribers without re-running rules
                    foreach ($createdTransactions as $t) {
                        event(new TransactionCreated($t, false));
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Rule processing after GoCardless sync failed', [
                    'account_id' => $account->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $stats;
    }

    /**
     * @param  array<string, mixed>  $mappedData
     */
    private function hasPotentialImportMatch(int $accountId, array $mappedData): bool
    {
        $bookedDate = $mappedData['booked_date'] instanceof Carbon
            ? $mappedData['booked_date']
            : Carbon::parse((string) ($mappedData['booked_date'] ?? now()));

        return Transaction::query()
            ->where('account_id', $accountId)
            ->whereDate('booked_date', $bookedDate->toDateString())
            ->whereRaw('ABS(amount - ?) <= ?', [(float) ($mappedData['amount'] ?? 0), 0.01])
            ->where('currency', (string) ($mappedData['currency'] ?? ''))
            ->where(function ($query) {
                $query->where('transaction_id', 'like', 'IMP-%')
                    ->orWhereNotNull('import_data');
            })
            ->exists();
    }

    private function appendReviewReason(?string $existingReasons, string $newReason): string
    {
        $reasons = $existingReasons !== null && trim($existingReasons) !== ''
            ? explode(',', $existingReasons)
            : [];

        $reasons[] = $newReason;
        $reasons = array_values(array_unique(array_filter(array_map('trim', $reasons))));

        return implode(',', $reasons);
    }

    /**
     * Calculate date range for sync.
     *
     * @throws \InvalidArgumentException When invalid parameters are provided
     */
    public function calculateDateRange(Account $account, int $maxDays = 90, bool $forceMax = false): array
    {
        if ($maxDays <= 0 || $maxDays > 365) {
            throw new \InvalidArgumentException('maxDays must be between 1 and 365');
        }

        $dateTo = now();
        $maxDaysAgo = $dateTo->copy()->subDays($maxDays);

        if (! $forceMax && $account->gocardless_last_synced_at) {
            $lastSynced = $account->gocardless_last_synced_at;
            if ($lastSynced->isAfter($dateTo)) {
                Log::warning('Last synced date is in the future', [
                    'account_id' => $account->id,
                    'last_synced_at' => $lastSynced,
                ]);
                $dateFrom = $maxDaysAgo;
            } elseif ($lastSynced->isBefore($maxDaysAgo)) {
                $dateFrom = $maxDaysAgo;
            } else {
                $dateFrom = $lastSynced->copy()->subDays(1);
            }
        } else {
            $dateFrom = $maxDaysAgo;
        }

        Log::debug('Calculated date range for sync', [
            'account_id' => $account->id,
            'date_from' => $dateFrom->format('Y-m-d'),
            'date_to' => $dateTo->format('Y-m-d'),
        ]);

        return [
            'date_from' => $dateFrom->format('Y-m-d'),
            'date_to' => $dateTo->format('Y-m-d'),
        ];
    }
}
