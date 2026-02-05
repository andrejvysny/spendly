<?php

namespace App\Http\Controllers\Accounts;

use App\Contracts\Repositories\AccountRepositoryInterface;
use App\Contracts\Repositories\AnalyticsRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\AccountRequest;
use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function __construct(
        private AccountRepositoryInterface $accountRepository,
        private AnalyticsRepositoryInterface $analyticsRepository
    ) {}

    public function index(): JsonResponse|Response
    {
        $accounts = $this->accountRepository->findByUser(auth()->id());

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

        // Calculate monthly summaries for the current page only (exclude transfers from income/expense)
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
            if ($transaction->type !== Transaction::TYPE_TRANSFER) {
                if ($transaction->amount > 0) {
                    $monthlySummaries[$month]['income'] += $transaction->amount;
                } else {
                    $monthlySummaries[$month]['expense'] += abs($transaction->amount);
                }
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

    public function destroy(int $id): \Illuminate\Http\RedirectResponse
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

    /**
     * @param  array<int>  $accountIds
     * @return \Illuminate\Support\Collection<int, object>
     */
    public function getCashflowOfMonth(array $accountIds, int $before_month = 0): \Illuminate\Support\Collection
    {
        return $this->analyticsRepository->getMonthlyCashflow($accountIds, $before_month);
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
