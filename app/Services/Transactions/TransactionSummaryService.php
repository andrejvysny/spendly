<?php

declare(strict_types=1);

namespace App\Services\Transactions;

use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Computes summary aggregates for transaction listing endpoints.
 *
 * Extracted from TransactionController so index / filter / load-more share
 * a single source of truth for `monthlySummaries` and `totalSummary`.
 */
class TransactionSummaryService
{
    /**
     * Compute the total-summary block over the (already filtered) query.
     *
     * @param  Builder<Transaction>  $query
     * @return array{count: int, income: float, expense: float, balance: float, categoriesCount: int, counterpartiesCount: int, uncategorizedCount: int, noCounterpartyCount: int}
     */
    public function totalSummary(Builder $query): array
    {
        return [
            'count' => (clone $query)->count(),
            'income' => (float) (clone $query)
                ->excludingTransfers()
                ->where('amount', '>', 0)
                ->sum('amount'),
            'expense' => abs((float) (clone $query)
                ->excludingTransfers()
                ->where('amount', '<', 0)
                ->sum('amount')),
            'balance' => (float) (clone $query)->sum('amount'),
            'categoriesCount' => (clone $query)->whereNotNull('category_id')->distinct('category_id')->count('category_id'),
            'counterpartiesCount' => (clone $query)->whereNotNull('counterparty_id')->distinct('counterparty_id')->count('counterparty_id'),
            'uncategorizedCount' => (clone $query)->whereNull('category_id')->count(),
            'noCounterpartyCount' => (clone $query)->whereNull('counterparty_id')->count(),
        ];
    }

    /**
     * Bucket the given transactions by translated month label and accumulate
     * income / expense / balance per bucket. Transfers are excluded from
     * income & expense (kept in balance).
     *
     * @param  iterable<Transaction>  $transactions
     * @return array<string, array{income: float, expense: float, balance: float}>
     */
    public function monthlySummaries(iterable $transactions): array
    {
        $summaries = [];

        foreach ($transactions as $transaction) {
            $month = Carbon::parse($transaction->booked_date)->translatedFormat('F Y');

            if (! isset($summaries[$month])) {
                $summaries[$month] = ['income' => 0.0, 'expense' => 0.0, 'balance' => 0.0];
            }

            $amount = (float) $transaction->amount;

            if (! $transaction->isTransfer()) {
                if ($amount > 0) {
                    $summaries[$month]['income'] += $amount;
                } else {
                    $summaries[$month]['expense'] += abs($amount);
                }
            }
            $summaries[$month]['balance'] += $amount;
        }

        return $summaries;
    }
}
