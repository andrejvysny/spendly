<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\Repositories\CategoryRepositoryInterface;
use App\Http\Requests\StoreBudgetRequest;
use App\Http\Requests\UpdateBudgetRequest;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\RecurringGroup;
use App\Models\Transaction;
use App\Services\BudgetService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BudgetController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly BudgetService $budgetService,
        private readonly CategoryRepositoryInterface $categoryRepository
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Budget::class);

        $user = $this->getAuthUser();
        $userId = $this->getAuthUserId();

        $periodType = (string) $request->input('period_type', Budget::PERIOD_MONTHLY);
        $year = (int) ($request->input('year') ?? date('Y'));
        $month = $request->has('month') ? (int) $request->input('month') : (int) date('n');
        if ($periodType === Budget::PERIOD_YEARLY) {
            $month = 0;
        }

        $budgetsWithProgress = $this->budgetService->getBudgetsWithProgress(
            $userId,
            $periodType,
            $year,
            $month === 0 ? null : $month
        );

        $categories = $this->categoryRepository->findByUser($userId);

        /** @var \App\Models\User $authUser */
        $authUser = $user;
        $accounts = $authUser->accounts;
        $firstAccount = $accounts->first();
        $defaultCurrency = $firstAccount !== null ? (string) $firstAccount->currency : 'EUR';

        // Count uncategorized expenses in current period
        $accountIds = $accounts->pluck('id')->toArray();
        $uncategorizedCount = 0;
        if ($accountIds !== []) {
            $viewStart = $periodType === Budget::PERIOD_MONTHLY && $month > 0
                ? sprintf('%04d-%02d-01', $year, $month)
                : sprintf('%04d-01-01', $year);
            $viewEnd = $periodType === Budget::PERIOD_MONTHLY && $month > 0
                ? date('Y-m-t', (int) strtotime($viewStart))
                : sprintf('%04d-12-31', $year);

            $uncategorizedCount = Transaction::whereIn('account_id', $accountIds)
                ->where('booked_date', '>=', $viewStart)
                ->where('booked_date', '<=', $viewEnd)
                ->where('amount', '<', 0)
                ->where('type', '!=', Transaction::TYPE_TRANSFER)
                ->whereNull('category_id')
                ->count();
        }

        return Inertia::render('budgets/Index', [
            'budgets' => $budgetsWithProgress->map(function (array $row) {
                return $this->serializeBudgetWithProgress($row);
            })->values()->all(),
            'categories' => $categories->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'color' => $c->color,
                'icon' => $c->icon,
            ])->values()->all(),
            'tags' => $authUser->tags->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'color' => $t->color ?? null,
            ])->values()->all(),
            'counterparties' => $authUser->counterparties->map(fn ($cp) => [
                'id' => $cp->id,
                'name' => $cp->name,
                'type' => $cp->type instanceof \BackedEnum ? $cp->type->value : (string) $cp->type,
            ])->values()->all(),
            'recurringGroups' => RecurringGroup::where('user_id', $userId)
                ->where('status', RecurringGroup::STATUS_CONFIRMED)
                ->get()
                ->map(fn ($rg) => [
                    'id' => $rg->id,
                    'name' => $rg->name,
                    'interval' => $rg->interval,
                ])->values()->all(),
            'accounts' => $accounts->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'currency' => $a->currency,
            ])->values()->all(),
            'periodType' => $periodType,
            'year' => $year,
            'month' => $month,
            'defaultCurrency' => $defaultCurrency,
            'uncategorizedCount' => $uncategorizedCount,
        ]);
    }

    public function builder(Request $request): Response
    {
        $this->authorize('viewAny', Budget::class);

        $userId = $this->getAuthUserId();
        $categories = $this->categoryRepository->findByUser($userId);
        $existingBudgets = $this->budgetService->getBudgetsWithProgress(
            $userId,
            Budget::PERIOD_MONTHLY,
            (int) date('Y'),
            (int) date('n')
        );

        /** @var \App\Models\User $user */
        $user = $this->getAuthUser();
        $firstAccount = $user->accounts->first();
        $defaultCurrency = $firstAccount !== null ? (string) $firstAccount->currency : 'EUR';

        return Inertia::render('budgets/Builder', [
            'categories' => $categories->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'color' => $c->color,
                'icon' => $c->icon,
            ])->values()->all(),
            'tags' => $user->tags->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'color' => $t->color ?? null,
            ])->values()->all(),
            'counterparties' => $user->counterparties->map(fn ($cp) => [
                'id' => $cp->id,
                'name' => $cp->name,
                'type' => $cp->type instanceof \BackedEnum ? $cp->type->value : (string) $cp->type,
            ])->values()->all(),
            'recurringGroups' => RecurringGroup::where('user_id', $userId)
                ->where('status', RecurringGroup::STATUS_CONFIRMED)
                ->get()
                ->map(fn ($rg) => [
                    'id' => $rg->id,
                    'name' => $rg->name,
                    'interval' => $rg->interval,
                ])->values()->all(),
            'accounts' => $user->accounts->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'currency' => $a->currency,
            ])->values()->all(),
            'existingBudgets' => $existingBudgets->map(function (array $row) {
                /** @var Budget $budget */
                $budget = $row['budget'];

                return [
                    'id' => $budget->id,
                    'category_id' => $budget->category_id,
                    'tag_id' => $budget->tag_id,
                    'counterparty_id' => $budget->counterparty_id,
                    'recurring_group_id' => $budget->recurring_group_id,
                    'account_id' => $budget->account_id,
                    'target_type' => $budget->target_type,
                    'target_key' => $budget->target_key,
                    'amount' => (float) $budget->amount,
                    'currency' => $budget->currency,
                    'period_type' => $budget->period_type,
                ];
            })->values()->all(),
            'defaultCurrency' => $defaultCurrency,
        ]);
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function suggestAmounts(Request $request)
    {
        $this->authorize('viewAny', Budget::class);

        $suggestions = $this->budgetService->getSuggestedAmounts($this->getAuthUserId());

        return response()->json(['suggestions' => $suggestions]);
    }

    public function suggestSubscriptionAmounts(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Budget::class);

        $suggestions = $this->budgetService->getSubscriptionSuggestions($this->getAuthUserId());

        return response()->json($suggestions);
    }

    public function store(StoreBudgetRequest $request): RedirectResponse
    {
        $this->authorize('create', Budget::class);

        $validated = $request->validated();
        $this->budgetService->create($this->getAuthUserId(), $validated);

        return redirect()->back()->with('success', 'Budget created successfully.');
    }

    public function update(UpdateBudgetRequest $request, Budget $budget): RedirectResponse
    {
        $this->authorize('update', $budget);

        $validated = $request->validated();
        $this->budgetService->update($budget, $validated);

        return redirect()->back()->with('success', 'Budget updated successfully.');
    }

    public function destroy(Budget $budget): RedirectResponse
    {
        $this->authorize('delete', $budget);

        $this->budgetService->delete($budget);

        return redirect()->back()->with('success', 'Budget deleted successfully.');
    }

    public function history(Budget $budget): JsonResponse
    {
        $this->authorize('view', $budget);

        $history = $this->budgetService->getBudgetHistory(
            $this->getAuthUserId(),
            $budget->id
        );

        return response()->json(['history' => $history]);
    }

    public function updatePeriod(Request $request, Budget $budget, BudgetPeriod $period): RedirectResponse
    {
        $this->authorize('update', $budget);

        $validated = $request->validate([
            'amount_budgeted' => ['required', 'numeric', 'min:0.01'],
        ]);

        // Ensure period belongs to this budget
        if ($period->budget_id !== $budget->id) {
            abort(404);
        }

        $period->update([
            'amount_budgeted' => $validated['amount_budgeted'],
        ]);

        return redirect()->back()->with('success', 'Period amount updated.');
    }

    /**
     * Serialize a budget row with progress data for frontend.
     *
     * @param  array{budget: Budget, period: BudgetPeriod|null, spent: float, remaining: float, percentage_used: float, is_exceeded: bool, pace_percentage: float, projected_total: float, days_elapsed: int, days_in_period: int}  $row
     * @return array<string, mixed>
     */
    private function serializeBudgetWithProgress(array $row): array
    {
        /** @var Budget $budget */
        $budget = $row['budget'];
        /** @var BudgetPeriod|null $period */
        $period = $row['period'];

        return [
            'id' => $budget->id,
            'user_id' => $budget->user_id,
            'category_id' => $budget->category_id,
            'tag_id' => $budget->tag_id,
            'counterparty_id' => $budget->counterparty_id,
            'recurring_group_id' => $budget->recurring_group_id,
            'account_id' => $budget->account_id,
            'target_type' => $budget->target_type,
            'include_transfers' => (bool) $budget->include_transfers,
            'category' => $budget->category ? [
                'id' => $budget->category->id,
                'name' => $budget->category->name,
                'color' => $budget->category->color,
                'icon' => $budget->category->icon,
            ] : null,
            'tag' => $budget->tag ? [
                'id' => $budget->tag->id,
                'name' => $budget->tag->name,
                'color' => $budget->tag->color ?? null,
            ] : null,
            'counterparty' => $budget->counterparty ? [
                'id' => $budget->counterparty->id,
                'name' => $budget->counterparty->name,
            ] : null,
            'recurring_group' => $budget->recurringGroup ? [
                'id' => $budget->recurringGroup->id,
                'name' => $budget->recurringGroup->name,
            ] : null,
            'account' => $budget->relationLoaded('account') && $budget->account ? [
                'id' => $budget->account->id,
                'name' => $budget->account->name,
            ] : null,
            'amount' => (float) $budget->amount,
            'currency' => $budget->currency,
            'mode' => $budget->mode,
            'period_type' => $budget->period_type,
            'name' => $budget->name,
            'rollover_enabled' => $budget->rollover_enabled,
            'rollover_cap' => $budget->rollover_cap !== null ? (float) $budget->rollover_cap : null,
            'include_subcategories' => $budget->include_subcategories,
            'is_active' => $budget->is_active,
            'period' => $period ? [
                'id' => $period->id,
                'start_date' => $period->start_date->format('Y-m-d'),
                'end_date' => $period->end_date->format('Y-m-d'),
                'amount_budgeted' => (float) $period->amount_budgeted,
                'rollover_amount' => (float) $period->rollover_amount,
                'status' => $period->status,
            ] : null,
            'spent' => $row['spent'],
            'remaining' => $row['remaining'],
            'percentage_used' => $row['percentage_used'],
            'is_exceeded' => $row['is_exceeded'],
            'pace_percentage' => $row['pace_percentage'],
            'projected_total' => $row['projected_total'],
            'days_elapsed' => $row['days_elapsed'],
            'days_in_period' => $row['days_in_period'],
        ];
    }
}
