<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Transaction;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $accounts = Account::where('user_id', $user->id)->get();

        $recentTransactions = Transaction::whereIn('account_id', $accounts->pluck('id'))
            ->orderBy('booked_date', 'desc')
            ->limit(10)
            ->get();

        // Calculate monthly balances for each account
        $monthlyBalances = [];
        $startDate = now()->startOfYear();
        $endDate = now();

        // Initialize array with all months for each account
        foreach ($accounts as $account) {
            $monthlyBalances[$account->id] = [];
            for ($date = $startDate->copy(); $date <= $endDate; $date->addMonth()) {
                $monthlyBalances[$account->id][$date->format('M')] = 0;
            }
        }

        // Get all transactions from start of year grouped by account
        foreach ($accounts as $account) {
            $accountTransactions = Transaction::where('account_id', $account->id)
                ->where('booked_date', '>=', $startDate)
                ->orderBy('booked_date', 'asc')
                ->get();

            // Calculate running balance for each month per account
            $runningBalance = 0;
            foreach ($accountTransactions as $transaction) {
                $month = \Carbon\Carbon::parse($transaction->booked_date)->format('M');
                $runningBalance += $transaction->amount;
                $monthlyBalances[$account->id][$month] = $runningBalance;
            }

            // Fill forward for each account - ensure each month has the latest known balance
            $lastBalance = 0;
            foreach ($monthlyBalances[$account->id] as $month => $balance) {
                if ($balance == 0) {
                    $monthlyBalances[$account->id][$month] = $lastBalance;
                } else {
                    $lastBalance = $balance;
                }
            }
        }

        return Inertia::render('dashboard', [
            'accounts' => $accounts,
            'recentTransactions' => $recentTransactions,
            'monthlyBalances' => $monthlyBalances,
        ]);
    }
}
