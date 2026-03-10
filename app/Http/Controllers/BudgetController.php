<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\Repositories\CategoryRepositoryInterface;
use App\Http\Requests\StoreBudgetRequest;
use App\Http\Requests\UpdateBudgetRequest;
use App\Models\Budget;
use App\Services\BudgetService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
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

        return Inertia::render('budgets/Index', [
            'budgets' => $budgetsWithProgress->map(function (array $row) {
                /** @var Budget $budget */
                $budget = $row['budget'];
                /** @var \App\Models\BudgetPeriod|null $period */
                $period = $row['period'];

                return [
                    'id' => $budget->id,
                    'user_id' => $budget->user_id,
                    'category_id' => $budget->category_id,
                    'category' => $budget->category ? [
                        'id' => $budget->category->id,
                        'name' => $budget->category->name,
                        'color' => $budget->category->color,
                        'icon' => $budget->category->icon,
                    ] : null,
                    'amount' => (float) $budget->amount,
                    'currency' => $budget->currency,
                    'mode' => $budget->mode,
                    'period_type' => $budget->period_type,
                    'name' => $budget->name,
                    'rollover_enabled' => $budget->rollover_enabled,
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
                ];
            })->values()->all(),
            'categories' => $categories->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'color' => $c->color,
                'icon' => $c->icon,
            ])->values()->all(),
            'periodType' => $periodType,
            'year' => $year,
            'month' => $month,
            'defaultCurrency' => $defaultCurrency,
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
            'existingBudgets' => $existingBudgets->map(function (array $row) {
                /** @var Budget $budget */
                $budget = $row['budget'];

                return [
                    'id' => $budget->id,
                    'category_id' => $budget->category_id,
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
}
