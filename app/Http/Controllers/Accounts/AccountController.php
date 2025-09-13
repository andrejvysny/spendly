<?php

namespace App\Http\Controllers\Accounts;

use App\Http\Controllers\Controller;
use App\Http\Requests\AccountRequest;
use App\Models\Account;
use App\Repositories\AccountRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function __construct(
        private AccountRepository $accountRepository
    )
    {
        if (!auth()->id()) {
            throw new \Exception('User not authenticated');
        }
    }

    public function index(): JsonResponse|Response
    {
        $accounts = $this->accountRepository->findByUserId(auth()->id());

        if (request()->wantsJson()) {
            return response()->json([
                'accounts' => $accounts,
            ]);
        }

        return Inertia::render('accounts/index', [
            'accounts' => $accounts,
        ]);
    }

    public function store(AccountRequest $request): RedirectResponse
    {
        try {
            $validated = $request->validated();

            $this->accountRepository->create(
                [
                    'name' => $validated['name'],
                    'bank_name' => $validated['bank_name'] ?? null,
                    'iban' => $validated['iban'] ?? null,
                    'type' => $validated['type'],
                    'currency' => $validated['currency'],
                    'balance' => $validated['balance'],
                    'is_gocardless_synced' => $validated['is_gocardless_synced'] ?? false,
                    'gocardless_account_id' => $validated['gocardless_account_id'] ?? null,
                    'user_id' => auth()->id(),
                ]
            );

            return redirect()->back()->with('success', 'Account created successfully');

        } catch (\Exception $e) {
            \Log::error('Account creation failed: '.$e->getMessage());

            return redirect()->back()->with('error', 'Failed to create account: '.$e->getMessage());
        }
    }

    public function show(Account $account): Response|RedirectResponse
    {
        // Get initial paginated transactions for this account (first page only)
        $transactions = $account->transactions()
            ->with(['category', 'merchant', 'tags'])
            ->orderBy('booked_date', 'desc')
            ->paginate(100); // Use same pagination count as TransactionController

        $total_transactions = $account->transactions()->count();

        // Calculate monthly summaries for the current page only
        $monthlySummaries = [];
        foreach ($transactions->items() as $transaction) {
            $month = \Carbon\Carbon::parse($transaction->booked_date)->translatedFormat('F Y');
            if (! isset($monthlySummaries[$month])) {
                $monthlySummaries[$month] = [
                    'income' => 0,
                    'expense' => 0,
                    'balance' => 0,
                ];
            }
            if ($transaction->amount > 0) {
                $monthlySummaries[$month]['income'] += $transaction->amount;
            } else {
                $monthlySummaries[$month]['expense'] += abs($transaction->amount);
            }
            $monthlySummaries[$month]['balance'] += $transaction->amount;
        }

        $cashflow_last_month = $this->getCashflowOfMonth([$account->id], 1);
        $cashflow_this_month = $this->getCashflowOfMonth([$account->id], 0);

        // Get categories and merchants for the filter dropdowns
        $categories = auth()->user()->categories;
        $merchants = auth()->user()->merchants;

        return Inertia::render('accounts/detail', [
            'account' => $account,
            'cashflow_last_month' => $cashflow_last_month,
            'cashflow_this_month' => $cashflow_this_month,
            'transactions' => [
                'data' => $transactions->items(),
                'current_page' => $transactions->currentPage(),
                'has_more_pages' => $transactions->hasMorePages(),
                'last_page' => $transactions->lastPage(),
                'total' => $transactions->total(),
            ],
            'monthlySummaries' => $monthlySummaries,
            'total_transactions' => $total_transactions,
            'categories' => $categories,
            'merchants' => $merchants,
        ]);
    }

    public function destroy($id)
    {

        $account = Account::where('user_id', auth()->id())->findOrFail($id);

        // Delete all associated transactions first
        $account->transactions()->delete();

        // Delete the account
        $account->delete();

        $this->accountRepository->delete($account);

        return redirect()->route('accounts.index')
            ->with('success', 'Account and all associated transactions have been deleted successfully');

    }

    public function getCashflowOfMonth($accountIds, $before_month = 0)
    {
        $startDate = now()->subMonths($before_month)->startOfMonth();
        $endDate = now()->subMonths($before_month)->endOfMonth();

        $cashflow = \DB::table('transactions')
            ->select(
                \DB::raw('CAST(strftime("%Y", processed_date) AS INTEGER) as year'),
                \DB::raw('CAST(strftime("%m", processed_date) AS INTEGER) as month'),
                \DB::raw('CAST(strftime("%d", processed_date) AS INTEGER) as day'),
                \DB::raw('COUNT(*) as transaction_count'),
                \DB::raw('SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END) as daily_spending'),
                \DB::raw('SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as daily_income'),
                \DB::raw('SUM(amount) as daily_balance')
            )
            ->whereIn('account_id', $accountIds)
            ->where('processed_date', '>=', $startDate)
            ->where('processed_date', '<=', $endDate)
            ->groupBy(
                \DB::raw('strftime("%Y", processed_date)'),
                \DB::raw('strftime("%m", processed_date)'),
                \DB::raw('strftime("%d", processed_date)')
            )
            ->orderBy('year')
            ->orderBy('month')
            ->orderBy('day')
            ->get();

        return $cashflow;
    }

    /**
     * Update sync options for an account.
     */
    public function updateSyncOptions(Request $request, string|int $id): JsonResponse
    {
        try {
            $account = Account::where('user_id', auth()->id())->findOrFail($id);

            $validated = $request->validate([
                'update_existing' => 'boolean',
                'force_max_date_range' => 'boolean',
            ]);

            // Merge with existing sync options to preserve any other settings
            $currentOptions = $account->sync_options ?? [];
            $updatedOptions = array_merge($currentOptions, $validated);

            $account->update([
                'sync_options' => $updatedOptions,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Sync options updated successfully',
                'sync_options' => $updatedOptions,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to update sync options: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to update sync options: '.$e->getMessage(),
            ], 500);
        }
    }
}
