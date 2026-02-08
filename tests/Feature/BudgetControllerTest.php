<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_access_budgets_index(): void
    {
        $this->get('/budgets')->assertRedirect('/login');
    }

    public function test_user_can_view_budgets_index(): void
    {
        $user = User::factory()->create();
        $category = $user->categories()->create(['name' => 'Food']);

        Budget::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'amount' => 400,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
            'year' => (int) date('Y'),
            'month' => (int) date('n'),
        ]);

        $response = $this->actingAs($user)->get('/budgets');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('budgets/Index')
            ->has('budgets')
            ->has('categories')
        );
    }

    public function test_user_can_create_budget(): void
    {
        $user = User::factory()->create();
        $category = $user->categories()->create(['name' => 'Groceries']);
        $year = (int) date('Y');
        $month = (int) date('n');

        $response = $this->actingAs($user)->post('/budgets', [
            'category_id' => $category->id,
            'amount' => 500,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
            'year' => $year,
            'month' => $month,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('budgets', [
            'user_id' => $user->id,
            'category_id' => $category->id,
            'amount' => 500,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
            'year' => $year,
            'month' => $month,
        ]);
    }

    public function test_user_can_create_yearly_budget(): void
    {
        $user = User::factory()->create();
        $category = $user->categories()->create(['name' => 'Travel']);
        $year = 2025;

        $response = $this->actingAs($user)->post('/budgets', [
            'category_id' => $category->id,
            'amount' => 3000,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_YEARLY,
            'year' => $year,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('budgets', [
            'user_id' => $user->id,
            'category_id' => $category->id,
            'amount' => 3000,
            'period_type' => Budget::PERIOD_YEARLY,
            'year' => $year,
            'month' => 0,
        ]);
    }

    public function test_user_can_update_own_budget(): void
    {
        $user = User::factory()->create();
        $category = $user->categories()->create(['name' => 'Food']);
        $budget = Budget::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'amount' => 400,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
            'year' => 2025,
            'month' => 2,
        ]);

        $response = $this->actingAs($user)->put("/budgets/{$budget->id}", [
            'amount' => 600,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
            'year' => 2025,
            'month' => 2,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('budgets', [
            'id' => $budget->id,
            'amount' => 600,
        ]);
    }

    public function test_user_cannot_update_other_users_budget(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $category = $other->categories()->create(['name' => 'Other']);
        $budget = Budget::create([
            'user_id' => $other->id,
            'category_id' => $category->id,
            'amount' => 400,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
            'year' => 2025,
            'month' => 2,
        ]);

        $this->actingAs($user)
            ->put("/budgets/{$budget->id}", ['amount' => 999])
            ->assertForbidden();
    }

    public function test_user_can_delete_own_budget(): void
    {
        $user = User::factory()->create();
        $category = $user->categories()->create(['name' => 'Food']);
        $budget = Budget::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'amount' => 400,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
            'year' => 2025,
            'month' => 2,
        ]);

        $response = $this->actingAs($user)->delete("/budgets/{$budget->id}");
        $response->assertRedirect();
        $this->assertDatabaseMissing('budgets', ['id' => $budget->id]);
    }

    public function test_user_cannot_delete_other_users_budget(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $category = $other->categories()->create(['name' => 'Other']);
        $budget = Budget::create([
            'user_id' => $other->id,
            'category_id' => $category->id,
            'amount' => 400,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
            'year' => 2025,
            'month' => 2,
        ]);

        $this->actingAs($user)->delete("/budgets/{$budget->id}")->assertForbidden();
        $this->assertDatabaseHas('budgets', ['id' => $budget->id]);
    }

    public function test_create_budget_validates_category_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $otherCategory = $other->categories()->create(['name' => 'Other']);

        $response = $this->actingAs($user)->post('/budgets', [
            'category_id' => $otherCategory->id,
            'amount' => 500,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
            'year' => (int) date('Y'),
            'month' => (int) date('n'),
        ]);

        $response->assertSessionHasErrors('category_id');
        $this->assertDatabaseCount('budgets', 0);
    }
}
