<?php

declare(strict_types=1);

namespace App\Services\Transfers;

use App\Models\Transaction;
use Illuminate\Support\Collection;

/**
 * Global best-first one-to-one assignment of debit/credit candidate pairs.
 * All valid candidates are generated, sorted by evidence strength, then swept
 * so the strongest evidence wins regardless of input order (unlike the old
 * per-debit greedy matching).
 */
final class PairMatcher
{
    public function __construct(
        private readonly PairEvaluator $evaluator,
        private readonly TransferConfig $config,
    ) {}

    /**
     * @param  Collection<int, Transaction>  $debits
     * @param  Collection<int, Transaction>  $credits
     * @return array{assigned: list<CandidatePair>, ambiguous: list<CandidatePair>}
     */
    public function match(Collection $debits, Collection $credits, AccountContext $context): array
    {
        $candidates = $this->generateCandidates($debits, $credits, $context);

        usort($candidates, function (CandidatePair $a, CandidatePair $b): int {
            return [$a->tier, -$a->score, $a->dateGapDays, $a->amountDiff, $a->credit->id, $a->debit->id]
                <=> [$b->tier, -$b->score, $b->dateGapDays, $b->amountDiff, $b->credit->id, $b->debit->id];
        });

        $assigned = [];
        $ambiguous = [];
        $usedDebitIds = [];
        $usedCreditIds = [];

        foreach ($candidates as $index => $candidate) {
            if (isset($usedDebitIds[$candidate->debit->id]) || isset($usedCreditIds[$candidate->credit->id])) {
                continue;
            }

            if ($candidate->tier === 3) {
                $twins = $this->findAmbiguousTwins($candidates, $index, $usedDebitIds, $usedCreditIds);
                if ($twins !== []) {
                    // Ties between equally-plausible heuristic pairings are not
                    // guessed - every involved leg is blocked and flagged instead.
                    foreach ([$candidate, ...$twins] as $tied) {
                        $ambiguous[] = $tied;
                        $usedDebitIds[$tied->debit->id] = true;
                        $usedCreditIds[$tied->credit->id] = true;
                    }

                    continue;
                }

                if ($candidate->score < $this->config->heuristicAutoThreshold) {
                    // Review-band candidate: leave unassigned; the orchestrator
                    // flags it for manual review.
                    continue;
                }
            }

            $assigned[] = $candidate;
            $usedDebitIds[$candidate->debit->id] = true;
            $usedCreditIds[$candidate->credit->id] = true;
        }

        return ['assigned' => $assigned, 'ambiguous' => $ambiguous];
    }

    /**
     * Review-band heuristic candidates (score below auto threshold) among the
     * given collections, excluding legs already paired. Used by the
     * orchestrator to flag suggestions after assignment.
     *
     * @param  Collection<int, Transaction>  $debits
     * @param  Collection<int, Transaction>  $credits
     * @return list<CandidatePair>
     */
    public function reviewBandCandidates(Collection $debits, Collection $credits, AccountContext $context): array
    {
        $unpairedDebits = $debits->filter(fn (Transaction $t) => $t->transfer_pair_transaction_id === null && $t->type !== Transaction::TYPE_TRANSFER);
        $unpairedCredits = $credits->filter(fn (Transaction $t) => $t->transfer_pair_transaction_id === null && $t->type !== Transaction::TYPE_TRANSFER);

        $candidates = array_filter(
            $this->generateCandidates($unpairedDebits, $unpairedCredits, $context),
            fn (CandidatePair $c) => $c->tier === 3 && $c->score < $this->config->heuristicAutoThreshold
        );

        usort($candidates, fn (CandidatePair $a, CandidatePair $b) => $b->score <=> $a->score);

        // One suggestion per leg: strongest candidate wins.
        $seen = [];
        $result = [];
        foreach ($candidates as $candidate) {
            if (isset($seen['d'.$candidate->debit->id]) || isset($seen['c'.$candidate->credit->id])) {
                continue;
            }
            $seen['d'.$candidate->debit->id] = true;
            $seen['c'.$candidate->credit->id] = true;
            $result[] = $candidate;
        }

        return $result;
    }

    /**
     * @param  Collection<int, Transaction>  $debits
     * @param  Collection<int, Transaction>  $credits
     * @return list<CandidatePair>
     */
    private function generateCandidates(Collection $debits, Collection $credits, AccountContext $context): array
    {
        $buckets = $this->bucketByCents($credits);
        $candidates = [];

        foreach ($debits as $debit) {
            $debitAbs = abs((float) $debit->amount);
            $toleranceCents = (int) ceil($this->config->amountToleranceFor($debitAbs) * 100);
            $centerCents = (int) round($debitAbs * 100);

            for ($cents = $centerCents - $toleranceCents; $cents <= $centerCents + $toleranceCents; $cents++) {
                foreach ($buckets[$cents] ?? [] as $credit) {
                    $candidate = $this->evaluator->evaluate($debit, $credit, $context);
                    if ($candidate !== null) {
                        $candidates[] = $candidate;
                    }
                }
            }
        }

        return $candidates;
    }

    /**
     * Other not-yet-used tier-3 candidates sharing a leg with the candidate at
     * $index and carrying identical evidence (score, date gap, amount diff).
     *
     * @param  list<CandidatePair>  $candidates
     * @param  array<int, true>  $usedDebitIds
     * @param  array<int, true>  $usedCreditIds
     * @return list<CandidatePair>
     */
    private function findAmbiguousTwins(array $candidates, int $index, array $usedDebitIds, array $usedCreditIds): array
    {
        $candidate = $candidates[$index];
        $twins = [];

        foreach ($candidates as $otherIndex => $other) {
            if ($otherIndex === $index || $other->tier !== 3) {
                continue;
            }
            if (isset($usedDebitIds[$other->debit->id]) || isset($usedCreditIds[$other->credit->id])) {
                continue;
            }
            $sharesLeg = $other->debit->id === $candidate->debit->id || $other->credit->id === $candidate->credit->id;
            if (! $sharesLeg) {
                continue;
            }
            if (round($other->score, 3) === round($candidate->score, 3)
                && $other->dateGapDays === $candidate->dateGapDays
                && round($other->amountDiff, 2) === round($candidate->amountDiff, 2)
            ) {
                $twins[] = $other;
            }
        }

        return $twins;
    }

    /**
     * @param  Collection<int, Transaction>  $credits
     * @return array<int, list<Transaction>>
     */
    private function bucketByCents(Collection $credits): array
    {
        $buckets = [];

        foreach ($credits as $credit) {
            $cents = (int) round(abs((float) $credit->amount) * 100);
            $buckets[$cents][] = $credit;
        }

        return $buckets;
    }
}
