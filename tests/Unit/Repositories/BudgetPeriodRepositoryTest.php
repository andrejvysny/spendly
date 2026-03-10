<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\User;
use App\Repositories\BudgetPeriodRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetPeriodRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private BudgetPeriodRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new BudgetPeriodRepository(new BudgetPeriod);
    }

    private function createBudget(?User $user = null): Budget
    {
        $user ??= User::factory()->create();
        $category = $user->categories()->create(['name' => 'Food']);

        return Budget::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'amount' => 500,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
        ]);
    }

    public function test_find_by_budget_returns_periods(): void
    {
        $budget = $this->createBudget();

        BudgetPeriod::create([
            'budget_id' => $budget->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
            'amount_budgeted' => 500,
            'status' => BudgetPeriod::STATUS_CLOSED,
        ]);
        BudgetPeriod::create([
            'budget_id' => $budget->id,
            'start_date' => '2026-02-01',
            'end_date' => '2026-02-28',
            'amount_budgeted' => 500,
            'status' => BudgetPeriod::STATUS_ACTIVE,
        ]);

        $periods = $this->repository->findByBudget($budget->id);
        $this->assertCount(2, $periods);
    }

    public function test_find_active_for_budget(): void
    {
        $budget = $this->createBudget();

        BudgetPeriod::create([
            'budget_id' => $budget->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
            'amount_budgeted' => 500,
            'status' => BudgetPeriod::STATUS_CLOSED,
        ]);
        BudgetPeriod::create([
            'budget_id' => $budget->id,
            'start_date' => '2026-02-01',
            'end_date' => '2026-02-28',
            'amount_budgeted' => 500,
            'status' => BudgetPeriod::STATUS_ACTIVE,
        ]);

        $active = $this->repository->findActiveForBudget($budget->id);
        $this->assertNotNull($active);
        $this->assertSame('2026-02-01', $active->start_date->format('Y-m-d'));
    }

    public function test_find_for_budgets_and_date(): void
    {
        $budget = $this->createBudget();

        BudgetPeriod::create([
            'budget_id' => $budget->id,
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-31',
            'amount_budgeted' => 500,
            'status' => BudgetPeriod::STATUS_ACTIVE,
        ]);

        $found = $this->repository->findForBudgetsAndDate([$budget->id], '2026-03-15');
        $this->assertCount(1, $found);

        $notFound = $this->repository->findForBudgetsAndDate([$budget->id], '2026-04-15');
        $this->assertCount(0, $notFound);
    }

    public function test_find_for_budgets_in_range(): void
    {
        $budget = $this->createBudget();

        BudgetPeriod::create([
            'budget_id' => $budget->id,
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-31',
            'amount_budgeted' => 500,
            'status' => BudgetPeriod::STATUS_ACTIVE,
        ]);
        BudgetPeriod::create([
            'budget_id' => $budget->id,
            'start_date' => '2026-04-01',
            'end_date' => '2026-04-30',
            'amount_budgeted' => 500,
            'status' => BudgetPeriod::STATUS_ACTIVE,
        ]);

        $found = $this->repository->findForBudgetsInRange([$budget->id], '2026-03-01', '2026-03-31');
        $this->assertCount(1, $found);
    }

    public function test_create_persists_period(): void
    {
        $budget = $this->createBudget();

        $period = $this->repository->create([
            'budget_id' => $budget->id,
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
            'amount_budgeted' => 750,
            'rollover_amount' => 50,
            'status' => BudgetPeriod::STATUS_ACTIVE,
        ]);

        $this->assertDatabaseHas('budget_periods', [
            'id' => $period->id,
            'amount_budgeted' => 750,
            'rollover_amount' => 50,
        ]);
    }

    public function test_update_modifies_period(): void
    {
        $budget = $this->createBudget();

        $period = BudgetPeriod::create([
            'budget_id' => $budget->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
            'amount_budgeted' => 500,
            'status' => BudgetPeriod::STATUS_ACTIVE,
        ]);

        $updated = $this->repository->updatePeriod($period, [
            'status' => BudgetPeriod::STATUS_CLOSED,
            'closed_at' => now(),
        ]);

        $this->assertSame(BudgetPeriod::STATUS_CLOSED, $updated->status);
    }

    public function test_delete_removes_period(): void
    {
        $budget = $this->createBudget();

        $period = BudgetPeriod::create([
            'budget_id' => $budget->id,
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-31',
            'amount_budgeted' => 500,
            'status' => BudgetPeriod::STATUS_ACTIVE,
        ]);

        $result = $this->repository->deletePeriod($period);
        $this->assertTrue($result);
        $this->assertDatabaseMissing('budget_periods', ['id' => $period->id]);
    }

    public function test_empty_budget_ids_returns_empty_collection(): void
    {
        $this->assertCount(0, $this->repository->findForBudgetsAndDate([], '2026-03-15'));
        $this->assertCount(0, $this->repository->findForBudgetsInRange([], '2026-01-01', '2026-12-31'));
    }
}
