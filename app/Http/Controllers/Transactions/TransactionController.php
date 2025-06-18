<?php

namespace App\Http\Controllers\Transactions;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class TransactionController extends Controller
{
    /**
     * Displays a paginated list of the authenticated user's transactions with advanced filtering and summary statistics.
     *
     * Applies filters for search, transaction type, account, amount (with multiple filter types), merchant, category, and date range. Calculates total and monthly summaries for the filtered transactions. Provides related categories, merchants, tags, and accounts for filter dropdowns. Returns an Inertia.js response rendering the transactions index view.
     */
    const PAGINATION_COUNT = 10; // Define a constant for pagination count

    public function index(Request $request)
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
            $merchantsCount = clone $query;
            $uncategorizedCount = clone $query;
            $noMerchantCount = clone $query;

            $totalSummary = [
                'count' => $countClone->count(),
                'income' => $incomeSum->where('amount', '>', 0)->sum('amount'),
                'expense' => abs($expenseSum->where('amount', '<', 0)->sum('amount')),
                'balance' => $balanceSum->sum('amount'),
                'categoriesCount' => $categoriesCount->whereNotNull('category_id')->distinct('category_id')->count('category_id'),
                'merchantsCount' => $merchantsCount->whereNotNull('merchant_id')->distinct('merchant_id')->count('merchant_id'),
                'uncategorizedCount' => $uncategorizedCount->whereNull('category_id')->count(),
                'noMerchantCount' => $noMerchantCount->whereNull('merchant_id')->count(),
            ];
        }

        // Get paginated transactions
        $transactions = $query->paginate(self::PAGINATION_COUNT);

        // Calculate monthly summaries for the current page
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

        // Get categories, merchants, and tags for the filter dropdowns
        $categories = Auth::user()->categories;
        $merchants = Auth::user()->merchants;
        $tags = Auth::user()->tags;
        $accounts = Auth::user()->accounts;

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
            'merchants' => $merchants,
            'accounts' => $accounts,
            'tags' => $tags,
            'filters' => $request->only([
                'search', 'account_id', 'transactionType',
                'amountFilterType', 'amountMin', 'amountMax',
                'amountExact', 'amountAbove', 'amountBelow',
                'dateFrom', 'dateTo', 'merchant_id', 'category_id',
            ]),
            'totalCount' => $totalCount,
        ]);
    }

    /**
     * Retrieves a paginated list of transactions for the authenticated user, applying filters and returning results as JSON.
     *
     * Applies filters for search term, transaction type, account, merchant, category, and date range. Returns the paginated transactions and a flag indicating if more pages are available.
     *
     * @return \Illuminate\Http\JsonResponse JSON response containing filtered transactions and pagination status.
     */
    public function loadMore(Request $request)
    {
        try {
            [$query] = $this->buildTransactionQuery($request);

            $transactions = $query->paginate(self::PAGINATION_COUNT, ['*'], 'page', $request->page);

            // Calculate monthly summaries for the current page
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
            \Log::error('Load more transactions failed: '.$e->getMessage());

            return response()->json(['error' => 'Failed to load more transactions'], 500);
        }
    }

    /**
     * Filters transactions based on request parameters and returns a paginated JSON response.
     *
     * Applies filters for search term, transaction type, account, amount (with support for exact, range, above, below), merchant, category, and date range. Returns paginated transactions, monthly summaries for the current page, total summary statistics, filter status, and pagination info.
     *
     * @return \Illuminate\Http\JsonResponse Paginated filtered transactions and summary data.
     */
    public function filter(Request $request)
    {
        try {
            \Log::info('Filter request received', ['params' => $request->all()]);

            [$query, $isFiltered] = $this->buildTransactionQuery($request);

            // Calculate total summary directly from the database
            $totalCount = clone $query;
            $incomeSum = clone $query;
            $expenseSum = clone $query;
            $balanceSum = clone $query;
            $categoriesCount = clone $query;
            $merchantsCount = clone $query;
            $uncategorizedCount = clone $query;
            $noMerchantCount = clone $query;

            $totalSummary = [
                'count' => $totalCount->count(),
                'income' => $incomeSum->where('amount', '>', 0)->sum('amount'),
                'expense' => abs($expenseSum->where('amount', '<', 0)->sum('amount')),
                'balance' => $balanceSum->sum('amount'),
                'categoriesCount' => $categoriesCount->whereNotNull('category_id')->distinct('category_id')->count('category_id'),
                'merchantsCount' => $merchantsCount->whereNotNull('merchant_id')->distinct('merchant_id')->count('merchant_id'),
                'uncategorizedCount' => $uncategorizedCount->whereNull('category_id')->count(),
                'noMerchantCount' => $noMerchantCount->whereNull('merchant_id')->count(),
            ];

            // Get paginated transactions
            $transactions = $query->paginate(self::PAGINATION_COUNT);

            \Log::info('Filtered transactions count: '.$transactions->count().', isFiltered: '.($isFiltered ? 'true' : 'false'));

            // Calculate monthly summaries for the current page
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
            \Log::error('Error in transaction filter: '.$e->getMessage(), [
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
     * Creates a new transaction with validated data and optional tags.
     *
     * Validates the request data, creates a transaction record, attaches tags if provided, and redirects back with a success or error message.
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'transaction_id' => 'required|string|max:255',
                'amount' => 'required|numeric',
                'currency' => 'required|string|max:3',
                'booked_date' => 'required|date',
                'processed_date' => 'required|date',
                'description' => 'required|string|max:255',
                'target_iban' => 'nullable|string|max:255',
                'source_iban' => 'nullable|string|max:255',
                'partner' => 'nullable|string|max:255',
                'type' => 'required|string|in:TRANSFER,DEPOSIT,WITHDRAWAL,PAYMENT',
                'metadata' => 'nullable|array',
                'balance_after_transaction' => 'required|numeric',
                'account_id' => 'required|exists:accounts,id',
                'merchant_id' => 'nullable|exists:merchants,id',
                'category_id' => 'nullable|exists:categories,id',
                'tags' => 'nullable|array',
                'tags.*' => 'exists:tags,id',
                'note' => 'nullable|string',
                'recipient_note' => 'nullable|string',
                'place' => 'nullable|string|max:255',
            ]);

            $tagIds = $validated['tags'] ?? [];
            unset($validated['tags']);

            $transaction = Transaction::create($validated);

            if (! empty($tagIds)) {
                $transaction->tags()->attach($tagIds);
            }

            return redirect()->back()->with('success', 'Transaction created successfully');
        } catch (\Exception $e) {
            \Log::error('Transaction creation failed: '.$e->getMessage());

            return redirect()->back()->with('error', 'Failed to create transaction: '.$e->getMessage());
        }
    }

    /**
     * Updates the merchant, category, and tags of a transaction.
     *
     * Validates and applies updates to the specified transaction, including synchronizing associated tags. Redirects back with a success or error message based on the outcome.
     */
    public function update(Request $request, Transaction $transaction)
    {
        try {
            $validated = $request->validate([
                'merchant_id' => 'nullable|exists:merchants,id',
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
            \Log::error('Transaction update failed: '.$e->getMessage());

            return redirect()->back()->with('error', 'Failed to update transaction: '.$e->getMessage());
        }
    }

    /**
     * Updates the merchant and/or category fields for multiple transactions in bulk.
     *
     * Validates the request for transaction IDs and optional merchant and category IDs, then updates the specified fields for each transaction. Returns a JSON response indicating success or failure.
     *
     * @return \Illuminate\Http\JsonResponse JSON response with a success message or error details.
     */
    public function bulkUpdate(Request $request)
    {
        try {
            $validated = $request->validate([
                'transaction_ids' => 'required|array',
                'transaction_ids.*' => 'exists:transactions,id',
                'merchant_id' => 'nullable|string',
                'category_id' => 'nullable|string',
            ]);

            $transactions = Transaction::whereIn('id', $validated['transaction_ids'])->get();

            foreach ($transactions as $transaction) {
                $updateData = [];
                if (array_key_exists('merchant_id', $validated)) {
                    $updateData['merchant_id'] = $validated['merchant_id'] === '' ? null : $validated['merchant_id'];
                }
                if (array_key_exists('category_id', $validated)) {
                    $updateData['category_id'] = $validated['category_id'] === '' ? null : $validated['category_id'];
                }
                $transaction->update($updateData);
            }

            return response()->json(['message' => 'Transactions updated successfully']);
        } catch (\Exception $e) {
            \Log::error('Bulk transaction update failed: '.$e->getMessage());

            return response()->json(['error' => 'Failed to update transactions'], 500);
        }
    }

    /**
     * Updates specific fields of a transaction and returns a JSON response.
     *
     * Validates and updates the transaction's description, note, partner, and place fields if provided.
     *
     * @return \Illuminate\Http\JsonResponse JSON response indicating success or failure.
     */
    public function updateTransaction(Request $request, Transaction $transaction)
    {

        $validated = $request->validate([
            'description' => 'nullable|string',
            'note' => 'nullable|string',
            'partner' => 'nullable|string',
            'place' => 'nullable|string',
        ]);

        try {

            $transaction->update($validated);

            return response()->json(['message' => 'Transaction updated successfully']);
        } catch (\Exception $e) {
            \Log::error('Transaction update failed: '.$e->getMessage());

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
            'merchant',
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

        // Merchant
        if ($request->has('merchant_id') && ! empty($request->merchant_id) && $request->merchant_id !== 'all') {
            $query->where('merchant_id', $request->merchant_id);
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

        return $isFiltered;
    }
}
