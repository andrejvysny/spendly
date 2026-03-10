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

        $daysDiff = $startDate->diffInDays($endDate);
        $isShortRange = $daysDiff < 32;
        $series = $isShortRange
            ? $this->initializeDailyCashflowSeries($startDate, $endDate)
            : $this->initializeMonthlyCashflowSeries($startDate, $endDate);

        $transactions = DB::table('transactions')
            ->whereIn('account_id', $accountIds)
            ->where('booked_date', '>=', $startDate)
            ->where('booked_date', '<=', $endDate)
            ->select('booked_date', 'amount', 'type')
            ->orderBy('booked_date')
            ->get();

        foreach ($transactions as $transaction) {
            $date = Carbon::parse($transaction->booked_date);
            $key = $isShortRange ? $date->format('Y-m-d') : $date->format('Y-m');
            if (! isset($series[$key])) {
                continue;
            }

            $series[$key]['transaction_count']++;

            $isTransfer = $transaction->type === Transaction::TYPE_TRANSFER;
            if (! $isTransfer) {
                if ((float) $transaction->amount > 0) {
                    $series[$key]['total_income'] += (float) $transaction->amount;
                } else {
                    $series[$key]['total_expenses'] += abs((float) $transaction->amount);
                }
            }

            $balanceField = $isShortRange ? 'day_balance' : 'month_balance';
            $series[$key][$balanceField] += (float) $transaction->amount;
        }

        return collect(array_values($series));
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
            ->where('transactions.booked_date', '>=', $startDate)
            ->where('transactions.booked_date', '<=', $endDate)
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
            ->where('booked_date', '>=', $startDate)
            ->where('booked_date', '<=', $endDate)
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
     * @return array{withCounterparty: \Illuminate\Support\Collection, noCounterparty: object|null}
     */
    public function getCounterpartySpending(array $accountIds, Carbon $startDate, Carbon $endDate): array
    {
        if ($accountIds === []) {
            return ['withCounterparty' => collect(), 'noCounterparty' => null];
        }

        $withCounterparty = DB::table('transactions')
            ->join('counterparties', 'transactions.counterparty_id', '=', 'counterparties.id')
            ->select(
                'counterparties.name as counterparty',
                DB::raw('SUM(ABS(transactions.amount)) as total'),
                DB::raw('COUNT(*) as count')
            )
            ->whereIn('transactions.account_id', $accountIds)
            ->where('transactions.booked_date', '>=', $startDate)
            ->where('transactions.booked_date', '<=', $endDate)
            ->where('transactions.amount', '<', 0)
            ->where('transactions.type', '!=', Transaction::TYPE_TRANSFER)
            ->whereNotNull('transactions.counterparty_id')
            ->groupBy('counterparties.id', 'counterparties.name')
            ->orderBy('total', 'desc')
            ->limit(10)
            ->get();

        $noCounterparty = DB::table('transactions')
            ->select(
                DB::raw('SUM(ABS(amount)) as total'),
                DB::raw('COUNT(*) as count')
            )
            ->whereIn('account_id', $accountIds)
            ->where('booked_date', '>=', $startDate)
            ->where('booked_date', '<=', $endDate)
            ->where('amount', '<', 0)
            ->where('type', '!=', Transaction::TYPE_TRANSFER)
            ->whereNull('counterparty_id')
            ->first();

        return [
            'withCounterparty' => $withCounterparty,
            'noCounterparty' => $noCounterparty,
        ];
    }

    /**
     * @param  array<int>  $accountIds
     * @return Collection<int, object>
     */
    public function getMonthlyCashflow(array $accountIds, int $beforeMonth = 0): Collection
    {
        if ($accountIds === []) {
            return collect();
        }

        $startDate = now()->subMonths($beforeMonth)->startOfMonth();
        $endDate = now()->subMonths($beforeMonth)->endOfMonth();
        $series = $this->initializeDailyMonthlySeries($startDate, $endDate);

        $transactions = DB::table('transactions')
            ->whereIn('account_id', $accountIds)
            ->where('booked_date', '>=', $startDate)
            ->where('booked_date', '<=', $endDate)
            ->select('booked_date', 'amount', 'type')
            ->orderBy('booked_date')
            ->get();

        foreach ($transactions as $transaction) {
            $date = Carbon::parse($transaction->booked_date);
            $key = $date->format('Y-m-d');
            if (! isset($series[$key])) {
                continue;
            }

            $series[$key]['transaction_count']++;
            if ($transaction->type !== Transaction::TYPE_TRANSFER) {
                if ((float) $transaction->amount > 0) {
                    $series[$key]['daily_income'] += (float) $transaction->amount;
                } else {
                    $series[$key]['daily_spending'] += abs((float) $transaction->amount);
                }
            }

            $series[$key]['daily_balance'] += (float) $transaction->amount;
        }

        return collect(array_map(static fn (array $item) => (object) $item, array_values($series)));
    }

    /**
     * Get balance history over time for accounts using back-calculation from current balance.
     *
     * @param  array<int>  $accountIds
     * @param  array<int, float>  $currentBalances  Map of account_id => current balance
     * @param  string  $granularity  'day' or 'month'
     * @return array<int, array<array{date: string, balance: float}>>
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

        $isDaily = $granularity === 'day';
        $datePoints = $this->buildBalanceDatePoints($startDate, $endDate, $isDaily);
        $dateFormat = match (config('database.default')) {
            'sqlite' => $isDaily ? 'strftime("%Y-%m-%d", booked_date)' : 'strftime("%Y-%m", booked_date)',
            'pgsql' => $isDaily ? "TO_CHAR(booked_date, 'YYYY-MM-DD')" : "TO_CHAR(booked_date, 'YYYY-MM')",
            default => $isDaily ? 'DATE_FORMAT(booked_date, "%Y-%m-%d")' : 'DATE_FORMAT(booked_date, "%Y-%m")',
        };

        $result = [];

        foreach ($accountIds as $accountId) {
            if (! isset($currentBalances[$accountId])) {
                continue;
            }

            $postEndNetChange = (float) DB::table('transactions')
                ->where('account_id', $accountId)
                ->where('booked_date', '>', $endDate->copy()->endOfDay())
                ->sum('amount');

            $balanceAtEndDate = (float) $currentBalances[$accountId] - $postEndNetChange;

            $txByPeriod = DB::table('transactions')
                ->where('account_id', $accountId)
                ->where('booked_date', '>=', $startDate->copy()->startOfDay())
                ->where('booked_date', '<=', $endDate->copy()->endOfDay())
                ->selectRaw("$dateFormat as period, SUM(amount) as net_amount")
                ->groupBy('period')
                ->pluck('net_amount', 'period');

            $runningBalance = $balanceAtEndDate;
            $chartData = [];

            foreach (array_reverse($datePoints) as $point) {
                $periodKey = $isDaily ? $point->format('Y-m-d') : $point->format('Y-m');
                $displayDate = $isDaily ? $point->format('Y-m-d') : $point->format('M Y');

                $chartData[] = [
                    'date' => $displayDate,
                    'balance' => round($runningBalance, 2),
                ];

                $runningBalance -= (float) ($txByPeriod->get($periodKey) ?? 0);
            }

            $result[$accountId] = array_reverse($chartData);
        }

        return $result;
    }

    /**
     * @return array<string, array{year:int, month:int, day:int, transaction_count:int, total_income:float, total_expenses:float, day_balance:float}>
     */
    private function initializeDailyCashflowSeries(Carbon $startDate, Carbon $endDate): array
    {
        $series = [];
        $current = $startDate->copy()->startOfDay();

        while ($current->lte($endDate->copy()->startOfDay())) {
            $series[$current->format('Y-m-d')] = [
                'year' => (int) $current->format('Y'),
                'month' => (int) $current->format('m'),
                'day' => (int) $current->format('d'),
                'transaction_count' => 0,
                'total_income' => 0.0,
                'total_expenses' => 0.0,
                'day_balance' => 0.0,
            ];
            $current->addDay();
        }

        return $series;
    }

    /**
     * @return array<string, array{year:int, month:int, transaction_count:int, total_income:float, total_expenses:float, month_balance:float}>
     */
    private function initializeMonthlyCashflowSeries(Carbon $startDate, Carbon $endDate): array
    {
        $series = [];
        $current = $startDate->copy()->startOfMonth();
        $last = $endDate->copy()->startOfMonth();

        while ($current->lte($last)) {
            $series[$current->format('Y-m')] = [
                'year' => (int) $current->format('Y'),
                'month' => (int) $current->format('m'),
                'transaction_count' => 0,
                'total_income' => 0.0,
                'total_expenses' => 0.0,
                'month_balance' => 0.0,
            ];
            $current->addMonth();
        }

        return $series;
    }

    /**
     * @return array<string, array{year:int, month:int, day:int, transaction_count:int, daily_spending:float, daily_income:float, daily_balance:float}>
     */
    private function initializeDailyMonthlySeries(Carbon $startDate, Carbon $endDate): array
    {
        $series = [];
        $current = $startDate->copy()->startOfDay();

        while ($current->lte($endDate->copy()->startOfDay())) {
            $series[$current->format('Y-m-d')] = [
                'year' => (int) $current->format('Y'),
                'month' => (int) $current->format('m'),
                'day' => (int) $current->format('d'),
                'transaction_count' => 0,
                'daily_spending' => 0.0,
                'daily_income' => 0.0,
                'daily_balance' => 0.0,
            ];
            $current->addDay();
        }

        return $series;
    }

    /**
     * @return array<int, Carbon>
     */
    private function buildBalanceDatePoints(Carbon $startDate, Carbon $endDate, bool $isDaily): array
    {
        $points = [];
        $current = $isDaily
            ? $startDate->copy()->startOfDay()
            : $startDate->copy()->startOfMonth();
        $last = $isDaily
            ? $endDate->copy()->startOfDay()
            : $endDate->copy()->startOfMonth();

        while ($current->lte($last)) {
            $points[] = $current->copy();
            $isDaily ? $current->addDay() : $current->addMonth();
        }

        return $points;
    }
}
