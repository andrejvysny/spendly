<?php

declare(strict_types=1);

namespace App\Http\Controllers\Transactions;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\User;
use App\Rules\OwnedByUser;
use App\Services\ExchangeRateService;
use App\Services\Transactions\TransactionBulkService;
use App\Services\Transactions\TransactionFilterService;
use App\Services\Transactions\TransactionSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;

class TransactionController extends Controller
{
    public const int PAGINATION_COUNT = 100;

    public function __construct(
        private readonly TransactionFilterService $filters,
        private readonly TransactionSummaryService $summary,
        private readonly TransactionBulkService $bulk,
    ) {}

    public function index(Request $request): \Inertia\Response
    {
        /** @var User $user */
        $user = Auth::user();
        [$query, $isFiltered] = $this->filters->buildQuery($user, $request);

        $totalCount = (clone $query)->count();
        $totalSummary = $isFiltered ? $this->summary->totalSummary($query) : null;

        $transactions = $query->paginate(self::PAGINATION_COUNT);
        /** @var array<int, Transaction> $items */
        $items = $transactions->items();
        $monthlySummaries = $this->summary->monthlySummaries($items);

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
            'categories' => $user->categories,
            'counterparties' => $user->counterparties,
            'accounts' => $user->accounts,
            'tags' => $user->tags,
            'recurringGroups' => $user->recurringGroups()->where('status', 'confirmed')->get(['id', 'name', 'interval']),
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

        /** @var User $user */
        $user = Auth::user();
        /** @var \App\Models\Account $account */
        $account = $user->accounts()->findOrFail($validated['account_id']);

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

        $baseCurrency = $user->base_currency ?? 'EUR';
        if ($transactionData['currency'] === $baseCurrency) {
            $transactionData['native_amount'] = $amount;
        } else {
            $transactionData['native_amount'] = app(ExchangeRateService::class)->convert(
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
        $query = Transaction::whereHas('account', function ($q): void {
            $q->where('user_id', Auth::id());
        })
            ->where('needs_manual_review', true)
            ->with(['account'])
            ->orderByDesc('booked_date');

        if ($request->filled('review_reason')) {
            $reason = $request->input('review_reason');
            $reasonStr = is_scalar($reason) ? (string) $reason : '';
            $query->where('review_reason', 'like', '%'.$reasonStr.'%');
        }

        $transactions = $query->paginate(50);

        return Inertia::render('transactions/review', [
            'transactions' => $transactions,
            'filters' => $request->only('review_reason'),
        ]);
    }

    public function loadMore(Request $request): JsonResponse
    {
        try {
            /** @var User $user */
            $user = Auth::user();
            [$query] = $this->filters->buildQuery($user, $request);

            $pageRaw = $request->input('page');
            $page = is_numeric($pageRaw) ? (int) $pageRaw : 1;
            $transactions = $query->paginate(self::PAGINATION_COUNT, ['*'], 'page', $page);
            /** @var array<int, Transaction> $items */
            $items = $transactions->items();
            $monthlySummaries = $this->summary->monthlySummaries($items);

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

    public function filter(Request $request): JsonResponse
    {
        try {
            Log::info('Filter request received', ['params' => $request->all()]);

            /** @var User $user */
            $user = Auth::user();
            [$query, $isFiltered] = $this->filters->buildQuery($user, $request);

            $totalSummary = $this->summary->totalSummary($query);
            $transactions = $query->paginate(self::PAGINATION_COUNT);
            /** @var array<int, Transaction> $items */
            $items = $transactions->items();
            $monthlySummaries = $this->summary->monthlySummaries($items);

            Log::info('Filtered transactions count: '.$transactions->count().', isFiltered: '.($isFiltered ? 'true' : 'false'));

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

    public function update(Request $request, Transaction $transaction): RedirectResponse
    {
        try {
            // Ownership: route-model binding is not user-scoped, so guard explicitly.
            $userId = (int) Auth::id();
            $account = $transaction->account;
            if ($account === null || (int) $account->user_id !== $userId) {
                abort(403);
            }

            $validated = $request->validate([
                'counterparty_id' => ['nullable', 'integer', new OwnedByUser('counterparties', $userId)],
                'category_id' => ['nullable', 'integer', new OwnedByUser('categories', $userId)],
                'tags' => 'nullable|array',
                'tags.*' => [new OwnedByUser('tags', $userId)],
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

    public function bulkUpdate(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'transaction_ids' => 'required|array',
                'transaction_ids.*' => 'integer',
                'counterparty_id' => 'nullable|string',
                'category_id' => 'nullable|string',
                'recurring_group_id' => 'nullable|string',
            ]);

            /** @var User $user */
            $user = Auth::user();
            $transactions = $this->bulk->ownedTransactions($user, $validated['transaction_ids']);
            // Foreign-owned targets are dropped inside the service (defense in depth);
            // foreign transactions are already excluded by ownedTransactions().
            $this->bulk->applyAssignments($transactions, $validated, $user);

            return response()->json(['message' => 'Transactions updated successfully']);
        } catch (\Exception $e) {
            Log::error('Bulk transaction update failed: '.$e->getMessage());

            return response()->json(['error' => 'Failed to update transactions'], 500);
        }
    }

    public function bulkNoteUpdate(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'transaction_ids' => 'required|array',
                'transaction_ids.*' => 'exists:transactions,id',
                'note' => 'required|string',
                'method' => 'required|string|in:replace,append',
            ]);

            /** @var User $user */
            $user = Auth::user();
            $transactions = $this->bulk->ownedTransactions($user, $validated['transaction_ids']);
            $rows = $this->bulk->applyNotes($transactions, $validated['note'], $validated['method']);

            return response()->json([
                'message' => 'Transaction notes updated successfully',
                'updated_count' => $transactions->count(),
                'updated_transactions' => $rows,
            ]);
        } catch (\Exception $e) {
            Log::error('Bulk transaction note update failed: '.$e->getMessage());

            return response()->json(['error' => 'Failed to update transaction notes'], 500);
        }
    }

    public function bulkTagUpdate(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'transaction_ids' => 'required|array',
                'transaction_ids.*' => 'integer',
                'tag_ids' => 'required|array',
                'tag_ids.*' => 'integer',
                'mode' => 'required|string|in:add,remove,set',
            ]);

            /** @var User $user */
            $user = Auth::user();
            $transactions = $this->bulk->ownedTransactions($user, $validated['transaction_ids']);
            // Only the caller's own tags may be applied (foreign tag ids are dropped).
            $ownedTagIds = $user->tags()
                ->whereIn('id', $validated['tag_ids'])
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            $rows = $this->bulk->applyTags($transactions, $ownedTagIds, $validated['mode']);

            return response()->json([
                'message' => 'Transaction tags updated successfully',
                'updated_transactions' => $rows,
            ]);
        } catch (\Exception $e) {
            Log::error('Bulk transaction tag update failed: '.$e->getMessage());

            return response()->json(['error' => 'Failed to update transaction tags'], 500);
        }
    }

    public function bulkTypeUpdate(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'transaction_ids' => 'required|array',
                'transaction_ids.*' => 'exists:transactions,id',
                'type' => 'required|string|in:TRANSFER,PAYMENT',
                'clear_transfer_pair' => 'boolean',
            ]);

