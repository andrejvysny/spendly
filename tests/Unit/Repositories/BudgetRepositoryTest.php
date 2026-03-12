<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Models\Budget;
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
        ]);
        Budget::create([
            'user_id' => $user->id,
            'category_id' => $cat2->id,
            'amount' => 200,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
        ]);
        Budget::create([
            'user_id' => $other->id,
            'category_id' => $otherCat->id,
            'amount' => 300,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
        ]);

        $found = $this->repository->findByUser($user->id);
        $this->assertCount(2, $found);
        $this->assertTrue($found->every(fn ($b) => $b->user_id === $user->id));
    }

    public function test_find_active_by_user_excludes_inactive(): void
    {
        $user = User::factory()->create();
        $cat1 = $user->categories()->create(['name' => 'A']);
        $cat2 = $user->categories()->create(['name' => 'B']);

        Budget::create([
            'user_id' => $user->id,
            'category_id' => $cat1->id,
            'amount' => 100,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
            'is_active' => true,
        ]);
        Budget::create([
            'user_id' => $user->id,
            'category_id' => $cat2->id,
            'amount' => 200,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
            'is_active' => false,
        ]);

        $found = $this->repository->findActiveByUser($user->id);
        $this->assertCount(1, $found);
    }

    public function test_find_by_user_and_period_type(): void
    {
        $user = User::factory()->create();
        $cat1 = $user->categories()->create(['name' => 'Food']);
        $cat2 = $user->categories()->create(['name' => 'Travel']);

        Budget::create([
            'user_id' => $user->id,
            'category_id' => $cat1->id,
            'amount' => 100,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
        ]);
        Budget::create([
            'user_id' => $user->id,
            'category_id' => $cat2->id,
            'amount' => 2000,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_YEARLY,
        ]);

        $monthly = $this->repository->findByUserAndPeriodType($user->id, Budget::PERIOD_MONTHLY);
        $this->assertCount(1, $monthly);

        $yearly = $this->repository->findByUserAndPeriodType($user->id, Budget::PERIOD_YEARLY);
        $this->assertCount(1, $yearly);
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
        ]);

        $this->assertDatabaseHas('budgets', [
            'id' => $budget->id,
            'amount' => 500,
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
        ]);

        $updated = $this->repository->update($budget->id, ['amount' => 250]);
        $this->assertNotNull($updated);
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
        ]);

        $result = $this->repository->delete($budget);
        $this->assertTrue($result);
        $this->assertDatabaseMissing('budgets', ['id' => $budget->id]);
    }

    public function test_create_budget_with_nullable_category(): void
    {
        $user = User::factory()->create();

        $budget = $this->repository->create([
            'user_id' => $user->id,
            'category_id' => null,
            'target_type' => Budget::TARGET_OVERALL,
            'target_key' => 'overall',
            'amount' => 1500,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
            'name' => 'Overall Monthly',
        ]);

        $this->assertDatabaseHas('budgets', [
            'id' => $budget->id,
            'category_id' => null,
            'name' => 'Overall Monthly',
        ]);
    }
}
