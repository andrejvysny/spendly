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
use App\Models\User;
use App\Services\ExchangeRateService;
use App\Services\GoCardless\DTOs\DedupDecision;
use App\Services\MlSuggestionService;
use App\Services\TransferDetectionService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * @phpstan-type BatchStats array{created:int, updated:int, skipped:int, errors:int, needs_review:int, skipped_reasons:array<string,int>, earliest_unsynced_date:?string}
 * @phpstan-type SyncCandidate array{data:array<string,mixed>, transaction_id:string, has_provider_id:bool, validation_review:bool}
 */
class TransactionSyncService
{
    private const int BATCH_SIZE = 100;

    public function __construct(
        private readonly TransactionRepositoryInterface $transactionRepository,
        private readonly GocardlessMapper $mapper,
        private readonly TransferDetectionService $transferDetectionService,
        private readonly MlSuggestionService $mlSuggestionService,
        private readonly TransactionDataValidator $validator,
        private readonly GoCardlessSyncFailureRepositoryInterface $failureRepository,
        private readonly RuleEngineInterface $ruleEngine,
        private readonly TransactionDeduplicator $deduplicator
    ) {}

    /**
     * Sync transactions for an account.
     *
     * @param  bool  $updateExisting  Whether to update already imported transactions (default: true)
     * @return array Statistics about the sync, including `earliest_unsynced_date` — the
     *               earliest booked date (Y-m-d) among rows that failed this run, or null
     *               when there were no failures or none carried a parseable date. Callers
     *               use it to decide how far the sync watermark may safely advance.
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
            'skipped_reasons' => [],
            'earliest_unsynced_date' => null,
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
            $stats['needs_review'] += $batchStats['needs_review'];

            foreach ($batchStats['skipped_reasons'] as $reason => $count) {
                $stats['skipped_reasons'][$reason] = ($stats['skipped_reasons'][$reason] ?? 0) + $count;
            }

            $stats['earliest_unsynced_date'] = $this->earlierDate(
                $stats['earliest_unsynced_date'],
                $batchStats['earliest_unsynced_date']
            );
        }

        // Run transfer detection for this user so new same-day pairs are marked.
        // Windowed to the synced batch (with padding) instead of full history.
        if ($stats['created'] + $stats['updated'] > 0) {
            try {
                $padding = config('transfers.detection_window_padding_days', 3);
                $paddingDays = is_numeric($padding) ? (int) $padding : 3;
                $from = $this->earliestBookedDate($transactions)?->subDays($paddingDays);
                $this->transferDetectionService->detectAndMarkTransfersForUser((int) $account->user_id, $from);
            } catch (\Throwable $e) {
                Log::warning('Transfer detection after sync failed', [
                    'account_id' => $account->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $stats;
    }

    /**
     * Canonical re-processing path for GoCardless sync failure retries.
     *
     * Routes raw provider payloads back through the same pipeline a live sync uses —
     * native_amount conversion, dedup, rule engine, and ML annotation all apply — instead
     * of a retry command maintaining its own narrower write path that skips them.
     *
     * @param  array<int, array<string, mixed>>  $rawTransactions
     * @return array<string, mixed> Statistics about the sync
     */
    public function resyncRawTransactions(array $rawTransactions, Account $account): array
    {
        return $this->syncTransactions($rawTransactions, $account, updateExisting: true);
    }

