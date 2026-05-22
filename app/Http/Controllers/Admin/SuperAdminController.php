<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Contracts\Repositories\TransactionRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BulkLabelRequest;
use App\Http\Requests\Admin\TransactionLabelingIndexRequest;
use App\Http\Requests\Admin\UpdateTransactionLabelRequest;
use App\Http\Resources\Admin\TransactionLabelingResource;
use App\Http\Resources\Admin\TransactionLabelingStatsResource;
use App\Models\Account;
use App\Models\Category;
use App\Models\Counterparty;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SuperAdminController extends Controller
{
    public function __construct(
        private readonly TransactionRepositoryInterface $transactionRepository
    ) {}

    public function index(): Response
    {
        return Inertia::render('admin/index');
    }

    public function transactions(TransactionLabelingIndexRequest $request): JsonResponse
    {
        $validated = $request->validatedWithDefaults();

        $query = Transaction::query()
            ->with(['account.user', 'counterparty', 'category', 'tags'])
            ->orderByDesc('booked_date');

        $this->applyUserFilter($query, $validated['user_id'] ?? null);
        $this->applyStatusFilter($query, $validated['status']);
        $this->applyTypeFilter($query, $validated['type']);
        $this->applyAccountFilter($query, $validated['account_ids'] ?? []);

        if (! empty($validated['search'])) {
            $query->search($validated['search']);
        }

        if (! empty($validated['category_id'])) {
            $query->where('category_id', $validated['category_id']);
        }

        if (! empty($validated['merchant_id'])) {
            $query->where('counterparty_id', $validated['merchant_id']);
        }

        if (! empty($validated['date_from'])) {
            $query->whereDate('booked_date', '>=', $validated['date_from']);
        }

        if (! empty($validated['date_to'])) {
            $query->whereDate('booked_date', '<=', $validated['date_to']);
        }

        // Get stats from the same filtered query
        $stats = $this->computeStats(clone $query);

        // Get similar group counts in a separate query to avoid pagination issues
        $fingerprints = $query->pluck('fingerprint', 'id');
        $similarCounts = [];
        if ($fingerprints->isNotEmpty()) {
            $counts = Transaction::query()
                ->selectRaw('fingerprint, COUNT(*) as count')
                ->whereIn('fingerprint', $fingerprints->filter()->values())
                ->groupBy('fingerprint')
                ->pluck('count', 'fingerprint');
            $similarCounts = $counts->toArray();
        }

        $transactions = $query->paginate((int) $validated['per_page']);

        // Add similar_group_count to each transaction
        $transactions->getCollection()->transform(function ($transaction) use ($similarCounts) {
            if ($transaction->fingerprint && isset($similarCounts[$transaction->fingerprint])) {
                $transaction->similar_group_count = $similarCounts[$transaction->fingerprint];
            } else {
                $transaction->similar_group_count = 1;
            }
            return $transaction;
        });

        return response()->json([
            'transactions' => [
                'data' => TransactionLabelingResource::collection($transactions),
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'total' => $transactions->total(),
                'has_more_pages' => $transactions->hasMorePages(),
            ],
            'stats' => new TransactionLabelingStatsResource($stats),
            'filter_options' => [
                'accounts' => $this->getFilterAccounts($validated['user_id'] ?? null),
            ],
            'filters' => $validated,
        ]);
    }

    public function updateLabel(UpdateTransactionLabelRequest $request, Transaction $transaction): JsonResponse
    {
        $validated = $request->validated();
        $metadata = (array) ($transaction->metadata ?? []);
        $superadminLabels = (array) ($metadata['superadmin_labels'] ?? []);

        $updateData = [];

        if (array_key_exists('category_id', $validated)) {
            $updateData['category_id'] = $validated['category_id'];
        }

        if (array_key_exists('counterparty_id', $validated)) {
            $updateData['counterparty_id'] = $validated['counterparty_id'];
        }

        if (array_key_exists('type', $validated)) {
            $updateData['type'] = $validated['type'];
        }

        if (array_key_exists('needs_manual_review', $validated)) {
            $updateData['needs_manual_review'] = $validated['needs_manual_review'];
        }

        if (array_key_exists('is_duplicate', $validated)) {
            $updateData['duplicate_identifier'] = $validated['is_duplicate']
                ? 'superadmin:'.$transaction->id
                : null;
            $superadminLabels['is_duplicate'] = $validated['is_duplicate'];
        }

        if (array_key_exists('is_recurring', $validated)) {
            if ($validated['is_recurring'] === false) {
                $updateData['recurring_group_id'] = null;
            }
            $superadminLabels['is_recurring'] = $validated['is_recurring'];
        }

        if (array_key_exists('is_uncertain', $validated)) {
            $updateData['needs_manual_review'] = $validated['is_uncertain'];
            $superadminLabels['is_uncertain'] = $validated['is_uncertain'];
        }

        // Handle ML acceptance
        if (! empty($validated['accept_ml_category']) && ! empty($metadata['ml']['category_suggestion'])) {
            $updateData['category_id'] = $metadata['ml']['category_suggestion']['id'] ?? null;
            $metadata['ml']['category_suggestion']['status'] = 'accepted';
            $metadata['ml']['category_suggestion']['accepted_at'] = now()->toIso8601String();
            $metadata['ml']['category_suggestion']['accepted_by'] = auth()->id();
            $superadminLabels['accepted_ml_category'] = true;
        }

        if (! empty($validated['accept_ml_counterparty']) && ! empty($metadata['ml']['counterparty_suggestion'])) {
            $updateData['counterparty_id'] = $metadata['ml']['counterparty_suggestion']['id'] ?? null;
            $metadata['ml']['counterparty_suggestion']['status'] = 'accepted';
            $metadata['ml']['counterparty_suggestion']['accepted_at'] = now()->toIso8601String();
            $metadata['ml']['counterparty_suggestion']['accepted_by'] = auth()->id();
            $superadminLabels['accepted_ml_counterparty'] = true;
        }

        // Handle tags
        if (array_key_exists('tags', $validated)) {
            $transaction->tags()->sync($validated['tags']);
        }

        if ($superadminLabels !== []) {
            $metadata['superadmin_labels'] = $superadminLabels;
            $updateData['metadata'] = $metadata;
        }

        if ($updateData !== []) {
            $transaction->update($updateData);
        }

        $transaction->load(['account.user', 'counterparty', 'category', 'tags']);

        return response()->json([
            'transaction' => new TransactionLabelingResource($transaction),
            'message' => 'Transaction updated successfully',
        ]);
    }

    public function bulkLabel(BulkLabelRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $labels = $validated['labels'];
        $userId = $validated['user_id'] ?? null;
        $updated = 0;

        $query = Transaction::query()->whereIn('id', $validated['transaction_ids']);
        $this->applyUserFilter($query, $userId);

        $transactions = $query->get();

        DB::transaction(function () use ($transactions, $labels, &$updated): void {
            foreach ($transactions as $transaction) {
                $updateData = [];
                $metadata = (array) ($transaction->metadata ?? []);
                $superadminLabels = (array) ($metadata['superadmin_labels'] ?? []);

                if (array_key_exists('category_id', $labels)) {
                    $updateData['category_id'] = $labels['category_id'];
                }

                if (array_key_exists('merchant_id', $labels)) {
                    $updateData['counterparty_id'] = $labels['merchant_id'];
                }

                if (array_key_exists('is_transfer', $labels)) {
                    $updateData['type'] = $labels['is_transfer'] ? Transaction::TYPE_TRANSFER : Transaction::TYPE_PAYMENT;
                }

                if (array_key_exists('is_duplicate', $labels)) {
                    $updateData['duplicate_identifier'] = $labels['is_duplicate']
                        ? 'superadmin:'.$transaction->id
                        : null;
                    $superadminLabels['is_duplicate'] = (bool) $labels['is_duplicate'];
                }

                if (array_key_exists('is_recurring', $labels)) {
                    if ($labels['is_recurring'] === false) {
                        $updateData['recurring_group_id'] = null;
                    }
                    $superadminLabels['is_recurring'] = (bool) $labels['is_recurring'];
                }

                if (array_key_exists('is_uncertain', $labels)) {
                    $updateData['needs_manual_review'] = $labels['is_uncertain'];
                    $superadminLabels['is_uncertain'] = (bool) $labels['is_uncertain'];
                }

                if (array_key_exists('needs_manual_review', $labels)) {
                    $updateData['needs_manual_review'] = $labels['needs_manual_review'];
                }

                if (array_key_exists('accept_ml_category', $labels) && ! empty($metadata['ml']['category_suggestion'])) {
                    $updateData['category_id'] = $metadata['ml']['category_suggestion']['id'] ?? null;
                    $metadata['ml']['category_suggestion']['status'] = 'accepted';
                    $metadata['ml']['category_suggestion']['accepted_at'] = now()->toIso8601String();
                    $metadata['ml']['category_suggestion']['accepted_by'] = auth()->id();
                    $superadminLabels['accepted_ml_category'] = true;
                }

                if (array_key_exists('accept_ml_counterparty', $labels) && ! empty($metadata['ml']['counterparty_suggestion'])) {
                    $updateData['counterparty_id'] = $metadata['ml']['counterparty_suggestion']['id'] ?? null;
                    $metadata['ml']['counterparty_suggestion']['status'] = 'accepted';
                    $metadata['ml']['counterparty_suggestion']['accepted_at'] = now()->toIso8601String();
                    $metadata['ml']['counterparty_suggestion']['accepted_by'] = auth()->id();
                    $superadminLabels['accepted_ml_counterparty'] = true;
                }

                if ($superadminLabels !== []) {
                    $metadata['superadmin_labels'] = $superadminLabels;
                    $updateData['metadata'] = $metadata;
                }

                if ($updateData === []) {
                    continue;
                }

                $transaction->update($updateData);
                $updated++;
            }
        });

        return response()->json([
            'message' => 'Transactions labeled successfully',
            'updated' => $updated,
            'total' => $transactions->count(),
        ]);
    }

    public function getUsers(): JsonResponse
    {
        $users = User::query()
            ->select(['id', 'name', 'email'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'users' => $users,
        ]);
    }

    public function getCategories(): JsonResponse
    {
        $categories = Category::select(['id', 'name', 'color'])
            ->orderBy('name')
            ->get();

        return response()->json(['categories' => $categories]);
    }

    public function getCounterparties(): JsonResponse
    {
        $counterparties = Counterparty::select(['id', 'name', 'type'])
            ->orderBy('name')
            ->get();

        return response()->json(['counterparties' => $counterparties]);
    }

    public function getTags(): JsonResponse
    {
        $tags = Tag::select(['id', 'name', 'color'])
            ->orderBy('name')
            ->get();

        return response()->json(['tags' => $tags]);
    }

    private function applyUserFilter(Builder $query, string|int|null $userId): void
    {
        if ($userId === null) {
            return;
        }
        $userId = (int) $userId;

        $userTransactionIds = $this->transactionRepository
            ->findByUser($userId)
            ->pluck('id')
            ->all();

        $query->whereIn('id', $userTransactionIds);
    }

    private function applyStatusFilter(Builder $query, string $status): void
    {
        switch ($status) {
            case 'unlabeled':
                $query->where(function (Builder $q) {
                    $q->whereNull('transactions.category_id')
                        ->whereNull('transactions.counterparty_id');
                });
                break;
            case 'labeled':
                $query->where(function (Builder $q) {
                    $q->whereNotNull('transactions.category_id')
                        ->orWhereNotNull('transactions.counterparty_id');
                });
                break;
            case 'flagged':
                $query->where(function (Builder $q) {
                    $q->where('transactions.needs_manual_review', true)
                        ->orWhereNotNull('transactions.duplicate_identifier');
                });
                break;
            case 'duplicates':
                $query->whereNotNull('transactions.duplicate_identifier');
                break;
        }
    }

    private function applyTypeFilter(Builder $query, string $type): void
    {
        if ($type === 'all') {
            return;
        }

        $typeMap = [
            'debit' => [Transaction::TYPE_PAYMENT, Transaction::TYPE_CARD_PAYMENT, Transaction::TYPE_DIRECT_DEBIT],
            'credit' => [Transaction::TYPE_CREDIT],
            'transfer' => [Transaction::TYPE_TRANSFER],
        ];

        if (isset($typeMap[$type])) {
            $query->whereIn('type', $typeMap[$type]);
        }
    }

    private function applyAccountFilter(Builder $query, array $accountIds): void
    {
        if (empty($accountIds)) {
            return;
        }

        $query->whereIn('account_id', $accountIds);
    }

    private function computeStats(Builder $query): array
    {
        $total = $query->count();

        $labeled = (clone $query)
            ->where(function (Builder $q) {
                $q->whereNotNull('transactions.category_id')
                    ->orWhereNotNull('transactions.counterparty_id');
            })
            ->count();

        $unlabeled = $total - $labeled;

        $flagged = (clone $query)
            ->where(function (Builder $q) {
                $q->where('transactions.needs_manual_review', true)
                    ->orWhereNotNull('transactions.duplicate_identifier');
            })
            ->count();

        $duplicates = (clone $query)
            ->whereNotNull('transactions.duplicate_identifier')
            ->count();

        return [
            'total' => $total,
            'labeled' => $labeled,
            'unlabeled' => $unlabeled,
            'flagged' => $flagged,
            'duplicates' => $duplicates,
        ];
    }

    private function getFilterAccounts(?int $userId): array
    {
        $query = Account::query()
            ->select(['id', 'name', 'iban'])
            ->withCount(['transactions']);

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        return $query->orderBy('name')
            ->get()
            ->map(fn ($account) => [
                'id' => $account->id,
                'name' => $account->name,
                'color' => '#3b82f6',
                'iban' => $account->iban,
                'count' => $account->transactions_count,
            ])
            ->toArray();
    }
}
