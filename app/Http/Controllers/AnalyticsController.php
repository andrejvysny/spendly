<?php

namespace App\Http\Controllers;

use App\Contracts\Repositories\AccountRepositoryInterface;
use App\Contracts\Repositories\AnalyticsRepositoryInterface;
use App\Contracts\Repositories\CategoryRepositoryInterface;
use App\Http\Requests\AnalyticsRequest;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AnalyticsController extends Controller
{
    public function __construct(
        private readonly AccountRepositoryInterface $accountRepository,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly AnalyticsRepositoryInterface $analyticsRepository
    ) {}

    public function index(AnalyticsRequest $request): \Inertia\Response
    {

        $selectedAccountIds = $request->getAccountIds();

        $user_accounts = $this->accountRepository->findByUser($this->getAuthUserId());
        $user_categories = $this->categoryRepository->findByUser($this->getAuthUserId());

        // If no accounts selected or invalid input, use all user accounts
        if (empty($selectedAccountIds)) {
            $accountIds = $user_accounts->pluck('id');
        } else {
            // Ensure we get only unique, valid account IDs that belong to the user
            $accountIds = collect($selectedAccountIds)
                ->unique()  // Remove duplicates
                ->filter(function ($id) use ($user_accounts) {
                    return $user_accounts->contains('id', $id);
                })
                ->values(); // Reset array keys to prevent issues
        }

        // Parse date range from request
        $dateRange = $this->parseDateRange($request);
        $startDate = $dateRange['start'];
        $endDate = $dateRange['end'];

        // Get data for analytics
        $accountIdsArray = $accountIds->toArray();
        $cashFlow = $this->analyticsRepository->getCashflow($accountIdsArray, $startDate, $endDate);
        $categorySpending = $this->analyticsRepository->getCategorySpending($accountIdsArray, $startDate, $endDate);
        $merchantSpending = $this->analyticsRepository->getMerchantSpending($accountIdsArray, $startDate, $endDate);

        return Inertia::render('Analytics/Index', [
            'accounts' => $user_accounts,
            'categories' => $user_categories,  // Add categories to the response
            'selectedAccountIds' => $accountIds->toArray(),
            'cashflow' => $cashFlow,
            'categorySpending' => $categorySpending,
            'merchantSpending' => $merchantSpending,
            'dateRange' => [
                'start' => $startDate->format('Y-m-d'),
                'end' => $endDate->format('Y-m-d'),
            ],
            'period' => $request->input('period', 'last_month'),
        ]);
    }

    /**
     * Parse date range from request.
     *
     * @return array{start: Carbon, end: Carbon}
     */
    private function parseDateRange(AnalyticsRequest $request): array
    {
        $period = $request->getPeriod();
        $customStart = $request->getStartDate();
        $customEnd = $request->getEndDate();
        $specificMonth = $request->getSpecificMonth();

        $now = Carbon::now();

        // Default to previous month
        $start = $now->copy()->subMonth()->startOfMonth();
        $end = $now->copy()->subMonth()->endOfMonth()->endOfDay();

        switch ($period) {
            case 'custom':
                if ($customStart && $customEnd) {
                    $start = Carbon::parse($customStart)->startOfDay();
                    $end = Carbon::parse($customEnd)->endOfDay();
                }
                break;
            case 'specific_month':
                if ($specificMonth) {
                    // Parse YYYY-MM format
                    $monthDate = Carbon::createFromFormat('Y-m', $specificMonth);
                    $start = $monthDate->copy()->startOfMonth();
                    $end = $monthDate->copy()->endOfMonth()->endOfDay();
                }
                break;
            case 'current_month':
                $start = $now->copy()->startOfMonth();
                $end = $now->copy()->endOfMonth()->endOfDay();
                break;
            case 'last_3_months':
                $start = $now->copy()->subMonths(3)->startOfMonth();
                $end = $now->copy()->endOfMonth()->endOfDay();
                break;
            case 'last_6_months':
                $start = $now->copy()->subMonths(6)->startOfMonth();
                $end = $now->copy()->endOfMonth()->endOfDay();
                break;
            case 'current_year':
                $start = $now->copy()->startOfYear();
                $end = $now->copy()->endOfYear()->endOfDay();
                break;
            case 'last_year':
                $start = $now->copy()->subYear()->startOfYear();
                $end = $now->copy()->subYear()->endOfYear()->endOfDay();
                break;
                // Default is last_month
        }

        return [
            'start' => $start,
            'end' => $end,
        ];
    }

    /**
     * Get balance history over time for accounts.
     *
     * @return JsonResponse
     */
    public function balanceHistory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account_ids' => 'nullable|array',
            'account_ids.*' => 'integer',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'granularity' => 'nullable|in:day,month',
        ]);

        $userAccounts = $this->accountRepository->findByUser($this->getAuthUserId());

        // Get selected account IDs or all user accounts
        $selectedIds = $validated['account_ids'] ?? [];
        if (empty($selectedIds)) {
            $accountIds = $userAccounts->pluck('id')->toArray();
        } else {
            // Filter to only user's accounts
            $accountIds = $userAccounts->pluck('id')
                ->intersect($selectedIds)
                ->values()
                ->toArray();
        }

        // Build current balances map
        $currentBalances = $userAccounts
            ->whereIn('id', $accountIds)
            ->pluck('balance', 'id')
            ->map(fn ($balance) => (float) $balance)
            ->toArray();

        // Parse date range
        $from = isset($validated['from'])
            ? Carbon::parse($validated['from'])->startOfDay()
            : Carbon::now()->subMonths(12)->startOfMonth();
        $to = isset($validated['to'])
            ? Carbon::parse($validated['to'])->endOfDay()
            : Carbon::now()->endOfDay();

        $granularity = $validated['granularity'] ?? 'month';

        // Get balance history
        $balanceHistory = $this->analyticsRepository->getBalanceHistory(
            $accountIds,
            $currentBalances,
            $from,
            $to,
            $granularity
        );

        // Also calculate net worth over time (sum of all accounts at each point)
        $netWorthHistory = $this->calculateNetWorthHistory($balanceHistory);

        // Get account info for frontend
        $accounts = $userAccounts->whereIn('id', $accountIds)->map(fn ($account) => [
            'id' => $account->id,
            'name' => $account->name,
            'currency' => $account->currency,
            'balance' => (float) $account->balance,
        ])->values();

        return response()->json([
            'accounts' => $accounts,
            'balance_history' => $balanceHistory,
            'net_worth_history' => $netWorthHistory,
            'date_range' => [
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
            ],
            'granularity' => $granularity,
        ]);
    }

    /**
     * Calculate net worth over time by summing all account balances at each point.
     *
     * @param  array<int, array<array{date: string, balance: float}>>  $balanceHistory
     * @return array<array{date: string, balance: float}>
     */
    private function calculateNetWorthHistory(array $balanceHistory): array
    {
        if (empty($balanceHistory)) {
            return [];
        }

        // Get the first account's dates as reference
        $firstAccountHistory = reset($balanceHistory);
        if (empty($firstAccountHistory)) {
            return [];
        }

        $netWorth = [];
        foreach ($firstAccountHistory as $index => $point) {
            $totalBalance = 0;
            foreach ($balanceHistory as $accountHistory) {
                if (isset($accountHistory[$index])) {
                    $totalBalance += $accountHistory[$index]['balance'];
                }
            }
            $netWorth[] = [
                'date' => $point['date'],
                'balance' => round($totalBalance, 2),
            ];
        }

        return $netWorth;
    }
}