    /**
     * Earlier of two Y-m-d date strings, treating null as "no date known".
     */
    private function earlierDate(?string $a, ?string $b): ?string
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }

        return $a <= $b ? $a : $b;
    }

    /**
     * Earliest booking date among the raw GoCardless transactions, used to
     * window the post-sync transfer detection run.
     *
     * @param  array<int, array<string, mixed>>  $transactions
     */
    private function earliestBookedDate(array $transactions): ?Carbon
    {
        $earliest = null;

        foreach ($transactions as $transaction) {
            $raw = $transaction['bookingDateTime'] ?? $transaction['bookingDate'] ?? null;
            if (! is_string($raw) || trim($raw) === '') {
                continue;
            }
            try {
                $date = Carbon::parse($raw);
            } catch (\Throwable) {
                continue;
            }
            if ($earliest === null || $date->lt($earliest)) {
                $earliest = $date;
            }
        }

        return $earliest;
    }

    /**
     * Process a batch of transactions.
     *
     * Runs in two passes: the first maps and validates every raw row, the second
     * resolves each mapped row against what is already stored. Splitting them lets
     * the existing-ID lookup use post-validation IDs (the validator synthesises an
     * ID for rows the provider left without one).
     *
     * @param  array<int, array<string, mixed>>  $batch
     * @param  bool  $updateExisting  Whether to update already imported transactions
     * @return BatchStats
     */
    private function processBatch(array $batch, Account $account, bool $updateExisting = true, ?Carbon $syncDate = null): array
    {
        $syncDate = $syncDate ?? Carbon::now();
        $accountId = (int) $account->id;
        $stats = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'needs_review' => 0,
            'skipped_reasons' => [],
            'earliest_unsynced_date' => null,
        ];

        // Pass 1: map + validate every raw row.
        $candidates = $this->prepareCandidates($batch, $account, $syncDate, $stats);

        // Single lookup over post-validation IDs so validator-generated fallback IDs
        // are matched against what is already stored.
        $existingIds = $this->transactionRepository->getExistingTransactionIds(
            $accountId,
            array_values(array_unique(array_filter(array_column($candidates, 'transaction_id'))))
        );

        // Pass 2: decide what happens to each mapped row.
        [$toCreate, $toUpdate] = $this->routeCandidates($candidates, $accountId, $existingIds, $updateExisting, $stats);

        // Perform batch operations
        $this->transactionRepository->transaction(function () use ($toCreate, $toUpdate, &$stats, $account) {
            // Batch create.
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

        $affectedTransactionIds = [];

        // Process rules on newly created transactions after the DB transaction commits.
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

                    $affectedTransactionIds = array_merge(
                        $affectedTransactionIds,
                        $createdTransactions->pluck('id')->all()
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('Rule processing after GoCardless sync failed', [
                    'account_id' => $account->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (! empty($toUpdate)) {
            $updatedTransactions = Transaction::query()
                ->where('account_id', $account->id)
                ->whereIn('transaction_id', array_keys($toUpdate))
                ->pluck('id')
                ->all();

            $affectedTransactionIds = array_merge($affectedTransactionIds, $updatedTransactions);
        }

        $affectedTransactionIds = array_values(array_unique(array_filter(array_map('intval', $affectedTransactionIds))));
        if ($affectedTransactionIds !== []) {
            try {
                $this->mlSuggestionService->annotateTransactions(
                    (int) $account->user_id,
                    $affectedTransactionIds,
                    'gocardless'
                );
            } catch (\Throwable $e) {
                Log::warning('ML suggestion annotation after GoCardless sync failed', [
                    'account_id' => $account->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $stats;
    }

    /**
     * Pass 1: map and validate raw provider rows. Rows that cannot be mapped or fail
     * validation are recorded as sync failures and dropped from the batch.
     *
     * @param  array<int, array<string, mixed>>  $batch
     * @param  BatchStats  $stats
     * @return list<SyncCandidate>
     */
    private function prepareCandidates(array $batch, Account $account, Carbon $syncDate, array &$stats): array
    {
        // Pre-fetch user's base currency (same account for all transactions in batch)
        $accountUser = User::find($account->user_id);
        $baseCurrency = $this->stringValue($accountUser instanceof User ? $accountUser->base_currency : null);
        if ($baseCurrency === '') {
            $baseCurrency = 'EUR';
        }

        $candidates = [];

        foreach ($batch as $transaction) {
            $externalTransactionId = $transaction['transactionId'] ?? null;

            try {
                $mappedData = $this->mapper->mapTransactionData($transaction, $account, $syncDate);

                // Capture provider-ID presence BEFORE validation: the validator
                // synthesises a `fallback_*` ID for rows the bank sent without one,
                // and such rows must not be trusted as uniquely identified.
                $hasProviderId = $this->stringValue($mappedData['transaction_id'] ?? null) !== '';

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
                    $this->trackFailureDate($stats, $transaction);

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
                $mappedData['native_amount'] = $this->resolveNativeAmount($mappedData, $baseCurrency, $syncDate);

                $candidates[] = [
                    'data' => $mappedData,
                    'transaction_id' => $this->stringValue($mappedData['transaction_id'] ?? null),
                    'has_provider_id' => $hasProviderId,
                    'validation_review' => $validation->needsReview,
                ];
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
                $this->trackFailureDate($stats, $transaction);
            }
        }

        return $candidates;
    }

    /**
     * Records the booked date of a row that just produced a failure record, so the
     * caller can learn how far back the sync watermark must be able to retry from.
     * Parsed from the raw payload (bookingDateTime, falling back to bookingDate) since
     * a failed row may not have made it through mapping. Unparsable/missing dates are
     * silently ignored — the watermark then stays conservative (not advanced at all).
     *
     * @param  BatchStats  $stats
     * @param  array<string, mixed>  $rawTransaction
     */
    private function trackFailureDate(array &$stats, array $rawTransaction): void
    {
        $date = $this->earliestBookedDate([$rawTransaction]);
        if ($date === null) {
            return;
        }

        $stats['earliest_unsynced_date'] = $this->earlierDate($stats['earliest_unsynced_date'], $date->format('Y-m-d'));
    }

    /**
     * Pass 2: resolve each mapped row into a create or an update, or drop it.
     *
     * @param  list<SyncCandidate>  $candidates
     * @param  Collection<int, string>  $existingIds
     * @param  BatchStats  $stats
     * @return array{0: list<array<string, mixed>>, 1: array<string, array<string, mixed>>}
     */
    private function routeCandidates(
        array $candidates,
        int $accountId,
        Collection $existingIds,
        bool $updateExisting,
        array &$stats
    ): array {
        $toCreate = [];
        $toUpdate = [];
        $seenTransactionIds = [];

        foreach ($candidates as $candidate) {
            $data = $candidate['data'];
            $transactionId = $candidate['transaction_id'];

            // The provider repeated the same ID inside one payload: only the first wins,
            // the second would be dropped by the (account_id, transaction_id) unique index.
            if ($transactionId !== '' && isset($seenTransactionIds[$transactionId])) {
                $this->recordSkip($stats, $accountId, $candidate, DedupDecision::REASON_DUPLICATE_IN_BATCH);

                continue;
            }
            $seenTransactionIds[$transactionId] = true;

            $decision = $this->deduplicator->decide(
                $accountId,
                $data,
                $candidate['has_provider_id'],
                $existingIds,
                $updateExisting
            );

            if ($decision->isSkip()) {
                $this->recordSkip($stats, $accountId, $candidate, $decision->reason);

                continue;
            }

            if ($decision->isUpdate()) {
                $target = (string) $decision->targetTransactionId;

                if (! isset($toUpdate[$target])) {
                    $toUpdate[$target] = $data;

                    continue;
                }

                // Two rows in this batch resolved onto the same stored row. Overwriting the
                // pending update would silently drop a real movement, so keep this one as a
                // new transaction and let a human confirm it.
                $decision = DedupDecision::create(DedupDecision::REASON_DUPLICATE_UPDATE_TARGET, true);
            }

            $toCreate[] = $this->prepareForCreate($data, $decision, $candidate['validation_review'], $stats);
        }

        return [$toCreate, $toUpdate];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  BatchStats  $stats
     * @return array<string, mixed>
     */
    private function prepareForCreate(array $data, DedupDecision $decision, bool $alreadyFlagged, array &$stats): array
    {
        if ($decision->needsReview) {
            $data['needs_manual_review'] = true;
            $data['review_reason'] = $this->appendReviewReason(
                is_string($data['review_reason'] ?? null) ? $data['review_reason'] : null,
                $decision->reason
            );

            if (! $alreadyFlagged) {
                $stats['needs_review']++;
            }
        }

        $data['created_at'] = now();
        $data['updated_at'] = now();

        return $data;
    }

    /**
     * Record a skipped row. Only identifiers are logged — never description or partner.
     *
     * @param  BatchStats  $stats
     * @param  SyncCandidate  $candidate
     */
    private function recordSkip(array &$stats, int $accountId, array $candidate, string $reason): void
    {
        $stats['skipped']++;
        $stats['skipped_reasons'][$reason] = ($stats['skipped_reasons'][$reason] ?? 0) + 1;

        Log::info('GoCardless sync skipped transaction', [
            'account_id' => $accountId,
            'transaction_id' => $candidate['transaction_id'],
            'reason' => $reason,
            'fingerprint' => $this->stringValue($candidate['data']['fingerprint'] ?? null),
        ]);
    }

    /**
     * Amount expressed in the user's base currency.
     *
     * @param  array<string, mixed>  $mappedData
     */
    private function resolveNativeAmount(array $mappedData, string $baseCurrency, Carbon $syncDate): float
    {
        if (isset($mappedData['native_amount']) && is_numeric($mappedData['native_amount'])) {
            return (float) $mappedData['native_amount'];
        }

        $amount = is_numeric($mappedData['amount'] ?? null) ? (float) $mappedData['amount'] : 0.0;
        $currency = $this->stringValue($mappedData['currency'] ?? null);

        if ($currency === '' || $currency === $baseCurrency) {
            return $amount;
        }

        $bookedDate = $mappedData['booked_date'] ?? null;
        if ($bookedDate instanceof Carbon) {
            $conversionDate = $bookedDate;
        } elseif (is_scalar($bookedDate) && trim((string) $bookedDate) !== '') {
            $conversionDate = Carbon::parse((string) $bookedDate);
        } else {
            $conversionDate = $syncDate;
        }

        return app(ExchangeRateService::class)->convert($amount, $currency, $baseCurrency, $conversionDate);
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
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
     * @return array{date_from: string, date_to: string}
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
