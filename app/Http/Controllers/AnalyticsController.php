<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AnalyticsController extends Controller
{
    public function index(Request $request)
    {
        $user_accounts = \App\Models\Account::where('user_id', auth()->user()->id)->get();
        $user_categories = \App\Models\Category::where('user_id', auth()->user()->id)->get();
        
        // Handle account selection
        $selectedAccountIds = $request->input('account_ids', []);
        
        // If no accounts selected or invalid input, use all user accounts
        if (empty($selectedAccountIds)) {
            $accountIds = $user_accounts->pluck('id');
        } else {
            // Ensure we get only unique, valid account IDs that belong to the user
            $accountIds = collect($selectedAccountIds)
                ->unique()  // Remove duplicates
                ->filter(function($id) use ($user_accounts) {
                    return $user_accounts->contains('id', $id);
                })
                ->values(); // Reset array keys to prevent issues
        }

        // Parse date range from request
        $dateRange = $this->parseDateRange($request);
        $startDate = $dateRange['start'];
        $endDate = $dateRange['end'];
        
        // Get data for analytics
        $cashflow = $this->getCashflowData($accountIds, $startDate, $endDate);
        $categorySpending = $this->getCategorySpending($accountIds, $startDate, $endDate);
        $merchantSpending = $this->getMerchantSpending($accountIds, $startDate, $endDate);
        
        return Inertia::render('Analytics/Index', [
            'accounts' => $user_accounts,
            'categories' => $user_categories,  // Add categories to the response
            'selectedAccountIds' => $accountIds->toArray(),
            'cashflow' => $cashflow,
            'categorySpending' => $categorySpending,
            'merchantSpending' => $merchantSpending,
            'dateRange' => [
                'start' => $startDate->format('Y-m-d'),
                'end' => $endDate->format('Y-m-d')
            ],
            'period' => $request->input('period', 'last_month')
        ]);
    }

    /**
     * Parse date range from request
     */
    private function parseDateRange(Request $request)
    {
        $period = $request->input('period', 'last_month');
        $customStart = $request->input('start_date');
        $customEnd = $request->input('end_date');
        $specificMonth = $request->input('specific_month'); // Format: YYYY-MM
        
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
            'end' => $end
        ];
    }

    /**
     * Get cashflow data for the specified accounts and date range
     */
    public function getCashflowData($accountIds, $startDate = null, $endDate = null, $months = 12)
    {
        if (!$startDate) {
            $startDate = now()->subMonths($months)->startOfMonth();
        }
        
        // If no accounts selected or explicitly selected accounts with no data, return empty collection
        if ($accountIds->isEmpty()) {
            return collect();
        }
        
        // Convert to array to ensure proper SQL query generation
        $accountIdsArray = $accountIds->toArray();
        
        $query = DB::table('transactions')
            ->whereIn('account_id', $accountIdsArray)
            ->where('processed_date', '>=', $startDate);
        
        if ($endDate) {
            $query->where('processed_date', '<=', $endDate);
        }
        
        // Check if we're looking at a date range less than 32 days
        $daysDiff = $startDate->diffInDays($endDate);
        $isShortRange = $daysDiff < 32;
        
        if ($isShortRange) {
            // Get all transactions for the period to calculate running balance
            $transactions = $query->select(
                'processed_date',
                'amount'
            )
            ->orderBy('processed_date')
            ->get();

            // Calculate daily balances
            $dailyBalances = [];
            
            foreach ($transactions as $transaction) {
                $date = Carbon::parse($transaction->processed_date);
                $dateKey = $date->format('Y-m-d');
                
                if (!isset($dailyBalances[$dateKey])) {
                    $dailyBalances[$dateKey] = [
                        'year' => (int)$date->format('Y'),
                        'month' => (int)$date->format('m'),
                        'day' => (int)$date->format('d'),
                        'transaction_count' => 0,
                        'total_income' => 0,
                        'total_expenses' => 0,
                        'day_balance' => 0
                    ];
                }
                
                $dailyBalances[$dateKey]['transaction_count']++;
                if ($transaction->amount > 0) {
                    $dailyBalances[$dateKey]['total_income'] += $transaction->amount;
                } else {
                    $dailyBalances[$dateKey]['total_expenses'] += abs($transaction->amount);
                }
                
                // Calculate net balance as income - expenses
                $dailyBalances[$dateKey]['day_balance'] = 
                    $dailyBalances[$dateKey]['total_income'] - $dailyBalances[$dateKey]['total_expenses'];
            }
            
            // Convert to array and sort by date
            $result = collect($dailyBalances)->values()->sortBy(function($item) {
                return sprintf('%04d-%02d-%02d', $item['year'], $item['month'], $item['day']);
            });
            
            return $result;
        } else {
            // For monthly view, calculate monthly balances
            $transactions = $query->select(
                'processed_date',
                'amount'
            )
            ->orderBy('processed_date')
            ->get();

            // Calculate monthly balances
            $monthlyBalances = [];
            
            foreach ($transactions as $transaction) {
                $date = Carbon::parse($transaction->processed_date);
                $monthKey = $date->format('Y-m');
                
                if (!isset($monthlyBalances[$monthKey])) {
                    $monthlyBalances[$monthKey] = [
                        'year' => (int)$date->format('Y'),
                        'month' => (int)$date->format('m'),
                        'transaction_count' => 0,
                        'total_income' => 0,
                        'total_expenses' => 0,
                        'month_balance' => 0
                    ];
                }
                
                $monthlyBalances[$monthKey]['transaction_count']++;
                if ($transaction->amount > 0) {
                    $monthlyBalances[$monthKey]['total_income'] += $transaction->amount;
                } else {
                    $monthlyBalances[$monthKey]['total_expenses'] += abs($transaction->amount);
                }
                
                // Calculate net balance as income - expenses
                $monthlyBalances[$monthKey]['month_balance'] = 
                    $monthlyBalances[$monthKey]['total_income'] - $monthlyBalances[$monthKey]['total_expenses'];
            }
            
            // Convert to array and sort by date
            $result = collect($monthlyBalances)->values()->sortBy(function($item) {
                return sprintf('%04d-%02d', $item['year'], $item['month']);
            });
            
            return $result;
        }
    }
    
    /**
     * Get spending by category for the specified date range
     */
    private function getCategorySpending($accountIds, $startDate, $endDate)
    {
        if ($accountIds->isEmpty()) {
            return collect();
        }
        $accountIdsArray = $accountIds->toArray();
        $categorized = DB::table('transactions')
            ->join('categories', 'transactions.category_id', '=', 'categories.id')
            ->select(
                'categories.name as category',
                DB::raw('SUM(ABS(amount)) as total'),
                DB::raw('COUNT(*) as count')
            )
            ->whereIn('transactions.account_id', $accountIdsArray)
            ->where('transactions.processed_date', '>=', $startDate)
            ->where('transactions.processed_date', '<=', $endDate)
            ->where('transactions.amount', '<', 0)
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
            ->whereIn('account_id', $accountIdsArray)
            ->where('processed_date', '>=', $startDate)
            ->where('processed_date', '<=', $endDate)
            ->where('amount', '<', 0)
            ->whereNull('category_id')
            ->first();
        return [
            'categorized' => $categorized,
            'uncategorized' => $uncategorized
        ];
    }
    
    /**
     * Get spending by merchant for the specified date range
     */
    private function getMerchantSpending($accountIds, $startDate, $endDate)
    {
        if ($accountIds->isEmpty()) {
            return collect();
        }
        $accountIdsArray = $accountIds->toArray();
        $withMerchant = DB::table('transactions')
            ->join('merchants', 'transactions.merchant_id', '=', 'merchants.id')
            ->select(
                'merchants.name as merchant',
                DB::raw('SUM(ABS(transactions.amount)) as total'),
                DB::raw('COUNT(*) as count')
            )
            ->whereIn('transactions.account_id', $accountIdsArray)
            ->where('transactions.processed_date', '>=', $startDate)
            ->where('transactions.processed_date', '<=', $endDate)
            ->where('transactions.amount', '<', 0)
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
            ->whereIn('account_id', $accountIdsArray)
            ->where('processed_date', '>=', $startDate)
            ->where('processed_date', '<=', $endDate)
            ->where('amount', '<', 0)
            ->whereNull('merchant_id')
            ->first();
        return [
            'withMerchant' => $withMerchant,
            'noMerchant' => $noMerchant
        ];
    }
}
