<?php

namespace App\Http\Controllers\Transactions;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;

class TransactionController extends Controller
{
    /**
     * Displays a paginated list of the authenticated user's transactions with advanced filtering and summary statistics.
     *
     * Applies filters for search, transaction type, account, amount (with multiple filter types), counterparty, category, and date range. Calculates total and monthly summaries for the filtered transactions. Provides related categories, counterparties, tags, and accounts for filter dropdowns. Returns an Inertia.js response rendering the transactions index view.
     */
    const int PAGINATION_COUNT = 100; // Define a constant for pagination count

    public function index(Request $request): \Inertia\Response
    {
        [$query, $isFiltered] = $this->buildTransactionQuery($request);

        $totalCount = (clone $query)->count();

        // Calculate total summary if filters are active
        $totalSummary = null;
        if ($isFiltered) {
            $countClone = clone $query;
            $incomeSum = clone $query;
            $expenseSum = clone $query;
            $balanceSum = clone $query;
            $categoriesCount = clone $query;
            $counterpartiesCount = clone $query;
            $uncategorizedCount = clone $query;
            $noCounterpartyCount = clone $query;

            $totalSummary = [
                'count' => $countClone->count(),
                'income' => $incomeSum->where('type', '!=', Transaction::TYPE_TRANSFER)->where('amount', '>', 0)->sum('amount'),
                'expense' => abs($expenseSum->where('type', '!=', Transaction::TYPE_TRANSFER)->where('amount', '<', 0)->sum('amount')),
                'balance' => $balanceSum->sum('amount'),
                'categoriesCount' => $categoriesCount->whereNotNull('category_id')->distinct('category_id')->count('category_id'),
                'counterpartiesCount' => $counterpartiesCount->whereNotNull('counterparty_id')->distinct('counterparty_id')->count('counterparty_id'),
                'uncategorizedCount' => $uncategorizedCount->whereNull('category_id')->count(),
                'noCounterpartyCount' => $noCounterpartyCount->whereNull('counterparty_id')->count(),
            ];
        }

        // Get paginated transactions
        $transactions = $query->paginate(self::PAGINATION_COUNT);

        // Calculate monthly summaries for the current page (exclude transfers from income/expense)
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

        // Get categories, counterparties, and tags for the filter dropdowns
        $categories = Auth::user()->categories;
        $counterparties = Auth::user()->counterparties;
        $tags = Auth::user()->tags;
        $accounts = Auth::user()->accounts;
        $recurringGroups = Auth::user()->recurringGroups()->where('status', 'confirmed')->get(['id', 'name', 'interval']);

        return Inertia::render('transactions/index', [
            'transactions' => [
                'data' => $transactions->items(),
                'current_page' => $transactions->currentPage(),
                'has_more_pages' => $transactions->hasMorePages(),
                'last_page' => $transactions->lastPage(),
                'total' => $transactions->total(),
            ],
            'monthlySummaries' => $monthlySummaries,
            'totalSummary' => $totalSummary,
            'isFiltered' => $isFiltered,
            'categories' => $categories,
            'counterparties' => $counterparties,
            'accounts' => $accounts,
            'tags' => $tags,
            'recurringGroups' => $recurringGroups,
            'filters' => $request->only([
                'search', 'account_id', 'transactionType',
                'amountFilterType', 'amountMin', 'amountMax',
                'amountExact', 'amountAbove', 'amountBelow',
                'dateFrom', 'dateTo', 'counterparty_id', 'category_id',
                'recurring_only',
            ]),
            'totalCount' => $totalCount,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'direction' => 'required|in:income,expense',
            'currency' => 'required|string|in:EUR,USD,GBP,CZK',
            'booked_date' => 'required|date',
            'description' => 'required|string',
            'type' => 'required|in:PAYMENT,TRANSFER',
            'account_id' => 'required|exists:accounts,id',
            'partner' => 'nullable|string',
            'note' => 'nullable|string',
            'place' => 'nullable|string',
            'category_id' => 'nullable|exists:categories,id',
            'counterparty_id' => 'nullable|exists:counterparties,id',
            'tags' => 'nullable|array',
            'tags.*' => 'exists:tags,id',
            'target_iban' => 'nullable|string',
            'source_iban' => 'nullable|string',
        ]);

        // Verify user owns the account
        $account = Auth::user()->accounts()->findOrFail($validated['account_id']);

        $amount = (float) $validated['amount'];
        if ($validated['direction'] === 'expense') {
            $amount = -$amount;
        }

        $transactionData = [
            'transaction_id' => 'TRX-'.now()->timestamp.'-'.Str::random(6),
            'amount' => $amount,
            'currency' => $validated['currency'],
            'booked_date' => $validated['booked_date'],
            'processed_date' => $validated['booked_date'],
            'description' => $validated['description'],
            'type' => $validated['type'],
            'account_id' => $account->id,
            'balance_after_transaction' => 0,
            'partner' => $validated['partner'] ?? null,
            'note' => $validated['note'] ?? null,
            'place' => $validated['place'] ?? null,
            'category_id' => $validated['category_id'] ?? null,
            'counterparty_id' => $validated['counterparty_id'] ?? null,
            'target_iban' => $validated['target_iban'] ?? null,
            'source_iban' => $validated['source_iban'] ?? null,
        ];

        $transactionData['fingerprint'] = Transaction::generateFingerprint($transactionData);

        // Set native_amount (converted to user's base currency)
        $user = Auth::user();
        $baseCurrency = $user->base_currency ?? 'EUR';
        if ($transactionData['currency'] === $baseCurrency) {
            $transactionData['native_amount'] = $amount;
        } else {
            $transactionData['native_amount'] = app(\App\Services\ExchangeRateService::class)->convert(
                $amount,
                $transactionData['currency'],
                $baseCurrency,
                \Carbon\Carbon::parse($transactionData['booked_date'])
            );
        }

        $transaction = Transaction::create($transactionData);

        if (! empty($validated['tags'])) {
            $transaction->tags()->sync($validated['tags']);
        }

        return redirect()->back()->with('success', 'Transaction created successfully');
    }

