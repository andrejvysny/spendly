<?php

namespace App\Http\Controllers;

use App\Contracts\Repositories\AccountRepositoryInterface;
use App\Contracts\Repositories\TransactionRepositoryInterface;
use App\Models\Transaction;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __construct(
        private AccountRepositoryInterface $accountRepository,
        private TransactionRepositoryInterface $transactionRepository
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

        // 4. Balance History (Back-calculated)
        // We want the last 12 months, including current.
        $months = [];
        $date = now()->startOfMonth();
        for ($i = 0; $i < 12; $i++) {
            $months[] = $date->copy();
            $date->subMonth();
        }
        $months = array_reverse($months); // Ascending order: [Jan 2025, ..., Jan 2026]

        $monthlyBalances = [];
        foreach ($accounts as $account) {
            $currentBalance = $account->balance;
            $history = [];
            
            // The balance at the END of the current month (effectively NOW) is the current balance
            // However, we want to plot specific points. simpler: Point X = Balance at end of Month X.
            // Balance(End Month X) = Balance(End Month X+1) - Sum(Transactions in Month X+1) -- Wait
            // Balance (Month T) = Balance (Month T+1) - Transactions(Month T+1)
            // Actually: Balance(End T) = Balance(End T+1) - NetFlow(T+1). 
            // Current Balance is Balance at NOW. 
            // So Balance(Start of Current Month) = Current Balance - Transactions(Since Start of Month).
            
            // Let's get transactions grouped by month for the last 12 months
            $dateFormat = match (config('database.default')) {
                'sqlite' => 'strftime("%Y-%m", booked_date)',
                'pgsql' => "TO_CHAR(booked_date, 'YYYY-MM')",
                default => 'DATE_FORMAT(booked_date, "%Y-%m")',
            };

            $txByMonth = Transaction::where('account_id', $account->id)
                ->where('booked_date', '>=', $months[0])
                ->selectRaw("$dateFormat as month, SUM(amount) as net_amount")
                ->groupBy('month')
                ->pluck('net_amount', 'month');

            // Start from current balance and work backwards
            // For the chart, we usually want value at the end of the month.
            // For the current month, "End" is Now.
            
            $runningBalance = $currentBalance;
            $chartData = [];

            // Iterate backwards from current month
            // We need to loop from specific months.
            // The global $months array is [Oldest -> Newest]
            // Let's iterate Newest -> Oldest to back-calculate
            
            $reversedMonths = array_reverse($months);
            foreach ($reversedMonths as $m) {
                $monthKey = $m->format('Y-m');
                
                // This point represents the balance at the end of month $m
                // For the very first iteration (current month), it's the current balance.
                // For previous months, we subtract the net amount of the *subsequent* month (the one we just processed).
                // Wait, logic check:
                // Balance_End_Jan = 1000.
                // Transactions_Jan = +200.
                // Balance_End_Dec = Balance_End_Jan - Transactions_Jan = 1000 - 200 = 800.
                
                // So, for the current month loop:
                // We add the point (Month, RunningBalance).
                // Then we update RunningBalance for the *previous* month iteration.
                // RunningBalance = RunningBalance - NetChange(CurrentMonth).
                
                $chartData[] = [
                    'date' => $m->format('M Y'),
                    'balance' => (float) $runningBalance
                ];

                $netChange = $txByMonth->get($monthKey) ?? 0;
                $runningBalance -= $netChange;
            }
            // fix order back to ascending
            $monthlyBalances[$account->id] = array_reverse($chartData);
        }

        return Inertia::render('dashboard', [
            'accounts' => $accounts,
            'recentTransactions' => $recentTransactions,
            'monthlyBalances' => $monthlyBalances,
            'currentMonthStats' => $currentMonthStats,
            'expensesByCategory' => $expensesByCategory,
        ]);
    }
}
