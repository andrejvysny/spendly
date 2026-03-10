<?php

namespace App\Http\Controllers;

use App\Contracts\Repositories\AccountRepositoryInterface;
use App\Contracts\Repositories\AnalyticsRepositoryInterface;
use App\Contracts\Repositories\TransactionRepositoryInterface;
use App\Models\Transaction;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __construct(
        private AccountRepositoryInterface $accountRepository,
        private TransactionRepositoryInterface $transactionRepository,
        private AnalyticsRepositoryInterface $analyticsRepository,
    ) {}

    public function index()
    {
        $user = auth()->user();
        $accounts = $this->accountRepository->findByUser($user->id);
        $accountIds = $accounts->pluck('id')->toArray();

        // 1. Recent Transactions
        $recentTransactions = $this->transactionRepository->getRecentByAccounts(
            $accountIds,
            10
        );

        // 2. Monthly Income & Expenses (Current Month)
        $currentMonthStart = now()->startOfMonth();
        $currentMonthEnd = now()->endOfMonth();

        $monthlyStats = Transaction::whereIn('account_id', $accountIds)
            ->whereBetween('booked_date', [$currentMonthStart, $currentMonthEnd])
            ->where('type', '!=', Transaction::TYPE_TRANSFER) // Exclude internal transfers if marked
            ->selectRaw('
                SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as income,
                SUM(CASE WHEN amount < 0 THEN amount ELSE 0 END) as expenses
            ')
            ->first();

        $currentMonthStats = [
            'income' => (float) ($monthlyStats->income ?? 0),
            'expenses' => (float) ($monthlyStats->expenses ?? 0),
        ];

        // 3. Expenses by Category (Current Month)
        $expensesByCategory = Transaction::whereIn('account_id', $accountIds)
            ->whereBetween('booked_date', [$currentMonthStart, $currentMonthEnd])
            ->where('amount', '<', 0)
            ->where('type', '!=', Transaction::TYPE_TRANSFER)
            ->whereNotNull('category_id')
            ->with('category')
            ->selectRaw('category_id, SUM(ABS(amount)) as total_amount')
            ->groupBy('category_id')
            ->orderByDesc('total_amount')
            ->limit(5)
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->category_id,
                    'name' => $item->category->name ?? 'Unknown',
                    'color' => $item->category->color ?? '#9CA3AF',
                    'amount' => (float) $item->total_amount,
                ];
            });

        // Calculate total expenses for percentage
        $totalExpenses = $currentMonthStats['expenses'] != 0 ? abs($currentMonthStats['expenses']) : 1; // Avoid division by zero
        $expensesByCategory = $expensesByCategory->map(function ($item) use ($totalExpenses) {
            $item['percentage'] = round(($item['amount'] / $totalExpenses) * 100, 1);
            return $item;
        });

        // 4. Balance History (Back-calculated via shared analytics repository)
        $balanceStart = now()->subMonths(11)->startOfMonth();
        $balanceEnd = now()->endOfDay();
        $currentBalances = $accounts
            ->pluck('balance', 'id')
            ->map(fn ($balance) => (float) $balance)
            ->toArray();
        $monthlyBalances = $this->analyticsRepository->getBalanceHistory(
            $accountIds,
            $currentBalances,
            $balanceStart,
            $balanceEnd,
            'month'
        );

        return Inertia::render('dashboard', [
            'accounts' => $accounts,
            'recentTransactions' => $recentTransactions,
            'monthlyBalances' => $monthlyBalances,
            'currentMonthStats' => $currentMonthStats,
            'expensesByCategory' => $expensesByCategory,
        ]);
    }
}