    /**
     * Display transactions that need manual review (e.g. flagged during import).
     */
    public function reviewQueue(Request $request): \Inertia\Response
    {
        $query = Transaction::whereHas('account', function (Builder $q) {
            $q->where('user_id', Auth::id());
        })
            ->where('needs_manual_review', true)
            ->with(['account'])
            ->orderByDesc('booked_date');

        if ($request->filled('review_reason')) {
            $query->where('review_reason', 'like', '%'.$request->review_reason.'%');
        }

        $transactions = $query->paginate(50);

        return Inertia::render('transactions/review', [
            'transactions' => $transactions,
            'filters' => $request->only('review_reason'),
        ]);
    }

    /**
     * Retrieves a paginated list of transactions for the authenticated user, applying filters and returning results as JSON.
     *
     * Applies filters for search term, transaction type, account, counterparty, category, and date range. Returns the paginated transactions and a flag indicating if more pages are available.
     *
     * @return JsonResponse JSON response containing filtered transactions and pagination status.
     */
    public function loadMore(Request $request)
    {
        try {
            [$query] = $this->buildTransactionQuery($request);

            $transactions = $query->paginate(self::PAGINATION_COUNT, ['*'], 'page', $request->page);

            // Calculate monthly summaries for the current page (exclude transfers from income/expense)
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

            return response()->json([
                'transactions' => [
                    'data' => $transactions->items(),
                    'current_page' => $transactions->currentPage(),
                    'has_more_pages' => $transactions->currentPage() < $transactions->lastPage(),
                    'last_page' => $transactions->lastPage(),
                    'total' => $transactions->total(),
                ],
                'monthlySummaries' => $monthlySummaries,
                'totalCount' => $transactions->total(),
            ]);
        } catch (\Exception $e) {
            Log::error('Load more transactions failed: '.$e->getMessage());

            return response()->json(['error' => 'Failed to load more transactions'], 500);
        }
    }

