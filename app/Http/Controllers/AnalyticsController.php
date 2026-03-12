<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\Repositories\AccountRepositoryInterface;
use App\Contracts\Repositories\AnalyticsRepositoryInterface;
use App\Contracts\Repositories\CategoryRepositoryInterface;
use App\Http\Requests\AnalyticsRequest;
use App\Models\User;
use App\Services\ExchangeRateService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AnalyticsController extends Controller
{
    public function __construct(
        private readonly AccountRepositoryInterface $accountRepository,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly AnalyticsRepositoryInterface $analyticsRepository,
        private readonly ExchangeRateService $exchangeRateService,
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
                ->unique()
                ->filter(function ($id) use ($user_accounts) {
                    return $user_accounts->contains('id', $id);
                })
                ->values();
        }

        // Parse date range from request
        $dateRange = $this->parseDateRange($request);
        $startDate = $dateRange['start'];
        $endDate = $dateRange['end'];
        $selectedAccounts = $user_accounts->whereIn('id', $accountIds->toArray())->values();
        $isMultiCurrency = $this->isMultiCurrency($selectedAccounts);
        $useNativeAmount = $isMultiCurrency;

        // Get data for analytics — always fetch, use native_amount for multi-currency
        $accountIdsArray = $accountIds->toArray();
        $cashFlow = $this->analyticsRepository->getCashflow($accountIdsArray, $startDate, $endDate, $useNativeAmount);
        $categorySpending = $this->analyticsRepository->getCategorySpending($accountIdsArray, $startDate, $endDate, $useNativeAmount);
        $counterpartySpending = $this->analyticsRepository->getCounterpartySpending($accountIdsArray, $startDate, $endDate, $useNativeAmount);

        /** @var User $user */
        $user = auth()->user();
        $displayCurrency = $user->base_currency ?? 'EUR';

        return Inertia::render('Analytics/Index', [
            'accounts' => $user_accounts,
            'categories' => $user_categories,
            'selectedAccountIds' => $accountIds->toArray(),
            'cashflow' => $cashFlow,
            'categorySpending' => $categorySpending,
            'counterpartySpending' => $counterpartySpending,
            'dateRange' => [
                'start' => $startDate->format('Y-m-d'),
                'end' => $endDate->format('Y-m-d'),
            ],
            'period' => $request->input('period', 'last_month'),
            'is_converted' => $isMultiCurrency,
            'display_currency' => $displayCurrency,
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
        }

        return [
            'start' => $start,
            'end' => $end,
        ];
    }

    /**
     * Get balance history over time for accounts.
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

        // Get balance history (works for any currency mix — each account computed independently)
        $balanceHistory = $this->analyticsRepository->getBalanceHistory(
            $accountIds,
            $currentBalances,
            $from,
            $to,
            $granularity
        );

        $selectedAccounts = $userAccounts->whereIn('id', $accountIds)->values();
        $isMultiCurrency = $this->isMultiCurrency($selectedAccounts);

        // For multi-currency: convert each account's balance points to base currency
        if ($isMultiCurrency) {
            $balanceHistory = $this->convertBalanceHistoryToBaseCurrency($balanceHistory, $userAccounts, $accountIds);
        }

        $netWorthHistory = $this->calculateNetWorthHistory($balanceHistory);

        $accounts = $userAccounts->whereIn('id', $accountIds)->map(fn ($account) => [
            'id' => $account->id,
            'name' => $account->name,
            'currency' => $account->currency,
            'balance' => (float) $account->balance,
        ])->values();

        /** @var User $user */
        $user = auth()->user();

        return response()->json([
            'accounts' => $accounts,
            'balance_history' => $balanceHistory,
            'net_worth_history' => $netWorthHistory,
            'date_range' => [
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
            ],
            'granularity' => $granularity,
            'is_converted' => $isMultiCurrency,
            'display_currency' => $user->base_currency ?? 'EUR',
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

    /**
     * Get monthly comparison data for two specified months.
     */
    public function monthlyComparison(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account_ids' => 'required|array',
            'account_ids.*' => 'integer|exists:accounts,id',
            'first_month' => 'required|date_format:Y-m',
            'second_month' => 'required|date_format:Y-m',
        ]);

        $userAccounts = $this->accountRepository->findByUser($this->getAuthUserId());

        $selectedIds = $validated['account_ids'];
        $accountIds = $userAccounts->pluck('id')
            ->intersect($selectedIds)
            ->values()
            ->toArray();

        if (empty($accountIds)) {
            return response()->json([
                'first_month' => [],
                'second_month' => [],
            ]);
        }

        $selectedAccounts = $userAccounts->whereIn('id', $accountIds)->values();
        $useNativeAmount = $this->isMultiCurrency($selectedAccounts);

        $firstMonth = Carbon::createFromFormat('Y-m', $validated['first_month']);
        $secondMonth = Carbon::createFromFormat('Y-m', $validated['second_month']);

        $firstStart = $firstMonth->copy()->startOfMonth();
        $firstEnd = $firstMonth->copy()->endOfMonth()->endOfDay();

        $secondStart = $secondMonth->copy()->startOfMonth();
        $secondEnd = $secondMonth->copy()->endOfMonth()->endOfDay();

        $firstMonthCashflow = $this->analyticsRepository->getCashflow($accountIds, $firstStart, $firstEnd, $useNativeAmount);
        $secondMonthCashflow = $this->analyticsRepository->getCashflow($accountIds, $secondStart, $secondEnd, $useNativeAmount);

        return response()->json([
            'first_month' => $firstMonthCashflow->values()->all(),
            'second_month' => $secondMonthCashflow->values()->all(),
        ]);
    }

    /**
     * Check if selected accounts span multiple currencies.
     */
    private function isMultiCurrency(\Illuminate\Support\Collection $accounts): bool
    {
        $currencies = $accounts
            ->pluck('currency')
            ->filter(fn ($currency) => $currency !== null && $currency !== '')
            ->unique();

        return $currencies->count() > 1;
    }

    /**
     * Convert balance history points to user's base currency for multi-currency accounts.
     *
     * @param  array<int, array<array{date: string, balance: float}>>  $balanceHistory
     * @return array<int, array<array{date: string, balance: float}>>
     */
    private function convertBalanceHistoryToBaseCurrency(
        array $balanceHistory,
        \Illuminate\Support\Collection $userAccounts,
        array $accountIds
    ): array {
        /** @var User $user */
        $user = auth()->user();
        $baseCurrency = $user->base_currency ?? 'EUR';

        $result = [];
        foreach ($balanceHistory as $accountId => $history) {
            $account = $userAccounts->firstWhere('id', $accountId);
            $accountCurrency = $account?->currency ?? $baseCurrency;

            if ($accountCurrency === $baseCurrency) {
                $result[$accountId] = $history;

                continue;
            }

            // Pre-fetch a single rate for this currency pair (most points share the same rate via cache)
            $result[$accountId] = array_map(function (array $point) use ($accountCurrency, $baseCurrency): array {
                $date = Carbon::parse($point['date']);
                $rate = $this->exchangeRateService->getRate($accountCurrency, $baseCurrency, $date);

                return [
                    'date' => $point['date'],
                    'balance' => round($point['balance'] * $rate, 2),
                ];
            }, $history);
        }

        return $result;
    }
}
