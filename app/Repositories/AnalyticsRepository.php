<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\AnalyticsRepositoryInterface;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AnalyticsRepository implements AnalyticsRepositoryInterface
{
    /**
     * @param  array<int>  $accountIds
     * @return Collection<int, mixed>
     */
    public function getCashflow(array $accountIds, Carbon $startDate, Carbon $endDate): Collection
    {
        if ($accountIds === []) {
            return collect();
        }

        $query = DB::table('transactions')
            ->whereIn('account_id', $accountIds)
            ->where('processed_date', '>=', $startDate)
            ->where('processed_date', '<=', $endDate);

        $daysDiff = $startDate->diffInDays($endDate);
        $isShortRange = $daysDiff < 32;

        $transactions = $query->select('processed_date', 'amount', 'type')
            ->orderBy('processed_date')
            ->get();

        if ($isShortRange) {
            $dailyBalances = [];
            foreach ($transactions as $transaction) {
                $date = Carbon::parse($transaction->processed_date);
                $dateKey = $date->format('Y-m-d');

                if (! isset($dailyBalances[$dateKey])) {
                    $dailyBalances[$dateKey] = [
                        'year' => (int) $date->format('Y'),
                        'month' => (int) $date->format('m'),
                        'day' => (int) $date->format('d'),
                        'transaction_count' => 0,
                        'total_income' => 0,
                        'total_expenses' => 0,
                        'day_balance' => 0,
                    ];
                }

                $dailyBalances[$dateKey]['transaction_count']++;
                $isTransfer = $transaction->type === Transaction::TYPE_TRANSFER;
                if (! $isTransfer) {
                    if ($transaction->amount > 0) {
                        $dailyBalances[$dateKey]['total_income'] += $transaction->amount;
                    } else {
                        $dailyBalances[$dateKey]['total_expenses'] += abs($transaction->amount);
                    }
                }
                $dailyBalances[$dateKey]['day_balance'] += $transaction->amount;
            }

            return collect($dailyBalances)->values()->sortBy(function ($item) {
                return sprintf('%04d-%02d-%02d', $item['year'], $item['month'], $item['day']);
            })->values();
        }

        $monthlyBalances = [];
        foreach ($transactions as $transaction) {
            $date = Carbon::parse($transaction->processed_date);
            $monthKey = $date->format('Y-m');

            if (! isset($monthlyBalances[$monthKey])) {
                $monthlyBalances[$monthKey] = [
                    'year' => (int) $date->format('Y'),
                    'month' => (int) $date->format('m'),
                    'transaction_count' => 0,
                    'total_income' => 0,
                    'total_expenses' => 0,
                    'month_balance' => 0,
                ];
            }

            $monthlyBalances[$monthKey]['transaction_count']++;
            $isTransfer = $transaction->type === Transaction::TYPE_TRANSFER;
            if (! $isTransfer) {
                if ($transaction->amount > 0) {
                    $monthlyBalances[$monthKey]['total_income'] += $transaction->amount;
                } else {
                    $monthlyBalances[$monthKey]['total_expenses'] += abs($transaction->amount);
                }
            }
            $monthlyBalances[$monthKey]['month_balance'] += $transaction->amount;
        }

        return collect($monthlyBalances)->values()->sortBy(function ($item) {
            return sprintf('%04d-%02d', $item['year'], $item['month']);
        })->values();
    }

    /**
     * @param  array<int>  $accountIds
     * @return array{categorized: \Illuminate\Support\Collection, uncategorized: object|null}
     */
    public function getCategorySpending(array $accountIds, Carbon $startDate, Carbon $endDate): array
    {
        if ($accountIds === []) {
            return ['categorized' => collect(), 'uncategorized' => null];
        }

        $categorized = DB::table('transactions')
            ->join('categories', 'transactions.category_id', '=', 'categories.id')
            ->select(
                'categories.name as category',
                DB::raw('SUM(ABS(amount)) as total'),
                DB::raw('COUNT(*) as count')
            )
            ->whereIn('transactions.account_id', $accountIds)
            ->where('transactions.processed_date', '>=', $startDate)
            ->where('transactions.processed_date', '<=', $endDate)
            ->where('transactions.amount', '<', 0)
            ->where('transactions.type', '!=', Transaction::TYPE_TRANSFER)
            ->whereNotNull('transactions.category_id')
            ->groupBy('categories.id', 'categories.name')
            ->orderBy('total', 'desc')
            ->limit(10)
            ->get();

        $uncategorized = DB::table('transactions')
            ->select(
                DB::raw('SUM(ABS(amount)) as total'),
                DB::raw('COUNT(*) as count')
            )
            ->whereIn('account_id', $accountIds)
            ->where('processed_date', '>=', $startDate)
            ->where('processed_date', '<=', $endDate)
            ->where('amount', '<', 0)
            ->where('type', '!=', Transaction::TYPE_TRANSFER)
            ->whereNull('category_id')
            ->first();

        return [
            'categorized' => $categorized,
            'uncategorized' => $uncategorized,
        ];
    }

    /**
     * @param  array<int>  $accountIds
     * @return array{withMerchant: \Illuminate\Support\Collection, noMerchant: object|null}
     */
    public function getMerchantSpending(array $accountIds, Carbon $startDate, Carbon $endDate): array
    {
        if ($accountIds === []) {
            return ['withMerchant' => collect(), 'noMerchant' => null];
        }

        $withMerchant = DB::table('transactions')
            ->join('merchants', 'transactions.merchant_id', '=', 'merchants.id')
            ->select(
                'merchants.name as merchant',
                DB::raw('SUM(ABS(transactions.amount)) as total'),
                DB::raw('COUNT(*) as count')
            )
            ->whereIn('transactions.account_id', $accountIds)
            ->where('transactions.processed_date', '>=', $startDate)
            ->where('transactions.processed_date', '<=', $endDate)
            ->where('transactions.amount', '<', 0)
            ->where('transactions.type', '!=', Transaction::TYPE_TRANSFER)
            ->whereNotNull('transactions.merchant_id')
            ->groupBy('merchants.id', 'merchants.name')
            ->orderBy('total', 'desc')
            ->limit(10)
            ->get();

        $noMerchant = DB::table('transactions')
            ->select(
                DB::raw('SUM(ABS(amount)) as total'),
                DB::raw('COUNT(*) as count')
            )
            ->whereIn('account_id', $accountIds)
            ->where('processed_date', '>=', $startDate)
            ->where('processed_date', '<=', $endDate)
            ->where('amount', '<', 0)
            ->where('type', '!=', Transaction::TYPE_TRANSFER)
            ->whereNull('merchant_id')
            ->first();

        return [
            'withMerchant' => $withMerchant,
            'noMerchant' => $noMerchant,
        ];
    }

    /**
     * @param  array<int>  $accountIds
     * @return Collection<int, object>
     */
    public function getMonthlyCashflow(array $accountIds, int $beforeMonth = 0): Collection
    {
        $startDate = now()->subMonths($beforeMonth)->startOfMonth();
        $endDate = now()->subMonths($beforeMonth)->endOfMonth();

        return DB::table('transactions')
            ->select(
                DB::raw('CAST(strftime("%Y", processed_date) AS INTEGER) as year'),
                DB::raw('CAST(strftime("%m", processed_date) AS INTEGER) as month'),
                DB::raw('CAST(strftime("%d", processed_date) AS INTEGER) as day'),
                DB::raw('COUNT(*) as transaction_count'),
                DB::raw("SUM(CASE WHEN amount < 0 AND type != '".Transaction::TYPE_TRANSFER."' THEN ABS(amount) ELSE 0 END) as daily_spending"),
                DB::raw("SUM(CASE WHEN amount > 0 AND type != '".Transaction::TYPE_TRANSFER."' THEN amount ELSE 0 END) as daily_income"),
                DB::raw('SUM(amount) as daily_balance')
            )
            ->whereIn('account_id', $accountIds)
            ->where('processed_date', '>=', $startDate)
            ->where('processed_date', '<=', $endDate)
            ->groupBy(
                DB::raw('strftime("%Y", processed_date)'),
                DB::raw('strftime("%m", processed_date)'),
                DB::raw('strftime("%d", processed_date)')
            )
            ->orderBy('year')
            ->orderBy('month')
            ->orderBy('day')
            ->get();
    }

    /**
     * Get balance history over time for accounts using back-calculation from current balance.
     *
     * @param  array<int>  $accountIds
     * @param  array<int, float>  $currentBalances  Map of account_id => current balance
     * @param  string  $granularity  'day' or 'month'
     * @return array<int, array<array{date: string, balance: float}>>  Map of account_id => time series
     */
    public function getBalanceHistory(
        array $accountIds,
        array $currentBalances,
        Carbon $startDate,
        Carbon $endDate,
        string $granularity = 'month'
    ): array {
        if ($accountIds === []) {
            return [];
        }

        $result = [];
        $isDaily = $granularity === 'day';

        // Generate date points based on granularity
        $datePoints = [];
        $current = $endDate->copy();
        while ($current >= $startDate) {
            $datePoints[] = $current->copy();
            if ($isDaily) {
                $current->subDay();
            } else {
                $current->subMonth();
            }
        }
        // Reverse to oldest first
        $datePoints = array_reverse($datePoints);

        // Get database-specific date format
        $dateFormat = match (config('database.default')) {
            'sqlite' => $isDaily ? 'strftime("%Y-%m-%d", booked_date)' : 'strftime("%Y-%m", booked_date)',
            'pgsql' => $isDaily ? "TO_CHAR(booked_date, 'YYYY-MM-DD')" : "TO_CHAR(booked_date, 'YYYY-MM')",
            default => $isDaily ? 'DATE_FORMAT(booked_date, "%Y-%m-%d")' : 'DATE_FORMAT(booked_date, "%Y-%m")',
        };

        foreach ($accountIds as $accountId) {
            if (! isset($currentBalances[$accountId])) {
                continue;
            }

            $currentBalance = (float) $currentBalances[$accountId];

            // Get transactions grouped by period for this account
            $txByPeriod = DB::table('transactions')
                ->where('account_id', $accountId)
                ->where('booked_date', '>=', $startDate->startOfDay())
                ->where('booked_date', '<=', $endDate->endOfDay())
                ->selectRaw("$dateFormat as period, SUM(amount) as net_amount")
                ->groupBy('period')
                ->pluck('net_amount', 'period');

            // Back-calculate: Start from current balance and work backwards
            $runningBalance = $currentBalance;
            $chartData = [];

            // Iterate from newest to oldest
            $reversedPoints = array_reverse($datePoints);
            foreach ($reversedPoints as $point) {
                $periodKey = $isDaily ? $point->format('Y-m-d') : $point->format('Y-m');
                $displayDate = $isDaily ? $point->format('Y-m-d') : $point->format('M Y');

                $chartData[] = [
                    'date' => $displayDate,
                    'balance' => round($runningBalance, 2),
                ];

                // Subtract this period's net change to get previous period's balance
                $netChange = $txByPeriod->get($periodKey) ?? 0;
                $runningBalance -= (float) $netChange;
            }

            // Reverse back to chronological order
            $result[$accountId] = array_reverse($chartData);
        }

        return $result;
    }
}
