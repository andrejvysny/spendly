<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\RecurringGroup;
use App\Models\Transaction;
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

        $budget = Budget::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'amount' => 400,
            'currency' => 'EUR',
            'mode' => Budget::MODE_LIMIT,
            'period_type' => Budget::PERIOD_MONTHLY,
        ]);

        BudgetPeriod::create([
            'budget_id' => $budget->id,
            'start_date' => now()->startOfMonth()->format('Y-m-d'),
            'end_date' => now()->endOfMonth()->format('Y-m-d'),
            'amount_budgeted' => 400,
            'status' => BudgetPeriod::STATUS_ACTIVE,
        ]);

        $this->withoutVite();
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

        $response = $this->actingAs($user)->post('/budgets', [
            'category_id' => $category->id,
            'amount' => 500,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('budgets', [
            'user_id' => $user->id,
            'category_id' => $category->id,
            'amount' => 500,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
        ]);
        // Verify auto-created period
        $budget = Budget::where('user_id', $user->id)->first();
        $this->assertDatabaseHas('budget_periods', [
            'budget_id' => $budget->id,
            'amount_budgeted' => 500,
            'status' => BudgetPeriod::STATUS_ACTIVE,
        ]);
    }

    public function test_user_can_create_yearly_budget(): void
    {
        $user = User::factory()->create();
        $category = $user->categories()->create(['name' => 'Travel']);

        $response = $this->actingAs($user)->post('/budgets', [
            'category_id' => $category->id,
            'amount' => 3000,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_YEARLY,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('budgets', [
            'user_id' => $user->id,
            'category_id' => $category->id,
            'amount' => 3000,
            'period_type' => Budget::PERIOD_YEARLY,
        ]);
    }

    public function test_user_can_create_budget_without_category(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/budgets', [
            'amount' => 2000,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('budgets', [
            'user_id' => $user->id,
            'category_id' => null,
            'amount' => 2000,
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
        ]);

        $response = $this->actingAs($user)->put("/budgets/{$budget->id}", [
            'amount' => 600,
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
        ]);

        $response->assertSessionHasErrors('category_id');
        $this->assertDatabaseCount('budgets', 0);
    }

    public function test_user_can_view_builder_page(): void
    {
        $user = User::factory()->create();
        $user->categories()->create(['name' => 'Food']);

        $this->withoutVite();
        $response = $this->actingAs($user)->get('/budgets/builder');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('budgets/Builder')
            ->has('categories')
            ->has('existingBudgets')
            ->has('defaultCurrency')
        );
    }

    public function test_suggestions_endpoint_returns_json(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/budgets/suggestions');
        $response->assertOk();
        $response->assertJsonStructure(['suggestions']);
    }

    public function test_suggestions_include_recurring_groups(): void
    {
        $user = User::factory()->create();
        $account = $user->accounts()->create([
            'name' => 'Test',
            'currency' => 'EUR',
            'type' => 'checking',
            'balance' => 1000,
        ]);
        $category = $user->categories()->create(['name' => 'Subscriptions']);

        $group = RecurringGroup::create([
            'user_id' => $user->id,
            'name' => 'Netflix',
            'interval' => 'monthly',
            'interval_days' => 30,
            'amount_min' => -15.99,
            'amount_max' => -15.99,
            'scope' => RecurringGroup::SCOPE_PER_USER,
            'status' => RecurringGroup::STATUS_CONFIRMED,
        ]);

        // Create transactions linked to this recurring group
        for ($i = 0; $i < 3; $i++) {
            \App\Models\Transaction::create([
                'account_id' => $account->id,
                'transaction_id' => "tx-netflix-{$i}",
                'amount' => -15.99,
                'currency' => 'EUR',
                'booked_date' => now()->subMonths($i)->format('Y-m-d'),
                'processed_date' => now()->subMonths($i)->format('Y-m-d'),
                'description' => 'Netflix',
                'type' => 'card_payment',
                'balance_after_transaction' => 900,
                'category_id' => $category->id,
                'recurring_group_id' => $group->id,
            ]);
        }

        $response = $this->actingAs($user)->getJson('/budgets/suggestions');
        $response->assertOk();
        $response->assertJsonPath('suggestions.0.category_name', 'Subscriptions');
        $this->assertGreaterThan(0, $response->json('suggestions.0.suggested_amount'));
    }

    public function test_budget_periods_cascade_delete_with_budget(): void
    {
        $user = User::factory()->create();
        $category = $user->categories()->create(['name' => 'Food']);
        $budget = Budget::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'amount' => 400,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
        ]);

        BudgetPeriod::create([
            'budget_id' => $budget->id,
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-31',
            'amount_budgeted' => 400,
            'status' => BudgetPeriod::STATUS_ACTIVE,
        ]);

        $this->actingAs($user)->delete("/budgets/{$budget->id}");
        $this->assertDatabaseMissing('budget_periods', ['budget_id' => $budget->id]);
    }

    public function test_history_endpoint_returns_json(): void
    {
        $user = User::factory()->create();
        $category = $user->categories()->create(['name' => 'Food']);
        $budget = Budget::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'amount' => 400,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
        ]);

        BudgetPeriod::create([
            'budget_id' => $budget->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
            'amount_budgeted' => 400,
            'status' => BudgetPeriod::STATUS_CLOSED,
        ]);

        $response = $this->actingAs($user)->getJson("/budgets/{$budget->id}/history");
        $response->assertOk();
        $response->assertJsonStructure(['history']);
        $this->assertCount(1, $response->json('history'));
    }

    public function test_user_cannot_view_other_users_budget_history(): void
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
        ]);

        $this->actingAs($user)->getJson("/budgets/{$budget->id}/history")->assertForbidden();
    }

    public function test_update_period_amount(): void
    {
        $user = User::factory()->create();
        $category = $user->categories()->create(['name' => 'Food']);
        $budget = Budget::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'amount' => 400,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
        ]);

        $period = BudgetPeriod::create([
            'budget_id' => $budget->id,
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-31',
            'amount_budgeted' => 400,
            'status' => BudgetPeriod::STATUS_ACTIVE,
        ]);

        $response = $this->actingAs($user)->put("/budgets/{$budget->id}/periods/{$period->id}", [
            'amount_budgeted' => 600,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('budget_periods', [
            'id' => $period->id,
            'amount_budgeted' => 600,
        ]);
    }

    public function test_index_includes_uncategorized_count(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);

        Transaction::create([
            'account_id' => $account->id,
            'transaction_id' => 'tx-uncat',
            'amount' => -50,
            'currency' => 'EUR',
            'booked_date' => now()->format('Y-m-d'),
            'processed_date' => now()->format('Y-m-d'),
            'description' => 'Unknown',
            'type' => Transaction::TYPE_PAYMENT,
            'balance_after_transaction' => 0,
            'category_id' => null,
        ]);

        $this->withoutVite();
        $response = $this->actingAs($user)->get('/budgets');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('budgets/Index')
            ->where('uncategorizedCount', 1)
        );
    }

    public function test_create_budget_with_rollover_cap(): void
    {
        $user = User::factory()->create();
        $category = $user->categories()->create(['name' => 'Food']);

        $response = $this->actingAs($user)->post('/budgets', [
            'category_id' => $category->id,
            'amount' => 500,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
            'rollover_enabled' => true,
            'rollover_cap' => 100,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('budgets', [
            'user_id' => $user->id,
            'rollover_enabled' => true,
            'rollover_cap' => 100,
        ]);
    }
}
