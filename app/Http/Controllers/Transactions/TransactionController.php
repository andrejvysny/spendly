<?php

namespace App\Http\Controllers\Transactions;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class TransactionController extends Controller
{
    public function index(Request $request)
    {
        $userAccounts = Auth::user()->accounts()->pluck('id');

        $query = Transaction::with([
            'account',
            'merchant',
            'category',
            'tags',
        ])
            ->whereIn('account_id', $userAccounts);

        // Check if any filters are active
        $isFiltered = false;

        // Apply search if search term is provided
        if ($request->has('search') && ! empty($request->search)) {
            $query->search($request->search);
            $isFiltered = true;
        }

        // Filter by transaction type (income, expense, transfer)
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

        // Filter by account - fixed to use account_id
        if ($request->has('account_id') && ! empty($request->account_id) && $request->account_id !== 'all') {
            $query->where('account_id', $request->account_id);
            $isFiltered = true;
        }

        // Enhanced amount filtering with absolute values
        if ($request->has('amountFilterType') && ! empty($request->amountFilterType) && $request->amountFilterType !== 'all') {
            $isFiltered = true;

            // Determine if we're filtering for income, expense or all transactions
            $transactionType = $request->transactionType ?? 'all';

            switch ($request->amountFilterType) {
                case 'exact':
                    if ($request->has('amountExact') && $request->amountExact !== '') {
                        $exactAmount = abs(floatval($request->amountExact));

                        if ($transactionType === 'income') {
                            $query->where('amount', $exactAmount);
                        } elseif ($transactionType === 'expense') {
                            $query->where('amount', -$exactAmount);
                        } else {
                            // If not filtering by type, match both income and expense with this amount
                            $query->where(function ($q) use ($exactAmount) {
                                $q->where('amount', $exactAmount)
                                    ->orWhere('amount', -$exactAmount);
                            });
                        }
                    }
                    break;
                case 'range':
                    if ($request->has('amountMin') && ! empty($request->amountMin)) {
                        $minAmount = abs(floatval($request->amountMin));

                        if ($transactionType === 'income') {
                            $query->where('amount', '>=', $minAmount);
                        } elseif ($transactionType === 'expense') {
                            $query->where('amount', '<=', -$minAmount);
                        } else {
                            // If not filtering by type, apply condition based on absolute value
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
                        $maxAmount = abs(floatval($request->amountMax));

                        if ($transactionType === 'income') {
                            $query->where('amount', '<=', $maxAmount);
                        } elseif ($transactionType === 'expense') {
                            $query->where('amount', '>=', -$maxAmount);
                        } else {
                            // If not filtering by type, apply condition based on absolute value
                            $query->where(function ($q) use ($maxAmount) {
                                $q->where(function ($sq) use ($maxAmount) {
                                    $sq->where('amount', '<=', $maxAmount)
                                        ->where('amount', '>', 0);
                                })->orWhere(function ($sq) use ($maxAmount) {
                                    $sq->where('amount', '>=', -$maxAmount)
                                        ->where('amount', '<', 0);
                                });
                            });
                        }
                    }
                    break;
                case 'above':
                    if ($request->has('amountAbove') && ! empty($request->amountAbove)) {
                        $aboveAmount = abs(floatval($request->amountAbove));

                        if ($transactionType === 'income') {
                            $query->where('amount', '>=', $aboveAmount);
                        } elseif ($transactionType === 'expense') {
                            $query->where('amount', '<=', -$aboveAmount);
                        } else {
                            // If not filtering by type, apply condition based on absolute value
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
                        $belowAmount = abs(floatval($request->amountBelow));

                        if ($transactionType === 'income') {
                            $query->where('amount', '<=', $belowAmount)
                                ->where('amount', '>', 0);
                        } elseif ($transactionType === 'expense') {
                            $query->where('amount', '>=', -$belowAmount)
                                ->where('amount', '<', 0);
                        } else {
                            // If not filtering by type, apply condition based on absolute value
                            $query->where(function ($q) use ($belowAmount) {
                                $q->where(function ($sq) use ($belowAmount) {
                                    $sq->where('amount', '<=', $belowAmount)
                                        ->where('amount', '>', 0);
                                })->orWhere(function ($sq) use ($belowAmount) {
                                    $sq->where('amount', '>=', -$belowAmount)
                                        ->where('amount', '<', 0);
                                });
                            });
                        }
                    }
                    break;
            }
        } else {
            // Maintain backward compatibility with old filtering but with absolute values
            if ($request->has('amountMin') && ! empty($request->amountMin)) {
                $minAmount = abs(floatval($request->amountMin));
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
                $maxAmount = abs(floatval($request->amountMax));
                $transactionType = $request->transactionType ?? 'all';

                if ($transactionType === 'income') {
                    $query->where('amount', '<=', $maxAmount);
                } elseif ($transactionType === 'expense') {
                    $query->where('amount', '>=', -$maxAmount);
                } else {
                    $query->where(function ($q) use ($maxAmount) {
                        $q->where(function ($sq) use ($maxAmount) {
                            $sq->where('amount', '<=', $maxAmount)
                                ->where('amount', '>', 0);
                        })->orWhere(function ($sq) use ($maxAmount) {
                            $sq->where('amount', '>=', -$maxAmount)
                                ->where('amount', '<', 0);
                        });
                    });
                }
                $isFiltered = true;
            }

            // Check if any amount filter types are set directly
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

        // Filter by merchant
        if ($request->has('merchant_id') && ! empty($request->merchant_id) && $request->merchant_id !== 'all') {
            $query->where('merchant_id', $request->merchant_id);
            $isFiltered = true;
        }

        // Filter by category
        if ($request->has('category_id') && ! empty($request->category_id) && $request->category_id !== 'all') {
            $query->where('category_id', $request->category_id);
            $isFiltered = true;
        }

        // Filter by date range
        if ($request->has('dateFrom') && ! empty($request->dateFrom)) {
            $query->whereDate('booked_date', '>=', $request->dateFrom);
            $isFiltered = true;
        }

        if ($request->has('dateTo') && ! empty($request->dateTo)) {
            $query->whereDate('booked_date', '<=', $request->dateTo);
            $isFiltered = true;
        }

        // Calculate total summary if filters are active
        $totalSummary = null;
        if ($isFiltered) {
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
        }

        $transactions = $query->orderBy('booked_date', 'desc')
            ->get();

        // Calculate monthly summaries
        $monthlySummaries = [];
        foreach ($transactions as $transaction) {
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
            'transactions' => $transactions,
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
        ]);
    }

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
     * Filter transactions and return JSON response
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function filter(Request $request)
    {
        try {
            \Log::info('Filter request received', ['params' => $request->all()]);

            $userAccounts = Auth::user()->accounts()->pluck('id');
            $isFiltered = false;

            $query = Transaction::with([
                'account',
                'merchant',
                'category',
                'tags',
            ])
                ->whereIn('account_id', $userAccounts);

            // Apply search if search term is provided
            if ($request->has('search') && ! empty($request->search)) {
                $query->search($request->search);
                $isFiltered = true;
            }

            // Filter by transaction type (income, expense, transfer)
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

            // Filter by account - fixed to use account_id
            if ($request->has('account_id') && ! empty($request->account_id) && $request->account_id !== 'all') {
                $query->where('account_id', $request->account_id);
                $isFiltered = true;
            }

            // Enhanced amount filtering with absolute values
            if ($request->has('amountFilterType') && ! empty($request->amountFilterType) && $request->amountFilterType !== 'all') {
                $isFiltered = true;

                // Determine if we're filtering for income, expense or all transactions
                $transactionType = $request->transactionType ?? 'all';

                switch ($request->amountFilterType) {
                    case 'exact':
                        if ($request->has('amountExact') && $request->amountExact !== '') {
                            $exactAmount = abs(floatval($request->amountExact));

                            if ($transactionType === 'income') {
                                $query->where('amount', $exactAmount);
                            } elseif ($transactionType === 'expense') {
                                $query->where('amount', -$exactAmount);
                            } else {
                                // If not filtering by type, match both income and expense with this amount
                                $query->where(function ($q) use ($exactAmount) {
                                    $q->where('amount', $exactAmount)
                                        ->orWhere('amount', -$exactAmount);
                                });
                            }
                        }
                        break;
                    case 'range':
                        if ($request->has('amountMin') && ! empty($request->amountMin)) {
                            $minAmount = abs(floatval($request->amountMin));

                            if ($transactionType === 'income') {
                                $query->where('amount', '>=', $minAmount);
                            } elseif ($transactionType === 'expense') {
                                $query->where('amount', '<=', -$minAmount);
                            } else {
                                // If not filtering by type, apply condition based on absolute value
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
                            $maxAmount = abs(floatval($request->amountMax));

                            if ($transactionType === 'income') {
                                $query->where('amount', '<=', $maxAmount);
                            } elseif ($transactionType === 'expense') {
                                $query->where('amount', '>=', -$maxAmount);
                            } else {
                                // If not filtering by type, apply condition based on absolute value
                                $query->where(function ($q) use ($maxAmount) {
                                    $q->where(function ($sq) use ($maxAmount) {
                                        $sq->where('amount', '<=', $maxAmount)
                                            ->where('amount', '>', 0);
                                    })->orWhere(function ($sq) use ($maxAmount) {
                                        $sq->where('amount', '>=', -$maxAmount)
                                            ->where('amount', '<', 0);
                                    });
                                });
                            }
                        }
                        break;
                    case 'above':
                        if ($request->has('amountAbove') && ! empty($request->amountAbove)) {
                            $aboveAmount = abs(floatval($request->amountAbove));

                            if ($transactionType === 'income') {
                                $query->where('amount', '>=', $aboveAmount);
                            } elseif ($transactionType === 'expense') {
                                $query->where('amount', '<=', -$aboveAmount);
                            } else {
                                // If not filtering by type, apply condition based on absolute value
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
                            $belowAmount = abs(floatval($request->amountBelow));

                            if ($transactionType === 'income') {
                                $query->where('amount', '<=', $belowAmount)
                                    ->where('amount', '>', 0);
                            } elseif ($transactionType === 'expense') {
                                $query->where('amount', '>=', -$belowAmount)
                                    ->where('amount', '<', 0);
                            } else {
                                // If not filtering by type, apply condition based on absolute value
                                $query->where(function ($q) use ($belowAmount) {
                                    $q->where(function ($sq) use ($belowAmount) {
                                        $sq->where('amount', '<=', $belowAmount)
                                            ->where('amount', '>', 0);
                                    })->orWhere(function ($sq) use ($belowAmount) {
                                        $sq->where('amount', '>=', -$belowAmount)
                                            ->where('amount', '<', 0);
                                    });
                                });
                            }
                        }
                        break;
                }
            } else {
                // Maintain backward compatibility with old filtering but with absolute values
                if ($request->has('amountMin') && ! empty($request->amountMin)) {
                    $minAmount = abs(floatval($request->amountMin));
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
                    $maxAmount = abs(floatval($request->amountMax));
                    $transactionType = $request->transactionType ?? 'all';

                    if ($transactionType === 'income') {
                        $query->where('amount', '<=', $maxAmount);
                    } elseif ($transactionType === 'expense') {
                        $query->where('amount', '>=', -$maxAmount);
                    } else {
                        $query->where(function ($q) use ($maxAmount) {
                            $q->where(function ($sq) use ($maxAmount) {
                                $sq->where('amount', '<=', $maxAmount)
                                    ->where('amount', '>', 0);
                            })->orWhere(function ($sq) use ($maxAmount) {
                                $sq->where('amount', '>=', -$maxAmount)
                                    ->where('amount', '<', 0);
                            });
                        });
                    }
                    $isFiltered = true;
                }

                // Check if any amount filter types are set directly
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

            // Filter by merchant
            if ($request->has('merchant_id') && ! empty($request->merchant_id) && $request->merchant_id !== 'all') {
                $query->where('merchant_id', $request->merchant_id);
                $isFiltered = true;
            }

            // Filter by category
            if ($request->has('category_id') && ! empty($request->category_id) && $request->category_id !== 'all') {
                $query->where('category_id', $request->category_id);
                $isFiltered = true;
            }

            // Filter by date range
            if ($request->has('dateFrom') && ! empty($request->dateFrom)) {
                $query->whereDate('booked_date', '>=', $request->dateFrom);
                $isFiltered = true;
            }

            if ($request->has('dateTo') && ! empty($request->dateTo)) {
                $query->whereDate('booked_date', '<=', $request->dateTo);
                $isFiltered = true;
            }

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

            $transactions = $query->orderBy('booked_date', 'desc')
                ->get();

            \Log::info('Filtered transactions count: '.$transactions->count().', isFiltered: '.($isFiltered ? 'true' : 'false'));

            // Calculate monthly summaries
            $monthlySummaries = [];
            foreach ($transactions as $transaction) {
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
                'transactions' => $transactions,
                'monthlySummaries' => $monthlySummaries,
                'totalSummary' => $totalSummary,
                'isFiltered' => $isFiltered,
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
}
