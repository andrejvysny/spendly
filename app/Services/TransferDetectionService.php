<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Repositories\AccountRepositoryInterface;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TransferDetectionService
{
    /** Amount tolerance for matching (e.g. rounding differences). */
    private const float AMOUNT_TOLERANCE = 0.01;

    public function __construct(
        private readonly AccountRepositoryInterface $accountRepository
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
                $match = $this->findMatchingCredit($debit, $credits, $usedCreditIds);
                if ($match === null) {
                    continue;
                }

                DB::transaction(function () use ($debit, $match, &$updated) {
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
     *
     * @param  \Illuminate\Support\Collection<int, Transaction>  $credits
     * @param  array<int>  $usedCreditIds
     */
    private function findMatchingCredit(Transaction $debit, $credits, array $usedCreditIds): ?Transaction
    {
        $amount = abs((float) $debit->amount);

        foreach ($credits as $credit) {
            if (in_array($credit->id, $usedCreditIds, true)) {
                continue;
            }
            if ($credit->account_id === $debit->account_id) {
                continue;
            }
            $creditAmount = (float) $credit->amount;
            if (abs($creditAmount - $amount) <= self::AMOUNT_TOLERANCE) {
                return $credit;
            }
        }

        return null;
    }
}