    /**
     * Filters transactions based on request parameters and returns a paginated JSON response.
     *
     * Applies filters for search term, transaction type, account, amount (with support for exact, range, above, below), counterparty, category, and date range. Returns paginated transactions, monthly summaries for the current page, total summary statistics, filter status, and pagination info.
     *
     * @return JsonResponse Paginated filtered transactions and summary data.
     */
    public function filter(Request $request): JsonResponse
    {
        try {
            Log::info('Filter request received', ['params' => $request->all()]);

            [$query, $isFiltered] = $this->buildTransactionQuery($request);

            // Calculate total summary directly from the database
            $totalCount = clone $query;
            $incomeSum = clone $query;
            $expenseSum = clone $query;
            $balanceSum = clone $query;
            $categoriesCount = clone $query;
            $counterpartiesCount = clone $query;
            $uncategorizedCount = clone $query;
            $noCounterpartyCount = clone $query;

            $totalSummary = [
                'count' => $totalCount->count(),
                'income' => $incomeSum->where('type', '!=', Transaction::TYPE_TRANSFER)->where('amount', '>', 0)->sum('amount'),
                'expense' => abs($expenseSum->where('type', '!=', Transaction::TYPE_TRANSFER)->where('amount', '<', 0)->sum('amount')),
                'balance' => $balanceSum->sum('amount'),
                'categoriesCount' => $categoriesCount->whereNotNull('category_id')->distinct('category_id')->count('category_id'),
                'counterpartiesCount' => $counterpartiesCount->whereNotNull('counterparty_id')->distinct('counterparty_id')->count('counterparty_id'),
                'uncategorizedCount' => $uncategorizedCount->whereNull('category_id')->count(),
                'noCounterpartyCount' => $noCounterpartyCount->whereNull('counterparty_id')->count(),
            ];

            // Get paginated transactions
            $transactions = $query->paginate(self::PAGINATION_COUNT);

            Log::info('Filtered transactions count: '.$transactions->count().', isFiltered: '.($isFiltered ? 'true' : 'false'));

            // Calculate monthly summaries for the current page (exclude transfers from income/expense)
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

            return response()->json([
                'transactions' => [
                    'data' => $transactions->items(),
                    'current_page' => $transactions->currentPage(),
                    'has_more_pages' => $transactions->hasMorePages(),
                    'last_page' => $transactions->lastPage(),
                    'total' => $transactions->total(),
                ],
                'monthlySummaries' => $monthlySummaries,
                'totalSummary' => $totalSummary,
                'isFiltered' => $isFiltered,
                'totalCount' => $transactions->total(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error in transaction filter: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);

            return response()->json([
                'error' => 'An error occurred while filtering transactions',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Updates the counterparty, category, and tags of a transaction.
     *
     * Validates and applies updates to the specified transaction, including synchronizing associated tags. Redirects back with a success or error message based on the outcome.
     */
    public function update(Request $request, Transaction $transaction): \Illuminate\Http\RedirectResponse
    {
        try {
            $validated = $request->validate([
                'counterparty_id' => 'nullable|exists:counterparties,id',
                'category_id' => 'nullable|exists:categories,id',
                'tags' => 'nullable|array',
                'tags.*' => 'exists:tags,id',
            ]);

            $tagIds = $validated['tags'] ?? [];
            unset($validated['tags']);

            $transaction->update($validated);

            if (isset($request->tags)) {
                $transaction->tags()->sync($tagIds);
            }

            return redirect()->back()->with('success', 'Transaction updated successfully');
        } catch (\Exception $e) {
            Log::error('Transaction update failed: '.$e->getMessage());

            return redirect()->back()->with('error', 'Failed to update transaction: '.$e->getMessage());
        }
    }

    /**
     * Updates the counterparty and/or category fields for multiple transactions in bulk.
     *
     * Validates the request for transaction IDs and optional counterparty and category IDs, then updates the specified fields for each transaction. Returns a JSON response indicating success or failure.
     *
     * @return JsonResponse JSON response with a success message or error details.
     */
    public function bulkUpdate(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'transaction_ids' => 'required|array',
                'transaction_ids.*' => 'exists:transactions,id',
                'counterparty_id' => 'nullable|string',
                'category_id' => 'nullable|string',
                'recurring_group_id' => 'nullable|string',
            ]);

            $transactions = Transaction::whereIn('id', $validated['transaction_ids'])
                ->whereHas('account', fn ($q) => $q->where('user_id', Auth::id()))
                ->get();

            foreach ($transactions as $transaction) {
                $updateData = [];
                if (array_key_exists('counterparty_id', $validated)) {
                    $updateData['counterparty_id'] = $validated['counterparty_id'] === '' ? null : $validated['counterparty_id'];
                }
                if (array_key_exists('category_id', $validated)) {
                    $updateData['category_id'] = $validated['category_id'] === '' ? null : $validated['category_id'];
                }
                if (array_key_exists('recurring_group_id', $validated)) {
                    $updateData['recurring_group_id'] = $validated['recurring_group_id'] === '' ? null : $validated['recurring_group_id'];
                }
                $transaction->update($updateData);
            }

            return response()->json(['message' => 'Transactions updated successfully']);
        } catch (\Exception $e) {
            Log::error('Bulk transaction update failed: '.$e->getMessage());

            return response()->json(['error' => 'Failed to update transactions'], 500);
        }
    }

    /**
     * Updates notes for multiple transactions in bulk.
     *
     * Validates the request for transaction IDs, note content, and method (replace or append).
     * Updates the note field for each transaction according to the specified method.
     *
     * @return JsonResponse JSON response with a success message or error details.
     */
    public function bulkNoteUpdate(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'transaction_ids' => 'required|array',
                'transaction_ids.*' => 'exists:transactions,id',
                'note' => 'required|string',
                'method' => 'required|string|in:replace,append',
            ]);

            $transactions = Transaction::whereIn('id', $validated['transaction_ids'])
                ->whereHas('account', fn ($q) => $q->where('user_id', Auth::id()))
                ->get();
            $updatedTransactions = [];

            foreach ($transactions as $transaction) {
                if ($validated['method'] === 'replace') {
                    $transaction->update(['note' => $validated['note']]);
                } elseif ($validated['method'] === 'append') {
                    $existingNote = $transaction->note ?? '';
                    $newNote = $existingNote ? $existingNote."\n".$validated['note'] : $validated['note'];
                    $transaction->update(['note' => $newNote]);
                }

                // Refresh the transaction to get the updated note
                $transaction->refresh();
                $updatedTransactions[] = [
                    'id' => $transaction->id,
                    'note' => $transaction->note,
                ];
            }

            return response()->json([
                'message' => 'Transaction notes updated successfully',
                'updated_count' => $transactions->count(),
                'updated_transactions' => $updatedTransactions,
            ]);
        } catch (\Exception $e) {
            Log::error('Bulk transaction note update failed: '.$e->getMessage());

            return response()->json(['error' => 'Failed to update transaction notes'], 500);
        }
    }

