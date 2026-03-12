<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\Repositories\AccountRepositoryInterface;
use App\Contracts\Repositories\AnalyticsRepositoryInterface;
use App\Contracts\Repositories\TransactionRepositoryInterface;
use App\Models\RecurringGroup;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BudgetService;
use App\Services\ExchangeRateService;
use Carbon\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private AccountRepositoryInterface $accountRepository,
        private TransactionRepositoryInterface $transactionRepository,
        private AnalyticsRepositoryInterface $analyticsRepository,
        private BudgetService $budgetService,
        private ExchangeRateService $exchangeRateService,
    ) {}

    public function index(): Response
    {
        /** @var User $user */
        $user = auth()->user();
        $userId = (int) $user->id;
        $accounts = $this->accountRepository->findByUser($userId);
        /** @var array<int> $accountIds */
        $accountIds = $accounts->pluck('id')->toArray();

        // Check if accounts span multiple currencies
        $isMultiCurrency = $accounts->pluck('currency')->filter()->unique()->count() > 1;
        $baseCurrency = $user->base_currency ?? 'EUR';

        // Recent Transactions (5)
        $recentTransactions = $this->transactionRepository->getRecentByAccounts($accountIds, 5);

        // Current month stats (use native_amount for multi-currency)
        $currentMonthStart = now()->startOfMonth();
        $currentMonthEnd = now()->endOfMonth();
        $currentMonthStats = $this->getMonthStats($accountIds, $currentMonthStart, $currentMonthEnd, $isMultiCurrency);

        // Previous month stats
        $prevMonthStart = now()->subMonth()->startOfMonth();
        $prevMonthEnd = now()->subMonth()->endOfMonth();
        $previousMonthStats = $this->getMonthStats($accountIds, $prevMonthStart, $prevMonthEnd, $isMultiCurrency);

        // Expenses by Category (Current Month)
        $expensesByCategory = $this->getExpensesByCategory($accountIds, $currentMonthStart, $currentMonthEnd, $currentMonthStats['expenses'], $isMultiCurrency);

        // Balance History (12 months)
        $balanceStart = now()->subMonths(11)->startOfMonth();
        $balanceEnd = now()->endOfDay();
        /** @var array<int, float> $currentBalances */
        $currentBalances = $accounts
            ->pluck('balance', 'id')
            ->map(fn ($balance) => (float) $balance)
            ->toArray();

        // For multi-currency: convert balances to base currency
        if ($isMultiCurrency) {
            $today = Carbon::today();
            foreach ($accounts as $account) {
                if ($account->currency !== $baseCurrency && $account->currency !== null) {
                    $currentBalances[$account->id] = $this->exchangeRateService->convert(
                        (float) $account->balance,
                        $account->currency,
                        $baseCurrency,
                        $today
                    );
                }
            }
        }

        $monthlyBalances = $this->analyticsRepository->getBalanceHistory(
            $accountIds,
            $currentBalances,
            $balanceStart,
            $balanceEnd,
            'month'
        );

        // Previous net worth from balance history
        $prevMonthLabel = now()->subMonth()->format('M Y');
        $previousNetWorth = 0.0;
        foreach ($monthlyBalances as $accountHistory) {
            foreach ($accountHistory as $point) {
                if ($point['date'] === $prevMonthLabel) {
                    $previousNetWorth += $point['balance'];
                }
            }
        }

        // Budget progress (top 5 active monthly budgets)
        $budgetProgress = $this->getBudgetProgress($userId);

        // Top counterparties
        $topCounterparties = $this->getTopCounterparties($accountIds, $currentMonthStart, $currentMonthEnd);

        // Upcoming recurring payments
        $upcomingRecurring = $this->getUpcomingRecurring($userId);

        // Spending pace
        $spendingPace = $this->getSpendingPace($currentMonthStats['expenses']);

        return Inertia::render('dashboard', [
            'accounts' => $accounts,
            'recentTransactions' => $recentTransactions,
            'monthlyBalances' => $monthlyBalances,
            'currentMonthStats' => $currentMonthStats,
            'previousMonthStats' => $previousMonthStats,
            'previousNetWorth' => $previousNetWorth,
            'expensesByCategory' => $expensesByCategory,
            'budgetProgress' => $budgetProgress,
            'topCounterparties' => $topCounterparties,
            'upcomingRecurring' => $upcomingRecurring,
            'spendingPace' => $spendingPace,
            'is_converted' => $isMultiCurrency,
        ]);
    }

    /**
     * @param  array<int>  $accountIds
     * @return array{income: float, expenses: float}
     */
    private function getMonthStats(array $accountIds, Carbon $start, Carbon $end, bool $useNativeAmount = false): array
    {
        if ($accountIds === []) {
            return ['income' => 0.0, 'expenses' => 0.0];
        }

        $amountCol = $useNativeAmount ? 'native_amount' : 'amount';

        $stats = Transaction::whereIn('account_id', $accountIds)
            ->whereBetween('booked_date', [$start, $end])
            ->where('type', '!=', Transaction::TYPE_TRANSFER)
            ->selectRaw("
                SUM(CASE WHEN {$amountCol} > 0 THEN {$amountCol} ELSE 0 END) as income,
                SUM(CASE WHEN {$amountCol} < 0 THEN {$amountCol} ELSE 0 END) as expenses
            ")
            ->first();

        return [
            'income' => (float) ($stats->income ?? 0),
            'expenses' => (float) ($stats->expenses ?? 0),
        ];
    }

    /**
     * @param  array<int>  $accountIds
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function getExpensesByCategory(array $accountIds, Carbon $start, Carbon $end, float $totalExpenses, bool $useNativeAmount = false): \Illuminate\Support\Collection
    {
        if ($accountIds === []) {
            return collect();
        }

        $amountCol = $useNativeAmount ? 'native_amount' : 'amount';

        $expenses = Transaction::whereIn('account_id', $accountIds)
            ->whereBetween('booked_date', [$start, $end])
            ->where($amountCol, '<', 0)
            ->where('type', '!=', Transaction::TYPE_TRANSFER)
            ->whereNotNull('category_id')
            ->with('category')
            ->selectRaw("category_id, SUM(ABS({$amountCol})) as total_amount")
            ->groupBy('category_id')
            ->orderByDesc('total_amount')
            ->limit(5)
            ->get();

        $absTotal = $totalExpenses != 0 ? abs($totalExpenses) : 1;

        /** @var \Illuminate\Support\Collection<int, array<string, mixed>> $result */
        $result = $expenses->map(function (Transaction $item) use ($absTotal): array {
            /** @var float $amount */
            $amount = $item->getAttribute('total_amount');
            $amount = (float) $amount;
            /** @var \App\Models\Category|null $cat */
            $cat = $item->category;

            /** @var string $catName */
            $catName = $cat !== null ? $cat->getAttribute('name') : 'Unknown';
            /** @var string $catColor */
            $catColor = $cat !== null ? $cat->getAttribute('color') : '#9CA3AF';

            return [
                'id' => $item->category_id,
                'name' => $catName,
                'color' => $catColor,
                'amount' => $amount,
                'percentage' => round(($amount / $absTotal) * 100, 1),
            ];
        });

        return $result;
    }

    /**
     * @return array<int, array{name: string, budgeted: float, spent: float, percentage: float, is_exceeded: bool, category_color: string|null, currency: string}>
     */
    private function getBudgetProgress(int $userId): array
    {
        $now = now();
        $progress = $this->budgetService->getBudgetsWithProgress(
            $userId,
            'monthly',
            (int) $now->format('Y'),
            (int) $now->format('n')
        );

        /** @var array<int, array{name: string, budgeted: float, spent: float, percentage: float, is_exceeded: bool, category_color: string|null, currency: string}> $result */
        $result = $progress
            ->filter(fn (array $item): bool => (bool) $item['budget']->is_active)
            ->sortByDesc('percentage_used')
            ->take(5)
            ->map(function (array $item): array {
                /** @var \App\Models\Budget $budget */
                $budget = $item['budget'];
                /** @var \App\Models\BudgetPeriod|null $period */
                $period = $item['period'];

                /** @var \App\Models\Category|null $category */
                $category = $budget->category;

                /** @var string|null $budgetName */
                $budgetName = $budget->getAttribute('name');
                /** @var string|null $categoryName */
                $categoryName = $category !== null ? $category->getAttribute('name') : null;
                /** @var string|null $categoryColor */
                $categoryColor = $category !== null ? $category->getAttribute('color') : null;

                return [
                    'name' => $budgetName ?? ($categoryName ?? 'Budget'),
                    'budgeted' => (float) ($period !== null ? $period->getEffectiveAmount() : $budget->amount),
                    'spent' => (float) $item['spent'],
                    'percentage' => (float) $item['percentage_used'],
                    'is_exceeded' => (bool) $item['is_exceeded'],
                    'category_color' => $categoryColor,
                    'currency' => $budget->currency ?? 'EUR',
                ];
            })
            ->values()
            ->toArray();

        return $result;
    }

    /**
     * @param  array<int>  $accountIds
     * @return array<int, array{name: string, amount: float, transaction_count: int}>
     */
    private function getTopCounterparties(array $accountIds, Carbon $start, Carbon $end): array
    {
        $result = $this->analyticsRepository->getCounterpartySpending($accountIds, $start, $end);

        /** @var array<int, array{name: string, amount: float, transaction_count: int}> $counterparties */
        $counterparties = $result['withCounterparty']->take(5)->map(function (mixed $m): array {
            $obj = (object) $m;

            return [
                'name' => (string) ($obj->counterparty ?? ''),
                'amount' => (float) ($obj->total ?? 0),
                'transaction_count' => (int) ($obj->count ?? 0),
            ];
        })->values()->toArray();

        return $counterparties;
    }

    /**
     * @return array<int, array{name: string, amount: float, next_date: string|null, interval: string, counterparty_name: string|null}>
     */
    private function getUpcomingRecurring(int $userId): array
    {
        $cutoff = now()->addDays(30)->format('Y-m-d');
        $today = now()->format('Y-m-d');

        /** @var \Illuminate\Database\Eloquent\Collection<int, RecurringGroup> $groups */
        $groups = RecurringGroup::where('user_id', $userId)
            ->where('status', RecurringGroup::STATUS_CONFIRMED)
            ->withCount('transactions')
            ->withSum('transactions', 'amount')
            ->withMin('transactions', 'booked_date')
            ->withMax('transactions', 'booked_date')
            ->with('counterparty')
            ->get();

        /** @var array<int, array{name: string, amount: float, next_date: string|null, interval: string, counterparty_name: string|null}> $result */
        $result = $groups
            ->map(function (RecurringGroup $group): array {
                $stats = $group->stats;
                $nextDate = $stats['next_expected_payment'] ?? null;
                $avgAmount = $stats['average_amount'] ?? 0;
                /** @var \App\Models\Counterparty|null $counterparty */
                $counterparty = $group->counterparty;

                /** @var string $groupName */
                $groupName = $group->getAttribute('name') ?? 'Unknown';
                /** @var string|null $counterpartyName */
                $counterpartyName = $counterparty !== null ? $counterparty->getAttribute('name') : null;

                return [
                    'name' => $groupName,
                    'amount' => (float) $avgAmount,
                    'next_date' => $nextDate,
                    'interval' => $group->interval ?? 'monthly',
                    'counterparty_name' => $counterpartyName,
                ];
            })
            ->filter(function (array $item) use ($cutoff, $today): bool {
                return $item['next_date'] !== null && $item['next_date'] <= $cutoff && $item['next_date'] >= $today;
            })
            ->sortBy('next_date')
            ->take(10)
            ->values()
            ->toArray();

        return $result;
    }

    /**
     * @return array{daily_average: float, projected_total: float, days_elapsed: int, days_in_month: int}
     */
    private function getSpendingPace(float $totalExpenses): array
    {
        $daysElapsed = (int) now()->day;
        $daysInMonth = (int) now()->daysInMonth;
        $absExpenses = abs($totalExpenses);
        $dailyAvg = $daysElapsed > 0 ? round($absExpenses / $daysElapsed, 2) : 0.0;
        $projected = round($dailyAvg * $daysInMonth, 2);

        return [
            'daily_average' => $dailyAvg,
            'projected_total' => $projected,
            'days_elapsed' => $daysElapsed,
            'days_in_month' => $daysInMonth,
        ];
    }
}
