<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\Repositories\AccountRepositoryInterface;
use App\Models\Account;
use App\Models\Transaction;
use App\Services\Transfers\CandidatePair;
use App\Services\Transfers\Iban;
use App\Services\Transfers\TransferConfig;
use Illuminate\Console\Command;

/**
 * Repairs transfers whose evidence no longer holds. Method-aware: pairs are
 * rechecked against the rule that created them (recorded in
 * metadata.transfer_detection.method); single-leg and manual marks are never
 * touched, heuristic/cross-currency pairs only with --include-heuristic and
 * only on hard invariant violations.
 */
class TransferFixIncorrectCommand extends Command
{
    protected $signature = 'transfers:fix-incorrect
                            {--dry-run : List what would be changed without updating}
                            {--user= : Run for a specific user ID only}
                            {--fix-pairs : Also unpair and reclassify paired TRANSFERs that fail their method\'s recheck}
                            {--include-heuristic : Also recheck heuristic/cross-currency pairs (hard invariants only)}';

    protected $description = 'Reclassify TRANSFER transactions whose detection evidence no longer holds (unpaired legs, broken IBAN pairs)';

    public function handle(AccountRepositoryInterface $accountRepository): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $userIdOpt = $this->option('user') !== null ? (string) $this->option('user') : null;
        $fixPairs = (bool) $this->option('fix-pairs');

        $total = 0;

        $total += $this->fixUnpairedTransfers($accountRepository, $dryRun, $userIdOpt);

        if ($fixPairs) {
            $total += $this->fixIncorrectlyPairedTransfers($accountRepository, $dryRun, $userIdOpt);
        }

        if ($dryRun) {
            $this->info("Dry run: {$total} transaction(s) would be reclassified.");
        } else {
            $this->info("Reclassified {$total} transaction(s).");
        }

        return self::SUCCESS;
    }

    private function fixUnpairedTransfers(AccountRepositoryInterface $accountRepository, bool $dryRun, ?string $userIdOpt): int
    {
        $query = Transaction::query()
            ->where('type', Transaction::TYPE_TRANSFER)
            ->whereNull('transfer_pair_transaction_id')
            ->with('account');

        if ($userIdOpt !== null) {
            $query->whereHas('account', fn ($q) => $q->where('user_id', (int) $userIdOpt));
        }

        $transactions = $query->get();
        $updated = 0;

        foreach ($transactions as $transaction) {
            $account = $transaction->account;
            if (! $account instanceof Account) {
                continue;
            }
            if ($this->isProtectedFromUnpairedFix($transaction)) {
                continue;
            }
            $userId = (int) $account->user_id;
            $ownIbans = $this->getOwnIbansNormalized($accountRepository, $userId);
            $counterpartyIban = $transaction->amount < 0
                ? $transaction->target_iban
                : $transaction->source_iban;
            $counterpartyNorm = Iban::normalize($counterpartyIban);
            if ($counterpartyNorm !== null && isset($ownIbans[$counterpartyNorm])) {
                continue;
            }
            $newType = (float) $transaction->amount < 0 ? Transaction::TYPE_PAYMENT : Transaction::TYPE_DEPOSIT;
            if ($dryRun) {
                $this->line("Would update transaction id={$transaction->id} ({$transaction->transaction_id}) type TRANSFER -> {$newType}");
                $updated++;

                continue;
            }
            $transaction->update(['type' => $newType]);
            $updated++;
        }

        return $updated;
    }

    /**
     * Single-leg (pocket/vault) and manually marked transfers are legitimate
     * without a pair or counterparty IBAN - never reclassify them.
     */
    private function isProtectedFromUnpairedFix(Transaction $transaction): bool
    {
        $metadata = is_array($transaction->metadata) ? $transaction->metadata : [];
        if (($metadata['single_leg_transfer'] ?? false) === true) {
            return true;
        }

        $method = $metadata['transfer_detection']['method'] ?? null;

        return in_array($method, [CandidatePair::METHOD_SINGLE_LEG, CandidatePair::METHOD_MANUAL], true);
    }

