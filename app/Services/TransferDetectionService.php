<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Repositories\AccountRepositoryInterface;
use App\Contracts\Repositories\TransactionRepositoryInterface;
use App\Models\Transaction;
use App\Services\Transfers\AccountContext;
use App\Services\Transfers\CandidatePair;
use App\Services\Transfers\HeuristicScorer;
use App\Services\Transfers\PairEvaluator;
use App\Services\Transfers\PairMatcher;
use App\Services\Transfers\SingleLegDetector;
use App\Services\Transfers\TransferConfig;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Tiered transfer detection between a user's own accounts.
 *
 * Tier 1 (bidirectional IBAN) and tier 2 (one-sided IBAN) auto-mark. Tier 3
 * (no IBAN, heuristic score) auto-marks above the configured threshold and
 * review-flags in the band below it. Cross-currency candidates are
 * review-flagged by default. Single-leg internal moves (Revolut pockets)
 * are marked without a pair. See config/transfers.php for tunables.
 */
class TransferDetectionService
{
    public const string REVIEW_REASON_TRANSFER_CANDIDATE_UNPAIRED = 'transfer_candidate_unpaired';

    public const string REVIEW_REASON_TRANSFER_HEURISTIC = 'transfer_heuristic_review';

    public const string REVIEW_REASON_TRANSFER_AMBIGUOUS = 'transfer_ambiguous';

    public const string REVIEW_REASON_TRANSFER_CROSS_CURRENCY = 'transfer_cross_currency_candidate';

    public const string REVIEW_REASON_SINGLE_LEG_CANDIDATE = 'single_leg_transfer_candidate';

    private const array AUTO_REVIEW_REASONS = [
        self::REVIEW_REASON_TRANSFER_CANDIDATE_UNPAIRED,
        self::REVIEW_REASON_TRANSFER_HEURISTIC,
        self::REVIEW_REASON_TRANSFER_AMBIGUOUS,
        self::REVIEW_REASON_TRANSFER_CROSS_CURRENCY,
        self::REVIEW_REASON_SINGLE_LEG_CANDIDATE,
    ];

    public function __construct(
        private readonly AccountRepositoryInterface $accountRepository,
        private readonly TransactionRepositoryInterface $transactionRepository,
        private readonly ?MlService $mlService = null
    ) {}

    /**
     * Detect debit/credit pairs across the user's accounts and mark them as transfers.
     *
     * @return int Number of transactions updated (each pair counts as 2, single-leg moves as 1).
     */
    public function detectAndMarkTransfersForUser(int $userId, ?Carbon $from = null, ?Carbon $to = null): int
    {
        $config = TransferConfig::fromConfig();
        $accounts = $this->accountRepository->findByUser($userId);
        $accountIds = $accounts->pluck('id')->map(fn ($id): int => (int) $id)->all();

        if ($accountIds === []) {
            return 0;
        }

        $context = AccountContext::forUser($userId, $accounts);
        $transactions = $this->loadUnpairedTransactions($accountIds, $from, $to);
        $updated = 0;

        if (count($accountIds) >= 2) {
            $updated += $this->runPairPass($transactions, $context, $config);

            if ($config->crossCurrencyEnabled) {
                $updated += $this->runCrossCurrencyPass($transactions, $context, $config);
            }
        }

        $updated += $this->runSingleLegPass($transactions, $config);

        $this->markUnpairedTransferCandidatesForReview($transactions);

        return $updated;
    }

