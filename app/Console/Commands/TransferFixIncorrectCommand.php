<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\Repositories\AccountRepositoryInterface;
use App\Models\Transaction;
use Illuminate\Console\Command;

class TransferFixIncorrectCommand extends Command
{
    protected $signature = 'transfers:fix-incorrect
                            {--dry-run : List what would be changed without updating}
                            {--user= : Run for a specific user ID only}
                            {--fix-pairs : Also unpair and reclassify paired TRANSFERs that do not satisfy IBAN check}';

    protected $description = 'Reclassify TRANSFER transactions that have no pair and whose counterparty IBAN is not one of the user\'s accounts to PAYMENT or DEPOSIT';

    public function handle(AccountRepositoryInterface $accountRepository): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $userIdOpt = $this->option('user');
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
            if ($account === null) {
                continue;
            }
            $userId = (int) $account->user_id;
            $ownIbans = $this->getOwnIbansNormalized($accountRepository, $userId);
            $counterpartyIban = $transaction->amount < 0
                ? $transaction->target_iban
                : $transaction->source_iban;
            $counterpartyNorm = $this->normalizeIbanNullable($counterpartyIban);
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

    private function fixIncorrectlyPairedTransfers(AccountRepositoryInterface $accountRepository, bool $dryRun, ?string $userIdOpt): int
    {
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
            if ($pair === null) {
                continue;
            }
            $account = $transaction->account;
            $pairAccount = $pair->account;
            if ($account === null || $pairAccount === null) {
                continue;
            }
            $userId = (int) $account->user_id;
            $accountIdToIban = $this->buildAccountIdToIbanMap($accountRepository, $userId);
            $debit = (float) $transaction->amount < 0 ? $transaction : $pair;
            $credit = (float) $transaction->amount > 0 ? $transaction : $pair;
            $debitTargetNorm = $this->normalizeIbanNullable($debit->target_iban);
            $creditSourceNorm = $this->normalizeIbanNullable($credit->source_iban);
            $debitAccountIban = $accountIdToIban[$debit->account_id] ?? null;
            $creditAccountIban = $accountIdToIban[$credit->account_id] ?? null;
            $isValidPair = $debitTargetNorm !== null
                && $creditSourceNorm !== null
                && $debitAccountIban !== null
                && $creditAccountIban !== null
                && $debitTargetNorm === $creditAccountIban
                && $creditSourceNorm === $debitAccountIban;
            if ($isValidPair) {
                continue;
            }
            $ids = [$transaction->id, $pair->id];
            if ($dryRun) {
                $this->line('Would unpair and reclassify transaction ids ' . implode(', ', $ids));
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
     * @return array<int, string>
     */
    private function buildAccountIdToIbanMap(AccountRepositoryInterface $accountRepository, int $userId): array
    {
        $accounts = $accountRepository->findByUser($userId);
        $map = [];
        foreach ($accounts as $account) {
            $iban = $account->iban;
            if ($iban !== null && trim((string) $iban) !== '') {
                $map[$account->id] = $this->normalizeIban((string) $iban);
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
            $iban = $acc->iban;
            if ($iban !== null && trim((string) $iban) !== '') {
                $out[$this->normalizeIban((string) $iban)] = true;
            }
        }
        return $out;
    }

    private function normalizeIban(string $iban): string
    {
        return strtoupper(trim(preg_replace('/\s+/', '', $iban)));
    }

    private function normalizeIbanNullable(?string $iban): ?string
    {
        if ($iban === null || trim($iban) === '') {
            return null;
        }
        return $this->normalizeIban($iban);
    }
}
