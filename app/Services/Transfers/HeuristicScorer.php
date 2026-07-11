<?php

declare(strict_types=1);

namespace App\Services\Transfers;

use App\Models\Transaction;

/**
 * Scores a no-IBAN debit/credit candidate pair using soft signals
 * (import-time transfer flags, own-name / counterparty-account name matches,
 * transfer-ish type codes, date proximity, exact amounts). Additive, capped at 1.0.
 */
final class HeuristicScorer
{
    private const array TRANSFER_TYPE_KEYWORDS = ['TRANSFER', 'TOPUP', 'STANDINGORDER'];

    /**
     * @return array{score: float, signals: list<string>}
     */
    public function score(Transaction $debit, Transaction $credit, AccountContext $context, int $dateGapDays, float $amountDiff): array
    {
        $score = 0.0;
        $signals = [];

        $debitCandidate = $this->hasTransferCandidateFlag($debit);
        $creditCandidate = $this->hasTransferCandidateFlag($credit);
        if ($debitCandidate && $creditCandidate) {
            $score += 0.35;
            $signals[] = 'both_transfer_candidates';
        } elseif ($debitCandidate || $creditCandidate) {
            $score += 0.20;
            $signals[] = 'one_transfer_candidate';
        }

        if ($this->matchesOwnName($debit, $context) || $this->matchesOwnName($credit, $context)) {
            $score += 0.20;
            $signals[] = 'own_name_match';
        }

        $debitMatchesCreditAccount = $this->mentionsAccount($debit, (int) $credit->account_id, $context);
        $creditMatchesDebitAccount = $this->mentionsAccount($credit, (int) $debit->account_id, $context);
        if ($debitMatchesCreditAccount || $creditMatchesDebitAccount) {
            $score += 0.20;
            $signals[] = 'counterparty_account_match';
        }

        $typeScore = 0.0;
        if ($this->hasTransferishType($debit)) {
            $typeScore += 0.10;
            $signals[] = 'transfer_type_debit';
        }
        if ($this->hasTransferishType($credit)) {
            $typeScore += 0.10;
            $signals[] = 'transfer_type_credit';
        }
        $score += min($typeScore, 0.20);

        if ($dateGapDays === 0) {
            $score += 0.10;
            $signals[] = 'same_day';
        } elseif ($dateGapDays === 1) {
            $score += 0.05;
            $signals[] = 'next_day';
        }

        if ($amountDiff < 0.005) {
            $score += 0.05;
            $signals[] = 'exact_amount';
        }

        return ['score' => min($score, 1.0), 'signals' => $signals];
    }

    private function hasTransferCandidateFlag(Transaction $transaction): bool
    {
        return is_array($transaction->metadata)
            && ($transaction->metadata['transfer_candidate'] ?? false) === true;
    }

    private function matchesOwnName(Transaction $transaction, AccountContext $context): bool
    {
        if ($context->normalizedUserName === '') {
            return false;
        }

        return str_contains($this->haystack($transaction), $context->normalizedUserName);
    }

    private function mentionsAccount(Transaction $transaction, int $otherAccountId, AccountContext $context): bool
    {
        $haystack = $this->haystack($transaction);
        if ($haystack === '') {
            return false;
        }

        foreach ([$context->accountIdToName[$otherAccountId] ?? '', $context->accountIdToBankName[$otherAccountId] ?? ''] as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function hasTransferishType(Transaction $transaction): bool
    {
        $metadata = is_array($transaction->metadata) ? $transaction->metadata : [];
        if (($metadata['transfer_type_hint'] ?? false) === true) {
            return true;
        }

        $candidates = [
            (string) ($transaction->type ?? ''),
            (string) ($transaction->bank_transaction_code ?? ''),
            (string) ($metadata['proprietaryBankTransactionCode'] ?? ''),
        ];

        foreach ($candidates as $candidate) {
            $folded = AccountContext::fold($candidate);
            if ($folded === '') {
                continue;
            }
            foreach (self::TRANSFER_TYPE_KEYWORDS as $keyword) {
                if (str_contains($folded, $keyword)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function haystack(Transaction $transaction): string
    {
        return AccountContext::fold(
            (string) ($transaction->partner ?? '').' '.(string) ($transaction->description ?? '')
        );
    }
}