    /**
     * Hybrid detection: rule-based first, then ML fallback for remaining unpaired transactions.
     *
     * @return array{rule_matched: int, ml_matched: int}
     */
    public function detectTransfersWithMlFallback(int $userId, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $ruleMatched = $this->detectAndMarkTransfersForUser($userId, $from, $to);

        if ($this->mlService === null || ! $this->mlService->isAvailable()) {
            return ['rule_matched' => $ruleMatched, 'ml_matched' => 0];
        }

        $accounts = $this->accountRepository->findByUser($userId);
        $accountIds = $accounts->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $mlMatched = 0;

        if ($accountIds === []) {
            return ['rule_matched' => $ruleMatched, 'ml_matched' => 0];
        }

        $context = AccountContext::forUser($userId, $accounts);
        $evaluator = $this->makeEvaluator(TransferConfig::fromConfig());

        $predictions = $this->mlService->detectTransfers($userId, $from, $to);
        foreach ($predictions as $prediction) {
            if (! $prediction['is_transfer']) {
                continue;
            }

            if ($prediction['confidence'] < 0.60) {
                continue;
            }

            $transaction = $this->findUserTransaction(
                $prediction['transaction_id'],
                $accountIds,
                $from,
                $to
            );

            if ($transaction === null || $transaction->type === Transaction::TYPE_TRANSFER) {
                continue;
            }

            $pair = $this->findUserTransaction(
                (int) ($prediction['suggested_pair_id'] ?? 0),
                $accountIds,
                $from,
                $to
            );

            $candidate = $pair !== null
                && $pair->type !== Transaction::TYPE_TRANSFER
                && $pair->transfer_pair_transaction_id === null
                ? $this->evaluateOrdered($evaluator, $transaction, $pair, $context)
                : null;

            // ML suggestions still need hard IBAN evidence (tier 1 or 2) to auto-pair.
            if ($candidate !== null && $candidate->tier <= 2) {
                $this->transactionRepository->transaction(function () use ($candidate, &$mlMatched) {
                    $this->markPairAsTransfer($candidate);
                    $mlMatched += 2;
                });

                continue;
            }

            $this->markTransactionForManualReview($transaction, self::REVIEW_REASON_TRANSFER_CANDIDATE_UNPAIRED);
        }

        return ['rule_matched' => $ruleMatched, 'ml_matched' => $mlMatched];
    }