    private function fixIncorrectlyPairedTransfers(AccountRepositoryInterface $accountRepository, bool $dryRun, ?string $userIdOpt): int
    {
        $includeHeuristic = (bool) $this->option('include-heuristic');
        $config = TransferConfig::fromConfig();

        $query = Transaction::query()
            ->where('type', Transaction::TYPE_TRANSFER)
            ->whereNotNull('transfer_pair_transaction_id')
            ->with(['account', 'pairTransaction.account']);

        if ($userIdOpt !== null) {
            $query->whereHas('account', fn ($q) => $q->where('user_id', (int) $userIdOpt));
        }

        $transactions = $query->get();
        $updated = 0;
        $processedIds = [];

        foreach ($transactions as $transaction) {
            if (in_array($transaction->id, $processedIds, true)) {
                continue;
            }
            $pair = $transaction->pairTransaction;
            if (! $pair instanceof Transaction) {
                continue;
            }
            $account = $transaction->account;
            $pairAccount = $pair->account;
            if (! $account instanceof Account || ! $pairAccount instanceof Account) {
                continue;
            }

            $debit = (float) $transaction->amount < 0 ? $transaction : $pair;
            $credit = (float) $transaction->amount > 0 ? $transaction : $pair;

            if ($this->pairStillValid($accountRepository, $config, $debit, $credit, (int) $account->user_id, $includeHeuristic)) {
                continue;
            }

            $ids = [$transaction->id, $pair->id];
            if ($dryRun) {
                $this->line('Would unpair and reclassify transaction ids '.implode(', ', $ids));
                $updated += 2;
                $processedIds = array_merge($processedIds, $ids);

                continue;
            }
            foreach ([$transaction, $pair] as $t) {
                $newType = (float) $t->amount < 0 ? Transaction::TYPE_PAYMENT : Transaction::TYPE_DEPOSIT;
                $t->update(['type' => $newType, 'transfer_pair_transaction_id' => null]);
                $updated++;
            }
            $processedIds = array_merge($processedIds, $ids);
        }

        return $updated;
    }

    /**
     * Recheck a pair against the rule that created it.
     */
    private function pairStillValid(
        AccountRepositoryInterface $accountRepository,
        TransferConfig $config,
        Transaction $debit,
        Transaction $credit,
        int $userId,
        bool $includeHeuristic
    ): bool {
        $metadata = is_array($debit->metadata) ? $debit->metadata : [];
        $method = $metadata['transfer_detection']['method'] ?? null;

        if (in_array($method, [CandidatePair::METHOD_MANUAL, CandidatePair::METHOD_SINGLE_LEG], true)) {
            return true;
        }

        if (in_array($method, [CandidatePair::METHOD_HEURISTIC, CandidatePair::METHOD_CROSS_CURRENCY], true)) {
            if (! $includeHeuristic) {
                return true;
            }

            return ! $this->violatesHardInvariants($config, $debit, $credit, $method);
        }

        $accountIdToIban = $this->buildAccountIdToIbanMap($accountRepository, $userId);
        $debitTargetNorm = Iban::normalize($debit->target_iban);
        $creditSourceNorm = Iban::normalize($credit->source_iban);
        $debitAccountIban = $accountIdToIban[$debit->account_id] ?? null;
        $creditAccountIban = $accountIdToIban[$credit->account_id] ?? null;

        $linkToCredit = $debitTargetNorm !== null && $creditAccountIban !== null && $debitTargetNorm === $creditAccountIban;
        $linkFromDebit = $creditSourceNorm !== null && $debitAccountIban !== null && $creditSourceNorm === $debitAccountIban;
        $contradiction = ($debitTargetNorm !== null && $creditAccountIban !== null && $debitTargetNorm !== $creditAccountIban)
            || ($creditSourceNorm !== null && $debitAccountIban !== null && $creditSourceNorm !== $debitAccountIban);

        if ($method === CandidatePair::METHOD_IBAN_ONE_SIDED) {
            return ($linkToCredit || $linkFromDebit) && ! $contradiction;
        }

        // iban_bidirectional or legacy pairs without recorded evidence.
        return $linkToCredit && $linkFromDebit;
    }

    /**
     * Hard invariants every pair must satisfy regardless of detection method:
     * opposite signs, different accounts, and (same-currency pairs only)
     * amounts within tolerance.
     */
    private function violatesHardInvariants(TransferConfig $config, Transaction $debit, Transaction $credit, string $method): bool
    {
        if ((float) $debit->amount >= 0 || (float) $credit->amount <= 0) {
            return true;
        }
        if ($debit->account_id === $credit->account_id) {
            return true;
        }
        if ($method === CandidatePair::METHOD_HEURISTIC
            && (string) $debit->currency === (string) $credit->currency
        ) {
            $tolerance = $config->amountToleranceFor((float) abs((float) $debit->amount));
            if (abs(abs((float) $debit->amount) - abs((float) $credit->amount)) > $tolerance) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function buildAccountIdToIbanMap(AccountRepositoryInterface $accountRepository, int $userId): array
    {
        $accounts = $accountRepository->findByUser($userId);
        $map = [];
        foreach ($accounts as $account) {
            $iban = Iban::normalize($account->iban);
            if ($iban !== null) {
                $map[$account->id] = $iban;
            }
        }

        return $map;
    }

    /**
     * @return array<string, true>
     */
    private function getOwnIbansNormalized(AccountRepositoryInterface $accountRepository, int $userId): array
    {
        $accounts = $accountRepository->findByUser($userId);
        $out = [];
        foreach ($accounts as $acc) {
            $iban = Iban::normalize($acc->iban);
            if ($iban !== null) {
                $out[$iban] = true;
            }
        }

        return $out;
    }
}