    /**
     * Updates tags for multiple transactions in bulk with add/remove/set modes.
     *
     * @return JsonResponse JSON response with updated transaction tag data or error details.
     */
    public function bulkTagUpdate(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'transaction_ids' => 'required|array',
                'transaction_ids.*' => 'exists:transactions,id',
                'tag_ids' => 'required|array',
                'tag_ids.*' => 'exists:tags,id',
                'mode' => 'required|string|in:add,remove,set',
            ]);

            $transactions = Transaction::whereIn('id', $validated['transaction_ids'])
                ->whereHas('account', fn ($q) => $q->where('user_id', Auth::id()))
                ->get();

            $updatedTransactions = [];

            foreach ($transactions as $transaction) {
                switch ($validated['mode']) {
                    case 'add':
                        $transaction->tags()->syncWithoutDetaching($validated['tag_ids']);
                        break;
                    case 'remove':
                        $transaction->tags()->detach($validated['tag_ids']);
                        break;
                    case 'set':
                        $transaction->tags()->sync($validated['tag_ids']);
                        break;
                }

                $transaction->load('tags');
                $updatedTransactions[] = [
                    'id' => $transaction->id,
                    'tags' => $transaction->tags,
                ];
            }

            return response()->json([
                'message' => 'Transaction tags updated successfully',
                'updated_transactions' => $updatedTransactions,
            ]);
        } catch (\Exception $e) {
            Log::error('Bulk transaction tag update failed: '.$e->getMessage());

            return response()->json(['error' => 'Failed to update transaction tags'], 500);
        }
    }

    /**
     * Updates the type for multiple transactions in bulk, with optional transfer pairing.
     *
     * @return JsonResponse JSON response with update count and pairing status or error details.
     */
    public function bulkTypeUpdate(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'transaction_ids' => 'required|array',
                'transaction_ids.*' => 'exists:transactions,id',
                'type' => 'required|string|in:TRANSFER,PAYMENT',
                'clear_transfer_pair' => 'boolean',
            ]);

            $clearTransferPair = $validated['clear_transfer_pair'] ?? false;

            $transactions = Transaction::whereIn('id', $validated['transaction_ids'])
                ->whereHas('account', fn ($q) => $q->where('user_id', Auth::id()))
                ->get();

            $paired = false;

            DB::transaction(function () use ($transactions, $validated, $clearTransferPair, &$paired) {
                // Clear transfer pairs if requested
                if ($clearTransferPair) {
                    $partnerIds = $transactions->pluck('transfer_pair_transaction_id')->filter()->toArray();

                    // Null out on selected transactions
                    Transaction::whereIn('id', $transactions->pluck('id'))
                        ->update(['transfer_pair_transaction_id' => null]);

                    // Null out on partner transactions
                    if (! empty($partnerIds)) {
                        Transaction::whereIn('id', $partnerIds)
                            ->update(['transfer_pair_transaction_id' => null]);
                    }

                    // Refresh after clearing
                    $transactions->each->refresh();
                }

                // Update type on all selected
                foreach ($transactions as $transaction) {
                    $transaction->update(['type' => $validated['type']]);
                }

                // Auto-pair: if type=TRANSFER and exactly 2 transactions with inverse amounts
                if ($validated['type'] === Transaction::TYPE_TRANSFER && $transactions->count() === 2) {
                    $first = $transactions->first();
                    $second = $transactions->last();
                    $sum = abs($first->amount + $second->amount);

                    if ($sum <= 0.01) {
                        $first->update(['transfer_pair_transaction_id' => $second->id]);
                        $second->update(['transfer_pair_transaction_id' => $first->id]);
                        $paired = true;
                    }
                }
            });

            return response()->json([
                'message' => 'Transaction types updated successfully',
                'updated_count' => $transactions->count(),
                'paired' => $paired,
            ]);
        } catch (\Exception $e) {
            Log::error('Bulk transaction type update failed: '.$e->getMessage());

            return response()->json(['error' => 'Failed to update transaction types'], 500);
        }
    }

    /**
     * Deletes multiple transactions in bulk, clearing transfer pairs and detaching tags.
     *
     * @return JsonResponse JSON response with deleted count and IDs or error details.
     */
    public function bulkDelete(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'transaction_ids' => 'required|array',
                'transaction_ids.*' => 'exists:transactions,id',
            ]);

            $transactions = Transaction::whereIn('id', $validated['transaction_ids'])
                ->whereHas('account', fn ($q) => $q->where('user_id', Auth::id()))
                ->get();

            $deletedIds = [];

            DB::transaction(function () use ($transactions, &$deletedIds) {
                // Clear transfer pairs on partner transactions
                $partnerIds = $transactions->pluck('transfer_pair_transaction_id')->filter()->toArray();
                if (! empty($partnerIds)) {
                    Transaction::whereIn('id', $partnerIds)
                        ->update(['transfer_pair_transaction_id' => null]);
                }

                foreach ($transactions as $transaction) {
                    $deletedIds[] = $transaction->id;
                    $transaction->tags()->detach();
                    $transaction->delete();
                }
            });

            return response()->json([
                'message' => 'Transactions deleted successfully',
                'deleted_count' => count($deletedIds),
                'deleted_ids' => $deletedIds,
            ]);
        } catch (\Exception $e) {
            Log::error('Bulk transaction delete failed: '.$e->getMessage());

            return response()->json(['error' => 'Failed to delete transactions'], 500);
        }
    }

    /**
     * Updates specific fields of a transaction and returns a JSON response.
     *
     * Validates and updates the transaction's description, note, partner, and place fields if provided.
     *
     * @return JsonResponse JSON response indicating success or failure.
     */
    public function updateTransaction(Request $request, Transaction $transaction): JsonResponse
    {

        $validated = $request->validate([
            'description' => 'nullable|string',
            'note' => 'nullable|string',
            'partner' => 'nullable|string',
            'place' => 'nullable|string',
            'needs_manual_review' => 'nullable|boolean',
        ]);

        try {

            $transaction->update($validated);

            return response()->json(['message' => 'Transaction updated successfully']);
        } catch (\Exception $e) {
            Log::error('Transaction update failed: '.$e->getMessage());

            return response()->json(['error' => 'Failed to update transaction'], 500);
        }
    }

    /**
     * Build the base transaction query and apply all filters.
     */
    private function buildTransactionQuery(Request $request): array
    {
        $userAccounts = Auth::user()->accounts()->pluck('id');

        $query = Transaction::with([
            'account',
            'counterparty',
            'category',
            'tags',
        ])
            ->whereIn('account_id', $userAccounts)
            ->orderBy('booked_date', 'desc');

        $isFiltered = $this->applyFilters($query, $request);

        return [$query, $isFiltered];
    }

    /**
     * Apply filtering parameters to the provided query instance.
     */
    private function applyFilters(Builder $query, Request $request): bool
    {
        $isFiltered = false;

        // Apply search term
        if ($request->has('search') && ! empty($request->search)) {
            $query->search($request->search);
            $isFiltered = true;
        }

        // Transaction type (income, expense, transfer)
        if ($request->has('transactionType') && ! empty($request->transactionType) && $request->transactionType !== 'all') {
            switch ($request->transactionType) {
                case 'income':
                    $query->where('amount', '>', 0);
                    break;
                case 'expense':
                    $query->where('amount', '<', 0);
                    break;
                case 'transfer':
                    $query->where('type', 'TRANSFER');
                    break;
            }
            $isFiltered = true;
        }

        // Account
        if ($request->has('account_id') && ! empty($request->account_id) && $request->account_id !== 'all') {
            $query->where('account_id', $request->account_id);
            $isFiltered = true;
        }

        // Amount filtering with absolute values
        if ($request->has('amountFilterType') && ! empty($request->amountFilterType) && $request->amountFilterType !== 'all') {
            $isFiltered = true;
            $transactionType = $request->transactionType ?? 'all';
            switch ($request->amountFilterType) {
                case 'exact':
                    if ($request->has('amountExact') && $request->amountExact !== '') {
                        $exactAmount = abs((float) $request->amountExact);
                        if ($transactionType === 'income') {
                            $query->where('amount', $exactAmount);
                        } elseif ($transactionType === 'expense') {
                            $query->where('amount', -$exactAmount);
                        } else {
                            $query->where(function ($q) use ($exactAmount) {
                                $q->where('amount', $exactAmount)->orWhere('amount', -$exactAmount);
                            });
                        }
                    }
                    break;
                case 'range':
                    if ($request->has('amountMin') && ! empty($request->amountMin)) {
                        $minAmount = abs((float) $request->amountMin);
                        if ($transactionType === 'income') {
                            $query->where('amount', '>=', $minAmount);
                        } elseif ($transactionType === 'expense') {
                            $query->where('amount', '<=', -$minAmount);
                        } else {
                            $query->where(function ($q) use ($minAmount) {
                                $q->where(function ($sq) use ($minAmount) {
                                    $sq->where('amount', '>=', $minAmount);
                                })->orWhere(function ($sq) use ($minAmount) {
                                    $sq->where('amount', '<=', -$minAmount);
                                });
                            });
                        }
                    }

                    if ($request->has('amountMax') && ! empty($request->amountMax)) {
                        $maxAmount = abs((float) $request->amountMax);
                        if ($transactionType === 'income') {
                            $query->where('amount', '<=', $maxAmount);
                        } elseif ($transactionType === 'expense') {
                            $query->where('amount', '>=', -$maxAmount);
                        } else {
                            $query->where(function ($q) use ($maxAmount) {
                                $q->where(function ($sq) use ($maxAmount) {
                                    $sq->where('amount', '<=', $maxAmount)->where('amount', '>', 0);
                                })->orWhere(function ($sq) use ($maxAmount) {
                                    $sq->where('amount', '>=', -$maxAmount)->where('amount', '<', 0);
                                });
                            });
                        }
                    }
                    break;
                case 'above':
                    if ($request->has('amountAbove') && ! empty($request->amountAbove)) {
                        $aboveAmount = abs((float) $request->amountAbove);
                        if ($transactionType === 'income') {
                            $query->where('amount', '>=', $aboveAmount);
                        } elseif ($transactionType === 'expense') {
                            $query->where('amount', '<=', -$aboveAmount);
                        } else {
                            $query->where(function ($q) use ($aboveAmount) {
                                $q->where(function ($sq) use ($aboveAmount) {
                                    $sq->where('amount', '>=', $aboveAmount);
                                })->orWhere(function ($sq) use ($aboveAmount) {
                                    $sq->where('amount', '<=', -$aboveAmount);
                                });
                            });
                        }
                    }
                    break;
                case 'below':
                    if ($request->has('amountBelow') && ! empty($request->amountBelow)) {
                        $belowAmount = abs((float) $request->amountBelow);
                        if ($transactionType === 'income') {
                            $query->where('amount', '<=', $belowAmount)->where('amount', '>', 0);
                        } elseif ($transactionType === 'expense') {
                            $query->where('amount', '>=', -$belowAmount)->where('amount', '<', 0);
                        } else {
                            $query->where(function ($q) use ($belowAmount) {
                                $q->where(function ($sq) use ($belowAmount) {
                                    $sq->where('amount', '<=', $belowAmount)->where('amount', '>', 0);
                                })->orWhere(function ($sq) use ($belowAmount) {
                                    $sq->where('amount', '>=', -$belowAmount)->where('amount', '<', 0);
                                });
                            });
                        }
                    }
                    break;
            }
        } else {
            if ($request->has('amountMin') && ! empty($request->amountMin)) {
                $minAmount = abs((float) $request->amountMin);
                $transactionType = $request->transactionType ?? 'all';
                if ($transactionType === 'income') {
                    $query->where('amount', '>=', $minAmount);
                } elseif ($transactionType === 'expense') {
                    $query->where('amount', '<=', -$minAmount);
                } else {
                    $query->where(function ($q) use ($minAmount) {
                        $q->where(function ($sq) use ($minAmount) {
                            $sq->where('amount', '>=', $minAmount);
                        })->orWhere(function ($sq) use ($minAmount) {
                            $sq->where('amount', '<=', -$minAmount);
                        });
                    });
                }
                $isFiltered = true;
            }

            if ($request->has('amountMax') && ! empty($request->amountMax)) {
                $maxAmount = abs((float) $request->amountMax);
                $transactionType = $request->transactionType ?? 'all';
                if ($transactionType === 'income') {
                    $query->where('amount', '<=', $maxAmount);
                } elseif ($transactionType === 'expense') {
                    $query->where('amount', '>=', -$maxAmount);
                } else {
                    $query->where(function ($q) use ($maxAmount) {
                        $q->where(function ($sq) use ($maxAmount) {
                            $sq->where('amount', '<=', $maxAmount)->where('amount', '>', 0);
                        })->orWhere(function ($sq) use ($maxAmount) {
                            $sq->where('amount', '>=', -$maxAmount)->where('amount', '<', 0);
                        });
                    });
                }
                $isFiltered = true;
            }

            if ($request->has('amountExact') && ! empty($request->amountExact)) {
                $isFiltered = true;
            }
            if ($request->has('amountAbove') && ! empty($request->amountAbove)) {
                $isFiltered = true;
            }
            if ($request->has('amountBelow') && ! empty($request->amountBelow)) {
                $isFiltered = true;
            }
        }

        // Counterparty
        if ($request->has('counterparty_id') && ! empty($request->counterparty_id) && $request->counterparty_id !== 'all') {
            $query->where('counterparty_id', $request->counterparty_id);
            $isFiltered = true;
        }

        // Category
        if ($request->has('category_id') && ! empty($request->category_id) && $request->category_id !== 'all') {
            $query->where('category_id', $request->category_id);
            $isFiltered = true;
        }

        // Date range
        if ($request->has('dateFrom') && ! empty($request->dateFrom)) {
            $query->whereDate('booked_date', '>=', $request->dateFrom);
            $isFiltered = true;
        }

        if ($request->has('dateTo') && ! empty($request->dateTo)) {
            $query->whereDate('booked_date', '<=', $request->dateTo);
            $isFiltered = true;
        }

        // Recurring only (has recurring_group_id)
        if ($request->boolean('recurring_only')) {
            $query->whereNotNull('recurring_group_id');
            $isFiltered = true;
        }

        // Unlinked only (no recurring group) – for "Add transaction" picker on recurring page
        if ($request->boolean('unlinked_only')) {
            $query->whereNull('recurring_group_id');
            $isFiltered = true;
        }

        return $isFiltered;
    }

    /**
     * Get transaction field definitions for dynamic form generation.
     */
    public function getFieldDefinitions(): JsonResponse
    {
        $fields = [
            'transaction_id' => [
                'type' => 'text',
                'label' => 'Transaction ID',
                'required' => true,
                'description' => 'Unique identifier for the transaction',
            ],
            'amount' => [
                'type' => 'number',
                'label' => 'Amount',
                'required' => true,
                'step' => '0.01',
                'description' => 'Transaction amount',
            ],
            'currency' => [
                'type' => 'select',
                'label' => 'Currency',
                'required' => true,
                'options' => [
                    ['value' => 'EUR', 'label' => 'Euro (€)'],
                    ['value' => 'USD', 'label' => 'US Dollar ($)'],
                    ['value' => 'GBP', 'label' => 'British Pound (£)'],
                    ['value' => 'CZK', 'label' => 'Czech Koruna (Kč)'],
                ],
                'description' => 'Transaction currency',
            ],
            'booked_date' => [
                'type' => 'date',
                'label' => 'Booked Date',
                'required' => true,
                'description' => 'Date when transaction was booked',
            ],
            'processed_date' => [
                'type' => 'date',
                'label' => 'Processed Date',
                'required' => true,
                'description' => 'Date when transaction was processed',
            ],
            'description' => [
                'type' => 'textarea',
                'label' => 'Description',
                'required' => true,
                'description' => 'Transaction description or purpose',
            ],
            'partner' => [
                'type' => 'text',
                'label' => 'Partner',
                'required' => true,
                'description' => 'Transaction partner or counterparty',
            ],
            'type' => [
                'type' => 'text',
                'label' => 'Type',
                'required' => true,
                'description' => 'Type of transaction',
            ],
            'target_iban' => [
                'type' => 'text',
                'label' => 'Target IBAN',
                'required' => false,
                'description' => 'Destination account IBAN',
            ],
            'source_iban' => [
                'type' => 'text',
                'label' => 'Source IBAN',
                'required' => false,
                'description' => 'Source account IBAN',
            ],
            'balance_after_transaction' => [
                'type' => 'number',
                'label' => 'Balance After',
                'required' => false,
                'step' => '0.01',
                'description' => 'Account balance after this transaction',
            ],
            'note' => [
                'type' => 'textarea',
                'label' => 'Note',
                'required' => false,
                'description' => 'Additional notes about the transaction',
            ],
            'recipient_note' => [
                'type' => 'textarea',
                'label' => 'Recipient Note',
                'required' => false,
                'description' => 'Note for the recipient',
            ],
            'place' => [
                'type' => 'text',
                'label' => 'Place',
                'required' => false,
                'description' => 'Location where transaction occurred',
            ],
            'account_id' => [
                'type' => 'select',
                'label' => 'Account',
                'required' => true,
                'options' => Auth::user()->accounts->map(function ($account) {
                    return [
                        'value' => $account->id,
                        'label' => $account->name.' ('.$account->iban.')',
                    ];
                })->toArray(),
                'description' => 'Associated account',
            ],
            'counterparty_id' => [
                'type' => 'select',
                'label' => 'Counterparty',
                'required' => false,
                'options' => Auth::user()->counterparties->map(function ($counterparty) {
                    return [
                        'value' => $counterparty->id,
                        'label' => $counterparty->name,
                    ];
                })->toArray(),
                'description' => 'Associated counterparty',
            ],
            'category_id' => [
                'type' => 'select',
                'label' => 'Category',
                'required' => false,
                'options' => Auth::user()->categories->map(function ($category) {
                    return [
                        'value' => $category->id,
                        'label' => $category->name,
                    ];
                })->toArray(),
                'description' => 'Transaction category',
            ],
        ];

        return response()->json([
            'fields' => $fields,
            'field_order' => [
                'account_id', 'transaction_id', 'amount', 'currency', 'description', 'booked_date', 'processed_date', 'partner', 'place', 'type',
                'target_iban', 'source_iban', 'balance_after_transaction',
                'counterparty_id', 'category_id',
                'note', 'recipient_note',
            ],
        ]);
    }
}
