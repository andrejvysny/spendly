<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use Carbon\Carbon;
use Illuminate\Support\Collection;

interface AnalyticsRepositoryInterface
{
    /**
     * Get cashflow data for the specified accounts and date range.
     *
     * @param  array<int>  $accountIds
     * @return Collection<int, mixed>
     */
    public function getCashflow(array $accountIds, Carbon $startDate, Carbon $endDate): Collection;

    /**
     * Get spending by category for the specified date range.
     *
     * @param  array<int>  $accountIds
     * @return array{categorized: \Illuminate\Support\Collection, uncategorized: object|null}
     */
    public function getCategorySpending(array $accountIds, Carbon $startDate, Carbon $endDate): array;

    /**
     * Get spending by merchant for the specified date range.
     *
     * @param  array<int>  $accountIds
     * @return array{withMerchant: \Illuminate\Support\Collection, noMerchant: object|null}
     */
    public function getMerchantSpending(array $accountIds, Carbon $startDate, Carbon $endDate): array;

    /**
     * Get daily cashflow for a specific month (for account show page).
     *
     * @param  array<int>  $accountIds
     * @return Collection<int, object>
     */
    public function getMonthlyCashflow(array $accountIds, int $beforeMonth = 0): Collection;

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
    ): array;
}
