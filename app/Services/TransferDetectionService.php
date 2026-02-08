<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Repositories\AccountRepositoryInterface;
use App\Contracts\Repositories\TransactionRepositoryInterface;
use App\Models\Transaction;
use Carbon\Carbon;

class TransferDetectionService
{
    /** Amount tolerance for matching (e.g. rounding differences). */
    private const float AMOUNT_TOLERANCE = 0.01;

    public function __construct(
        private readonly AccountRepositoryInterface $accountRepository,
        private readonly TransactionRepositoryInterface $transactionRepository
    ) {}

    /**
     * Detect same-day debit/credit pairs across the user's accounts and mark them as transfers.
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

        $query = Transaction::query()
            ->whereIn('account_id', $accountIds)
            ->where('type', '!=', Transaction::TYPE_TRANSFER)
            ->whereNull('transfer_pair_transaction_id');

        if ($from !== null) {
            $query->whereDate('booked_date', '>=', $from);
        }
        if ($to !== null) {
            $query->whereDate('booked_date', '<=', $to);
        }

        $transactions = $query->orderBy('booked_date')->get();

        $byDay = $transactions->groupBy(function (Transaction $t) {
            return Carbon::parse($t->booked_date)->format('Y-m-d');
        });

        $updated = 0;

        foreach ($byDay as $dateKey => $dayTransactions) {
            $debits = $dayTransactions->filter(fn (Transaction $t) => $t->amount < 0)
                ->sortByDesc(fn (Transaction $t) => abs((float) $t->amount))
                ->values();
            $credits = $dayTransactions->filter(fn (Transaction $t) => $t->amount > 0)->values();

            $usedCreditIds = [];

            foreach ($debits as $debit) {
                $match = $this->findMatchingCredit($debit, $credits, $usedCreditIds, $accountIdToIban);
                if ($match === null) {
                    continue;
                }

                $this->transactionRepository->transaction(function () use ($debit, $match, &$updated) {
                    $debit->update([
                        'type' => Transaction::TYPE_TRANSFER,
                        'transfer_pair_transaction_id' => $match->id,
                    ]);
                    $match->update([
                        'type' => Transaction::TYPE_TRANSFER,
                        'transfer_pair_transaction_id' => $debit->id,
                    ]);
                    $updated += 2;
                });

                $usedCreditIds[] = $match->id;
            }
        }

        return $updated;
    }

    /**
     * Find a credit transaction that matches the debit (same amount, different account).
     * Only pairs when IBANs show money moved between the two accounts: debit's target_iban
     * must equal the credit account's IBAN, and credit's source_iban must equal the debit account's IBAN.
     *
     * @param  \Illuminate\Support\Collection<int, Transaction>  $credits
     * @param  array<int>  $usedCreditIds
     * @param  array<int, string>  $accountIdToIban  Map account_id -> normalized IBAN
     */
    private function findMatchingCredit(Transaction $debit, $credits, array $usedCreditIds, array $accountIdToIban): ?Transaction
    {
        $amount = abs((float) $debit->amount);
        $debitAccountIban = $accountIdToIban[$debit->account_id] ?? null;
        $debitTargetIban = $this->normalizeIbanNullable($debit->target_iban);

        foreach ($credits as $credit) {
            if (in_array($credit->id, $usedCreditIds, true)) {
                continue;
            }
            if ($credit->account_id === $debit->account_id) {
                continue;
            }
            $creditAmount = (float) $credit->amount;
            if (abs($creditAmount - $amount) > self::AMOUNT_TOLERANCE) {
                continue;
            }

            // Only pair when both legs show the other account's IBAN (real transfer between own accounts)
            $creditAccountIban = $accountIdToIban[$credit->account_id] ?? null;
            $creditSourceIban = $this->normalizeIbanNullable($credit->source_iban);
            if ($debitTargetIban === null || $creditSourceIban === null || $debitAccountIban === null || $creditAccountIban === null) {
                continue;
            }
            if ($debitTargetIban !== $creditAccountIban || $creditSourceIban !== $debitAccountIban) {
                continue;
            }

            return $credit;
        }

        return null;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Models\Account>  $accounts
     * @return array<int, string>
     */
    private function buildAccountIdToIbanMap($accounts): array
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
