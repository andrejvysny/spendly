<?php

declare(strict_types=1);

namespace App\Services\Transfers;

use App\Models\Transaction;

/**
 * Decides whether a (debit, credit) combination qualifies as a transfer pair
 * and at which evidence tier:
 *
 *  - Tier 1: bidirectional IBAN cross-match (debit's target IBAN is the credit
 *    account's own IBAN AND credit's source IBAN is the debit account's own IBAN).
 *  - Tier 2: exactly one of those directional links is verifiable, the other
 *    side carries no contradicting evidence.
 *  - Tier 3: no IBAN evidence at all; soft signals scored by HeuristicScorer.
 *  - Tier 4 (cross-currency): different currencies reconciled via bank
 *    exchange metadata or native amounts.
 *
 * A present-but-mismatching counterparty IBAN on either side is a contradiction
 * (the money verifiably went elsewhere) and disqualifies the pair entirely.
 */
final class PairEvaluator
{
    public function __construct(
        private readonly TransferConfig $config,
        private readonly HeuristicScorer $scorer,
    ) {}

    public function evaluate(Transaction $debit, Transaction $credit, AccountContext $context): ?CandidatePair
    {
        if (! $this->passesBaseGates($debit, $credit)) {
            return null;
        }

        $dateGap = $this->dateGapInDays($debit, $credit);
        $amountDiff = abs(abs((float) $debit->amount) - abs((float) $credit->amount));

        $debitTargetIban = Iban::normalize($debit->target_iban);
        $creditSourceIban = Iban::normalize($credit->source_iban);
        $debitAccountIban = $context->ibanFor((int) $debit->account_id);
        $creditAccountIban = $context->ibanFor((int) $credit->account_id);

        $linkToCredit = $debitTargetIban !== null && $creditAccountIban !== null && $debitTargetIban === $creditAccountIban;
        $linkFromDebit = $creditSourceIban !== null && $debitAccountIban !== null && $creditSourceIban === $debitAccountIban;

        $contradiction = ($debitTargetIban !== null && $creditAccountIban !== null && $debitTargetIban !== $creditAccountIban)
            || ($creditSourceIban !== null && $debitAccountIban !== null && $creditSourceIban !== $debitAccountIban);

        if ($contradiction) {
            return null;
        }

        if ($linkToCredit && $linkFromDebit) {
            return new CandidatePair($debit, $credit, 1, CandidatePair::METHOD_IBAN_BIDIRECTIONAL, 1.0, $dateGap, $amountDiff);
        }

        if ($linkToCredit || $linkFromDebit) {
            return new CandidatePair($debit, $credit, 2, CandidatePair::METHOD_IBAN_ONE_SIDED, 1.0, $dateGap, $amountDiff);
        }

        $result = $this->scorer->score($debit, $credit, $context, $dateGap, $amountDiff);
        if ($result['score'] >= $this->config->heuristicReviewThreshold) {
            return new CandidatePair($debit, $credit, 3, CandidatePair::METHOD_HEURISTIC, $result['score'], $dateGap, $amountDiff, $result['signals']);
        }

        return null;
    }

    /**
     * Cross-currency gate: opposite signs, different accounts, different
     * currencies, date window, and amounts that reconcile via exchange
     * evidence within the configured tolerance.
     */
    public function evaluateCrossCurrency(Transaction $debit, Transaction $credit, AccountContext $context): ?CandidatePair
    {
        if ((float) $debit->amount >= 0 || (float) $credit->amount <= 0) {
            return null;
        }
        if ($credit->account_id === $debit->account_id) {
            return null;
        }
        if ((string) $credit->currency === (string) $debit->currency) {
            return null;
        }

        $dateGap = $this->dateGapInDays($debit, $credit);
        if ($dateGap > $this->config->dateGapDays) {
            return null;
        }

        $debitTargetIban = Iban::normalize($debit->target_iban);
        $creditSourceIban = Iban::normalize($credit->source_iban);
        $debitAccountIban = $context->ibanFor((int) $debit->account_id);
        $creditAccountIban = $context->ibanFor((int) $credit->account_id);
        $contradiction = ($debitTargetIban !== null && $creditAccountIban !== null && $debitTargetIban !== $creditAccountIban)
            || ($creditSourceIban !== null && $debitAccountIban !== null && $creditSourceIban !== $debitAccountIban);
        if ($contradiction) {
            return null;
        }

        if (! $this->amountsReconcileAcrossCurrencies($debit, $credit)) {
            return null;
        }

        $amountDiff = abs(abs((float) $debit->amount) - abs((float) $credit->amount));

        return new CandidatePair($debit, $credit, 4, CandidatePair::METHOD_CROSS_CURRENCY, 1.0, $dateGap, $amountDiff);
    }

    private function passesBaseGates(Transaction $debit, Transaction $credit): bool
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

        if ($this->dateGapInDays($debit, $credit) > $this->config->dateGapDays) {
            return false;
        }

        $tolerance = $this->config->amountToleranceFor((float) abs((float) $debit->amount));

        return abs(abs((float) $debit->amount) - abs((float) $credit->amount)) <= $tolerance;
    }

    private function amountsReconcileAcrossCurrencies(Transaction $debit, Transaction $credit): bool
    {
        $debitAbs = abs((float) $debit->amount);
        $creditAbs = abs((float) $credit->amount);
        $tolerance = $this->config->crossCurrencyTolerancePercent / 100.0;

        // Bank-provided instructed amount: the credit leg often records the
        // originally instructed amount in the debit leg's currency (and vice versa).
        foreach ([[$credit, $debit], [$debit, $credit]] as [$leg, $other]) {
            $originalCurrency = (string) ($leg->original_currency ?? '');
            $originalAmount = $leg->original_amount !== null ? abs((float) $leg->original_amount) : null;
            if ($originalAmount !== null && $originalAmount > 0.0 && $originalCurrency === (string) $other->currency) {
                if ($this->withinRelativeTolerance($originalAmount, abs((float) $other->amount), $tolerance)) {
                    return true;
                }
            }
        }

        // Bank-provided exchange rate (direction is ambiguous across providers - try both).
        foreach ([$debit, $credit] as $leg) {
            $rate = $leg->exchange_rate !== null ? (float) $leg->exchange_rate : null;
            if ($rate !== null && $rate > 0.0) {
                if ($this->withinRelativeTolerance($debitAbs * $rate, $creditAbs, $tolerance)
                    || $this->withinRelativeTolerance($debitAbs / $rate, $creditAbs, $tolerance)) {
                    return true;
                }
            }
        }

        // Fallback: both legs converted to the user's base currency at import time.
        $debitNative = $debit->native_amount !== null ? abs((float) $debit->native_amount) : null;
        $creditNative = $credit->native_amount !== null ? abs((float) $credit->native_amount) : null;
        if ($debitNative !== null && $creditNative !== null && $debitNative > 0.0) {
            return $this->withinRelativeTolerance($debitNative, $creditNative, $tolerance);
        }

        return false;
    }

    private function withinRelativeTolerance(float $expected, float $actual, float $tolerance): bool
    {
        if ($expected <= 0.0) {
            return false;
        }

        return abs($expected - $actual) / $expected <= $tolerance;
    }

    private function dateGapInDays(Transaction $first, Transaction $second): int
    {
        return (int) abs($first->booked_date->copy()->startOfDay()->diffInDays($second->booked_date->copy()->startOfDay()));
    }
}
