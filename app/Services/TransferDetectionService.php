<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Repositories\AccountRepositoryInterface;
use App\Contracts\Repositories\TransactionRepositoryInterface;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class TransferDetectionService
{
    /** Amount tolerance for matching (e.g. rounding differences). */
    private const float AMOUNT_TOLERANCE = 0.01;

    private const int MAX_BOOKED_DATE_GAP_DAYS = 1;

    public const string REVIEW_REASON_TRANSFER_CANDIDATE_UNPAIRED = 'transfer_candidate_unpaired';

    public function __construct(
        private readonly AccountRepositoryInterface $accountRepository,
        private readonly TransactionRepositoryInterface $transactionRepository,
        private readonly ?MlService $mlService = null
    ) {}

    /**
     * Detect debit/credit pairs across the user's accounts and mark them as transfers.
     *
     * @return int Number of transactions updated (each pair counts as 2).
     */
    public function detectAndMarkTransfersForUser(int $userId, ?Carbon $from = null, ?Carbon $to = null): int
    {
        $accounts = $this->accountRepository->findByUser($userId);
        $accountIds = $accounts->pluck('id')->all();
        $accountIdToIban = $this->buildAccountIdToIbanMap($accounts);

        if (count($accountIds) < 2) {
            return 0;
        }

        $transactions = Transaction::query()
            ->whereIn('account_id', $accountIds)
            ->where('type', '!=', Transaction::TYPE_TRANSFER)
            ->whereNull('transfer_pair_transaction_id')
            ->when($from !== null, fn ($query) => $query->where('booked_date', '>=', $from->copy()->startOfDay()))
            ->when($to !== null, fn ($query) => $query->where('booked_date', '<=', $to->copy()->endOfDay()))
            ->orderBy('booked_date')
            ->orderBy('id')
            ->get();

        $debits = $transactions
            ->filter(fn (Transaction $transaction) => (float) $transaction->amount < 0)
            ->values();
        $credits = $transactions
            ->filter(fn (Transaction $transaction) => (float) $transaction->amount > 0)
            ->values();

        $usedCreditIds = [];
        $updated = 0;

        foreach ($debits as $debit) {
            $match = $this->findMatchingCredit($debit, $credits, $usedCreditIds, $accountIdToIban);
            if ($match === null) {
                continue;
            }

            $this->transactionRepository->transaction(function () use ($debit, $match, &$updated) {
                $this->markPairAsTransfer($debit, $match);
                $updated += 2;
            });

            $usedCreditIds[] = $match->id;
        }

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
        $accountIds = $accounts->pluck('id')->all();
        $accountIdToIban = $this->buildAccountIdToIbanMap($accounts);
        $mlMatched = 0;

        if ($accountIds === []) {
            return ['rule_matched' => $ruleMatched, 'ml_matched' => 0];
        }

        $predictions = $this->mlService->detectTransfers($userId, $from, $to);
        foreach ($predictions as $prediction) {
            if (! ($prediction['is_transfer'] ?? false)) {
                continue;
            }

            if (($prediction['confidence'] ?? 0) < 0.60) {
                continue;
            }

            $transaction = $this->findUserTransaction(
                (int) ($prediction['transaction_id'] ?? 0),
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

            if ($pair !== null
                && $pair->type !== Transaction::TYPE_TRANSFER
                && $pair->transfer_pair_transaction_id === null
                && $this->pairMatchesTransferRules($transaction, $pair, $accountIdToIban)
            ) {
                $this->transactionRepository->transaction(function () use ($transaction, $pair, &$mlMatched) {
                    $this->markPairAsTransfer($transaction, $pair);
                    $mlMatched += 2;
                });

                continue;
            }

            $this->markTransactionForManualReview($transaction, self::REVIEW_REASON_TRANSFER_CANDIDATE_UNPAIRED);
        }

        return ['rule_matched' => $ruleMatched, 'ml_matched' => $mlMatched];
    }

    /**
     * @param  Collection<int, Transaction>  $credits
     * @param  array<int>  $usedCreditIds
     * @param  array<int, string>  $accountIdToIban
     */
    private function findMatchingCredit(Transaction $debit, Collection $credits, array $usedCreditIds, array $accountIdToIban): ?Transaction
    {
        $bestMatch = null;
        $bestDayGap = null;
        $bestAmountDiff = null;

        foreach ($credits as $credit) {
            if (in_array($credit->id, $usedCreditIds, true)) {
                continue;
            }

            if (! $this->isValidRuleBasedPair($debit, $credit, $accountIdToIban)) {
                continue;
            }

            $dayGap = $this->bookedDateGapInDays($debit, $credit);
            $amountDiff = abs(abs((float) $debit->amount) - abs((float) $credit->amount));

            if ($bestMatch === null
                || $dayGap < $bestDayGap
                || ($dayGap === $bestDayGap && $amountDiff < $bestAmountDiff)
                || ($dayGap === $bestDayGap && $amountDiff === $bestAmountDiff && $credit->id < $bestMatch->id)
            ) {
                $bestMatch = $credit;
                $bestDayGap = $dayGap;
                $bestAmountDiff = $amountDiff;
            }
        }

        return $bestMatch;
    }

    /**
     * @param  array<int, string>  $accountIdToIban
     */
    private function isValidRuleBasedPair(Transaction $debit, Transaction $credit, array $accountIdToIban): bool
    {
        if ((float) $debit->amount >= 0 || (float) $credit->amount <= 0) {
            return false;
        }

        if ($credit->account_id === $debit->account_id) {
            return false;
        }

        if ((string) $credit->currency !== (string) $debit->currency) {
            return false;
        }

        if ($this->bookedDateGapInDays($debit, $credit) > self::MAX_BOOKED_DATE_GAP_DAYS) {
            return false;
        }

        if (abs(abs((float) $debit->amount) - abs((float) $credit->amount)) > self::AMOUNT_TOLERANCE) {
            return false;
        }

        $debitAccountIban = $accountIdToIban[$debit->account_id] ?? null;
        $creditAccountIban = $accountIdToIban[$credit->account_id] ?? null;
        $debitTargetIban = $this->normalizeIbanNullable($debit->target_iban);
        $creditSourceIban = $this->normalizeIbanNullable($credit->source_iban);

        if ($debitAccountIban === null || $creditAccountIban === null || $debitTargetIban === null || $creditSourceIban === null) {
            return false;
        }

        return $debitTargetIban === $creditAccountIban
            && $creditSourceIban === $debitAccountIban;
    }

    /**
     * @param  array<int, string>  $accountIdToIban
     */
    private function pairMatchesTransferRules(Transaction $first, Transaction $second, array $accountIdToIban): bool
    {
        [$debit, $credit] = (float) $first->amount < 0
            ? [$first, $second]
            : [$second, $first];

        return $this->isValidRuleBasedPair($debit, $credit, $accountIdToIban);
    }

    private function bookedDateGapInDays(Transaction $first, Transaction $second): int
    {
        return (int) abs($first->booked_date->copy()->startOfDay()->diffInDays($second->booked_date->copy()->startOfDay()));
    }

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

    private function markPairAsTransfer(Transaction $first, Transaction $second): void
    {
        [$debit, $credit] = (float) $first->amount < 0
            ? [$first, $second]
            : [$second, $first];

        [$debitNeedsReview, $debitReviewReason] = $this->removeReviewReason(
            $debit->review_reason,
            self::REVIEW_REASON_TRANSFER_CANDIDATE_UNPAIRED
        );
        [$creditNeedsReview, $creditReviewReason] = $this->removeReviewReason(
            $credit->review_reason,
            self::REVIEW_REASON_TRANSFER_CANDIDATE_UNPAIRED
        );

        $debit->update([
            'type' => Transaction::TYPE_TRANSFER,
            'transfer_pair_transaction_id' => $credit->id,
            'needs_manual_review' => $debitNeedsReview,
            'review_reason' => $debitReviewReason,
        ]);
        $credit->update([
            'type' => Transaction::TYPE_TRANSFER,
            'transfer_pair_transaction_id' => $debit->id,
            'needs_manual_review' => $creditNeedsReview,
            'review_reason' => $creditReviewReason,
        ]);
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
     * @return array{0: bool, 1: ?string}
     */
    private function removeReviewReason(?string $existingReasons, string $reasonToRemove): array
    {
        $reasons = $existingReasons !== null && trim($existingReasons) !== ''
            ? explode(',', $existingReasons)
            : [];

        $reasons = array_values(array_filter(array_map(
            static fn (string $reason) => trim($reason),
            $reasons
        ), static fn (string $reason) => $reason !== '' && $reason !== $reasonToRemove));

        $reviewReason = $reasons === [] ? null : implode(',', array_values(array_unique($reasons)));

        return [$reviewReason !== null, $reviewReason];
    }

    /**
     * @param  Collection<int, \App\Models\Account>  $accounts
     * @return array<int, string>
     */
    private function buildAccountIdToIbanMap(Collection $accounts): array
    {
        $map = [];

        foreach ($accounts as $account) {
            $iban = $account->iban;
            if ($iban !== null && trim((string) $iban) !== '') {
                $map[$account->id] = $this->normalizeIban((string) $iban);
            }
        }

        return $map;
    }

    private function normalizeIban(string $iban): string
    {
        return strtoupper(trim((string) preg_replace('/\s+/', '', $iban)));
    }

    private function normalizeIbanNullable(?string $iban): ?string
    {
        if ($iban === null || trim($iban) === '') {
            return null;
        }

        return $this->normalizeIban($iban);
    }
}
