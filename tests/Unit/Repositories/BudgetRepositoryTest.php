<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Models\Budget;
use App\Models\Category;
use App\Models\User;
use App\Repositories\BudgetRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private BudgetRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new BudgetRepository(new Budget);
    }

    public function test_find_by_user_returns_only_that_users_budgets(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $cat1 = $user->categories()->create(['name' => 'A']);
        $cat2 = $user->categories()->create(['name' => 'B']);
        $otherCat = $other->categories()->create(['name' => 'Other']);

        Budget::create([
            'user_id' => $user->id,
            'category_id' => $cat1->id,
            'amount' => 100,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
            'year' => 2025,
            'month' => 1,
        ]);
        Budget::create([
            'user_id' => $user->id,
            'category_id' => $cat2->id,
            'amount' => 200,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
            'year' => 2025,
            'month' => 2,
        ]);
        Budget::create([
            'user_id' => $other->id,
            'category_id' => $otherCat->id,
            'amount' => 300,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
            'year' => 2025,
            'month' => 1,
        ]);

        $found = $this->repository->findByUser($user->id);
        $this->assertCount(2, $found);
        $this->assertTrue($found->every(fn ($b) => $b->user_id === $user->id));
    }

    public function test_find_for_user_and_period_filters_by_period(): void
    {
        $user = User::factory()->create();
        $category = $user->categories()->create(['name' => 'Food']);

        Budget::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'amount' => 100,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
            'year' => 2025,
            'month' => 3,
        ]);
        Budget::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'amount' => 200,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
            'year' => 2025,
            'month' => 4,
        ]);

        $found = $this->repository->findForUserAndPeriod($user->id, Budget::PERIOD_MONTHLY, 2025, 3);
        $this->assertCount(1, $found);
        $this->assertSame(100.0, (float) $found->first()->amount);
    }

    public function test_create_persists_budget(): void
    {
        $user = User::factory()->create();
        $category = $user->categories()->create(['name' => 'Food']);

        $budget = $this->repository->create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'amount' => 500,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
            'year' => 2025,
            'month' => 5,
        ]);

        $this->assertDatabaseHas('budgets', [
            'id' => $budget->id,
            'amount' => 500,
            'month' => 5,
        ]);
    }

    public function test_update_modifies_budget(): void
    {
        $user = User::factory()->create();
        $category = $user->categories()->create(['name' => 'Food']);
        $budget = Budget::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'amount' => 100,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
            'year' => 2025,
            'month' => 1,
        ]);

        $updated = $this->repository->update($budget, ['amount' => 250]);
        $this->assertSame(250.0, (float) $updated->amount);
        $this->assertDatabaseHas('budgets', ['id' => $budget->id, 'amount' => 250]);
    }

    public function test_delete_removes_budget(): void
    {
        $user = User::factory()->create();
        $category = $user->categories()->create(['name' => 'Food']);
        $budget = Budget::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'amount' => 100,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
            'year' => 2025,
            'month' => 1,
        ]);

        $result = $this->repository->delete($budget);
        $this->assertTrue($result);
        $this->assertDatabaseMissing('budgets', ['id' => $budget->id]);
    }
}