            /** @var User $user */
            $user = Auth::user();
            $transactions = $this->bulk->ownedTransactions($user, $validated['transaction_ids']);
            $result = $this->bulk->applyType(
                $transactions,
                $validated['type'],
                (bool) ($validated['clear_transfer_pair'] ?? false),
            );

            return response()->json([
                'message' => 'Transaction types updated successfully',
                'updated_count' => $transactions->count(),
                'paired' => $result['paired'],
            ]);
        } catch (\Exception $e) {
            Log::error('Bulk transaction type update failed: '.$e->getMessage());

            return response()->json(['error' => 'Failed to update transaction types'], 500);
        }
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'transaction_ids' => 'required|array',
                'transaction_ids.*' => 'exists:transactions,id',
            ]);

            /** @var User $user */
            $user = Auth::user();
            $transactions = $this->bulk->ownedTransactions($user, $validated['transaction_ids']);
            $deletedIds = $this->bulk->deleteAll($transactions);

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

    public function updateTransaction(Request $request, Transaction $transaction): JsonResponse
    {
        /** @var \App\Models\Account|null $account */
        $account = $transaction->account;
        if ($account === null || (int) $account->user_id !== (int) Auth::id()) {
            abort(403);
        }

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
     * Get transaction field definitions for dynamic form generation.
     */
    public function getFieldDefinitions(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

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
                'options' => $user->accounts->map(fn ($account) => [
                    'value' => $account->id,
                    'label' => $account->name.' ('.$account->iban.')',
                ])->toArray(),
                'description' => 'Associated account',
            ],
            'counterparty_id' => [
                'type' => 'select',
                'label' => 'Counterparty',
                'required' => false,
                'options' => $user->counterparties->map(fn ($counterparty) => [
                    'value' => $counterparty->id,
                    'label' => $counterparty->name,
                ])->toArray(),
                'description' => 'Associated counterparty',
            ],
            'category_id' => [
                'type' => 'select',
                'label' => 'Category',
                'required' => false,
                'options' => $user->categories->map(fn ($category) => [
                    'value' => $category->id,
                    'label' => $category->name,
                ])->toArray(),
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
