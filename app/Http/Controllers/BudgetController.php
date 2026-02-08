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

        $periodType = $request->input('period_type', Budget::PERIOD_MONTHLY);
        $year = (int) $request->input('year', (int) date('Y'));
        $month = $request->has('month') ? (int) $request->input('month') : (int) date('n');
        if ($periodType === Budget::PERIOD_YEARLY) {
            $month = 0;
        }

        $budgetsWithProgress = $this->budgetService->getBudgetsWithProgress($userId, $periodType, $year, $month === 0 ? null : $month);

        $categories = $this->categoryRepository->findByUser($userId);

        $accounts = $user->accounts;
        $defaultCurrency = $accounts->isNotEmpty() ? $accounts->first()->currency : 'EUR';

        return Inertia::render('budgets/Index', [
            'budgets' => $budgetsWithProgress->map(function (array $row) {
                $budget = $row['budget'];

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
                    'period_type' => $budget->period_type,
                    'year' => $budget->year,
                    'month' => $budget->month,
                    'name' => $budget->name,
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

    public function store(StoreBudgetRequest $request): RedirectResponse
    {
        $this->authorize('create', Budget::class);

        $validated = $request->validated();
        if (($validated['period_type'] ?? '') === Budget::PERIOD_YEARLY) {
            $validated['month'] = 0;
        }

        $this->budgetService->create($this->getAuthUserId(), $validated);

        return redirect()->back()->with('success', 'Budget created successfully.');
    }

    public function update(UpdateBudgetRequest $request, Budget $budget): RedirectResponse
    {
        $this->authorize('update', $budget);

        $validated = $request->validated();
        if (isset($validated['period_type']) && $validated['period_type'] === Budget::PERIOD_YEARLY) {
            $validated['month'] = 0;
        }

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