    /**
     * @param  array<int>  $accountIds
     * @return Collection<int, Transaction>
     */
    private function loadUnpairedTransactions(array $accountIds, ?Carbon $from, ?Carbon $to): Collection
    {
        $query = Transaction::query()
            ->whereIn('account_id', $accountIds)
            ->where('type', '!=', Transaction::TYPE_TRANSFER)
            ->whereNull('transfer_pair_transaction_id');

        if ($from !== null) {
            $query->where('booked_date', '>=', $from->copy()->startOfDay());
        }
        if ($to !== null) {
            $query->where('booked_date', '<=', $to->copy()->endOfDay());
        }

        return $query
            ->orderBy('booked_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * Same-currency tier 1-3 matching: auto-mark assigned pairs, flag ambiguous
     * ties and review-band heuristic suggestions.
     *
     * @param  Collection<int, Transaction>  $transactions
     */
    private function runPairPass(Collection $transactions, AccountContext $context, TransferConfig $config): int
    {
        $matcher = new PairMatcher($this->makeEvaluator($config), $config);

        $debits = $transactions->filter(fn (Transaction $t) => (float) $t->amount < 0)->values();
        $credits = $transactions->filter(fn (Transaction $t) => (float) $t->amount > 0)->values();

        ['assigned' => $assigned, 'ambiguous' => $ambiguous] = $matcher->match($debits, $credits, $context);

        $updated = 0;
        foreach ($assigned as $pair) {
            $this->transactionRepository->transaction(function () use ($pair, &$updated) {
                $this->markPairAsTransfer($pair);
                $updated += 2;
            });
        }

        foreach ($ambiguous as $pair) {
            $this->markTransactionForManualReview($pair->debit, self::REVIEW_REASON_TRANSFER_AMBIGUOUS);
            $this->markTransactionForManualReview($pair->credit, self::REVIEW_REASON_TRANSFER_AMBIGUOUS);
        }

        foreach ($matcher->reviewBandCandidates($debits, $credits, $context) as $suggestion) {
            $this->flagSuggestedPair($suggestion, self::REVIEW_REASON_TRANSFER_HEURISTIC);
        }

        return $updated;
    }

    /**
     * Different-currency candidates reconciled via exchange evidence. Auto-marks
     * only when explicitly enabled; review-flags with a suggested pair otherwise.
     *
     * @param  Collection<int, Transaction>  $transactions
     */
    private function runCrossCurrencyPass(Collection $transactions, AccountContext $context, TransferConfig $config): int
    {
        $evaluator = $this->makeEvaluator($config);

        $debits = $transactions->filter(fn (Transaction $t) => (float) $t->amount < 0 && ! $t->isTransfer())->values();
        $credits = $transactions->filter(fn (Transaction $t) => (float) $t->amount > 0 && ! $t->isTransfer())->values();

        $candidates = [];
        foreach ($debits as $debit) {
            foreach ($credits as $credit) {
                $candidate = $evaluator->evaluateCrossCurrency($debit, $credit, $context);
                if ($candidate !== null) {
                    $candidates[] = $candidate;
                }
            }
        }

        usort($candidates, fn (CandidatePair $a, CandidatePair $b) => [$a->dateGapDays, $a->amountDiff, $a->credit->id]
            <=> [$b->dateGapDays, $b->amountDiff, $b->credit->id]);

        $updated = 0;
        $usedIds = [];
        foreach ($candidates as $candidate) {
            if (isset($usedIds[$candidate->debit->id]) || isset($usedIds[$candidate->credit->id])) {
                continue;
            }
            $usedIds[$candidate->debit->id] = true;
            $usedIds[$candidate->credit->id] = true;

            if ($config->crossCurrencyAutoMark) {
                $this->transactionRepository->transaction(function () use ($candidate, &$updated) {
                    $this->markPairAsTransfer($candidate);
                    $updated += 2;
                });
            } else {
                $this->flagSuggestedPair($candidate, self::REVIEW_REASON_TRANSFER_CROSS_CURRENCY);
            }
        }

        return $updated;
    }

    /**
     * Internal moves with no possible pair leg (e.g. Revolut pocket/vault).
     * Runs even for single-account users.
     *
     * @param  Collection<int, Transaction>  $transactions
     */
    private function runSingleLegPass(Collection $transactions, TransferConfig $config): int
    {
        $detector = new SingleLegDetector($config);
        $updated = 0;

        foreach ($transactions as $transaction) {
            if ($transaction->isTransfer()) {
                continue;
            }
            if (! $detector->isSingleLegTransfer($transaction)) {
                continue;
            }

            if ($config->singleLegAutoMark) {
                $this->markSingleLegAsTransfer($transaction);
                $updated++;
            } else {
                $this->markTransactionForManualReview($transaction, self::REVIEW_REASON_SINGLE_LEG_CANDIDATE);
            }
        }

        return $updated;
    }

    private function makeEvaluator(TransferConfig $config): PairEvaluator
    {
        return new PairEvaluator($config, new HeuristicScorer);
    }

    private function evaluateOrdered(PairEvaluator $evaluator, Transaction $first, Transaction $second, AccountContext $context): ?CandidatePair
    {
        [$debit, $credit] = (float) $first->amount < 0
            ? [$first, $second]
            : [$second, $first];

        return $evaluator->evaluate($debit, $credit, $context);
    }

    /**
     * @param  array<int>  $accountIds
     */
    private function findUserTransaction(int $transactionId, array $accountIds, ?Carbon $from, ?Carbon $to): ?Transaction
    {
        if ($transactionId <= 0) {
            return null;
        }

        $transaction = Transaction::query()
            ->whereKey($transactionId)
            ->whereIn('account_id', $accountIds)
            ->first();

        if ($transaction === null) {
            return null;
        }

        if ($from !== null && $transaction->booked_date->lt($from->copy()->startOfDay())) {
            return null;
        }

        if ($to !== null && $transaction->booked_date->gt($to->copy()->endOfDay())) {
            return null;
        }

        return $transaction;
    }

    private function markPairAsTransfer(CandidatePair $pair): void
    {
        $debit = $pair->debit;
        $credit = $pair->credit;

        [$debitNeedsReview, $debitReviewReason] = $this->clearAutoReviewReasons($debit->review_reason);
        [$creditNeedsReview, $creditReviewReason] = $this->clearAutoReviewReasons($credit->review_reason);

        $debit->update([
            'type' => Transaction::TYPE_TRANSFER,
            'transfer_pair_transaction_id' => $credit->id,
            'needs_manual_review' => $debitNeedsReview,
            'review_reason' => $debitReviewReason,
            'metadata' => $this->withDetectionEvidence($debit, $pair, (int) $credit->id),
        ]);
        $credit->update([
            'type' => Transaction::TYPE_TRANSFER,
            'transfer_pair_transaction_id' => $debit->id,
            'needs_manual_review' => $creditNeedsReview,
            'review_reason' => $creditReviewReason,
            'metadata' => $this->withDetectionEvidence($credit, $pair, (int) $debit->id),
        ]);
    }

    private function markSingleLegAsTransfer(Transaction $transaction): void
    {
        [$needsReview, $reviewReason] = $this->clearAutoReviewReasons($transaction->review_reason);

        $metadata = is_array($transaction->metadata) ? $transaction->metadata : [];
        $metadata['single_leg_transfer'] = true;
        $metadata['transfer_detection'] = [
            'method' => CandidatePair::METHOD_SINGLE_LEG,
            'score' => null,
            'signals' => [],
            'pair_id' => null,
            'matched_at' => now()->toIso8601String(),
        ];

        $transaction->update([
            'type' => Transaction::TYPE_TRANSFER,
            'transfer_pair_transaction_id' => null,
            'needs_manual_review' => $needsReview,
            'review_reason' => $reviewReason,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Review-flag both legs of an unconfirmed suggestion, storing the suggested
     * counterpart id so the review UI (and a human) can reconstruct the pair.
     */
    private function flagSuggestedPair(CandidatePair $pair, string $reason): void
    {
        $this->storeSuggestedPairEvidence($pair->debit, $pair, (int) $pair->credit->id);
        $this->storeSuggestedPairEvidence($pair->credit, $pair, (int) $pair->debit->id);
        $this->markTransactionForManualReview($pair->debit, $reason);
        $this->markTransactionForManualReview($pair->credit, $reason);
    }

    private function storeSuggestedPairEvidence(Transaction $transaction, CandidatePair $pair, int $suggestedPairId): void
    {
        $metadata = is_array($transaction->metadata) ? $transaction->metadata : [];
        $existing = $metadata['transfer_detection'] ?? null;

        $evidence = [
            'method' => $pair->method,
            'score' => $pair->tier === 3 ? round($pair->score, 3) : null,
            'signals' => $pair->signals,
            'suggested_pair_id' => $suggestedPairId,
        ];

        if (is_array($existing) && ($existing['suggested_pair_id'] ?? null) === $suggestedPairId && ($existing['method'] ?? null) === $pair->method) {
            return;
        }

        $metadata['transfer_detection'] = $evidence + ['matched_at' => now()->toIso8601String()];
        $transaction->update(['metadata' => $metadata]);
    }

    /**
     * @return array<string, mixed>
     */
    private function withDetectionEvidence(Transaction $transaction, CandidatePair $pair, int $pairId): array
    {
        $metadata = is_array($transaction->metadata) ? $transaction->metadata : [];
        $metadata['transfer_detection'] = [
            'method' => $pair->method,
            'score' => $pair->tier === 3 ? round($pair->score, 3) : null,
            'signals' => $pair->signals,
            'pair_id' => $pairId,
            'matched_at' => now()->toIso8601String(),
        ];

        return $metadata;
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     */
    private function markUnpairedTransferCandidatesForReview(Collection $transactions): void
    {
        $transactions
            ->filter(function (Transaction $transaction) {
                return $transaction->type !== Transaction::TYPE_TRANSFER
                    && $transaction->transfer_pair_transaction_id === null
                    && $this->isTransferCandidate($transaction);
            })
            ->each(function (Transaction $transaction) {
                $this->markTransactionForManualReview($transaction, self::REVIEW_REASON_TRANSFER_CANDIDATE_UNPAIRED);
            });
    }

    private function isTransferCandidate(Transaction $transaction): bool
    {
        return is_array($transaction->metadata)
            && ($transaction->metadata['transfer_candidate'] ?? false) === true;
    }

    private function markTransactionForManualReview(Transaction $transaction, string $reason): void
    {
        $reviewReason = $this->appendReviewReason($transaction->review_reason, $reason);
        $needsManualReview = $reviewReason !== null;

        if ($transaction->needs_manual_review === $needsManualReview && $transaction->review_reason === $reviewReason) {
            return;
        }

        $transaction->update([
            'needs_manual_review' => $needsManualReview,
            'review_reason' => $reviewReason,
        ]);
    }

    private function appendReviewReason(?string $existingReasons, string $newReason): ?string
    {
        $reasons = $existingReasons !== null && trim($existingReasons) !== ''
            ? explode(',', $existingReasons)
            : [];

        $reasons[] = $newReason;
        $reasons = array_values(array_unique(array_filter(array_map('trim', $reasons))));

        return $reasons === [] ? null : implode(',', $reasons);
    }

    /**
     * Remove every detector-generated review reason (a leg being marked as a
     * transfer resolves all of its pending transfer suggestions).
     *
     * @return array{0: bool, 1: ?string}
     */
    private function clearAutoReviewReasons(?string $existingReasons): array
    {
        $reasons = $existingReasons !== null && trim($existingReasons) !== ''
            ? explode(',', $existingReasons)
            : [];

        $reasons = array_values(array_filter(array_map(
            static fn (string $reason) => trim($reason),
            $reasons
        ), static fn (string $reason) => $reason !== '' && ! in_array($reason, self::AUTO_REVIEW_REASONS, true)));

        $reviewReason = $reasons === [] ? null : implode(',', array_values(array_unique($reasons)));

        return [$reviewReason !== null, $reviewReason];
    }
}
