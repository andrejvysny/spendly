<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Account;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\RecurringGroup;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BudgetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetServiceTest extends TestCase
{
    use RefreshDatabase;

    private BudgetService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(BudgetService::class);
    }

    public function test_get_spent_for_period_sums_expenses(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);
        $category = $user->categories()->create(['name' => 'Food']);
        $budget = Budget::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'amount' => 1000,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
        ]);

        $period = BudgetPeriod::create([
            'budget_id' => $budget->id,
            'start_date' => '2025-02-01',
            'end_date' => '2025-02-28',
            'amount_budgeted' => 1000,
            'status' => BudgetPeriod::STATUS_ACTIVE,
        ]);

        Transaction::create([
            'account_id' => $account->id,
            'transaction_id' => 'tx-1',
            'amount' => -100,
            'currency' => 'EUR',
            'booked_date' => '2025-02-01',
            'processed_date' => '2025-02-01',
            'description' => 'Shop',
            'type' => Transaction::TYPE_PAYMENT,
            'balance_after_transaction' => 0,
            'category_id' => $category->id,
        ]);
        Transaction::create([
            'account_id' => $account->id,
            'transaction_id' => 'tx-2',
            'amount' => -50,
            'currency' => 'EUR',
            'booked_date' => '2025-02-01',
            'processed_date' => '2025-02-01',
            'description' => 'Cafe',
            'type' => Transaction::TYPE_PAYMENT,
            'balance_after_transaction' => 0,
            'category_id' => $category->id,
        ]);
        // Deposit should not be counted
        Transaction::create([
            'account_id' => $account->id,
            'transaction_id' => 'tx-3',
            'amount' => 200,
            'currency' => 'EUR',
            'booked_date' => '2025-02-01',
            'processed_date' => '2025-02-01',
            'description' => 'Refund',
            'type' => Transaction::TYPE_DEPOSIT,
            'balance_after_transaction' => 0,
            'category_id' => $category->id,
        ]);

        $spent = $this->service->getSpentForPeriod($budget, $period);
        $this->assertSame(150.0, $spent);
    }

    public function test_get_spent_for_period_excludes_transfers(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);
        $category = $user->categories()->create(['name' => 'Food']);
        $budget = Budget::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'amount' => 500,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
        ]);

        $period = BudgetPeriod::create([
            'budget_id' => $budget->id,
            'start_date' => '2025-03-01',
            'end_date' => '2025-03-31',
            'amount_budgeted' => 500,
            'status' => BudgetPeriod::STATUS_ACTIVE,
        ]);

        Transaction::create([
            'account_id' => $account->id,
            'transaction_id' => 'tx-transfer',
            'amount' => -200,
            'currency' => 'EUR',
            'booked_date' => '2025-03-01',
            'processed_date' => '2025-03-01',
            'description' => 'Transfer',
            'type' => Transaction::TYPE_TRANSFER,
            'balance_after_transaction' => 0,
            'category_id' => $category->id,
        ]);

        $spent = $this->service->getSpentForPeriod($budget, $period);
        $this->assertSame(0.0, $spent);
    }

    public function test_get_spent_for_period_only_includes_matching_currency(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);
        $category = $user->categories()->create(['name' => 'Food']);
        $budget = Budget::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'amount' => 500,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
        ]);

        $period = BudgetPeriod::create([
            'budget_id' => $budget->id,
            'start_date' => '2025-04-01',
            'end_date' => '2025-04-30',
            'amount_budgeted' => 500,
            'status' => BudgetPeriod::STATUS_ACTIVE,
        ]);

        Transaction::create([
            'account_id' => $account->id,
            'transaction_id' => 'tx-usd',
            'amount' => -100,
            'currency' => 'USD',
            'booked_date' => '2025-04-01',
            'processed_date' => '2025-04-01',
            'description' => 'USD spend',
            'type' => Transaction::TYPE_PAYMENT,
            'balance_after_transaction' => 0,
            'category_id' => $category->id,
        ]);
        Transaction::create([
            'account_id' => $account->id,
            'transaction_id' => 'tx-eur',
            'amount' => -80,
            'currency' => 'EUR',
            'booked_date' => '2025-04-01',
            'processed_date' => '2025-04-01',
            'description' => 'EUR spend',
            'type' => Transaction::TYPE_PAYMENT,
            'balance_after_transaction' => 0,
            'category_id' => $category->id,
        ]);

        $spent = $this->service->getSpentForPeriod($budget, $period);
        $this->assertSame(80.0, $spent);
    }

    public function test_get_budgets_with_progress_returns_spent_remaining_and_exceeded(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);
        $category = $user->categories()->create(['name' => 'Food']);
        $budget = Budget::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'amount' => 100,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
        ]);

        BudgetPeriod::create([
            'budget_id' => $budget->id,
            'start_date' => '2025-05-01',
            'end_date' => '2025-05-31',
            'amount_budgeted' => 100,
            'status' => BudgetPeriod::STATUS_ACTIVE,
        ]);

        Transaction::create([
            'account_id' => $account->id,
            'transaction_id' => 'tx-over',
            'amount' => -120,
            'currency' => 'EUR',
            'booked_date' => '2025-05-01',
            'processed_date' => '2025-05-01',
            'description' => 'Over',
            'type' => Transaction::TYPE_PAYMENT,
            'balance_after_transaction' => 0,
            'category_id' => $category->id,
        ]);

        $collection = $this->service->getBudgetsWithProgress($user->id, Budget::PERIOD_MONTHLY, 2025, 5);
        $this->assertCount(1, $collection);
        $row = $collection->first();
        $this->assertNotNull($row);
        $this->assertSame(120.0, $row['spent']);
        $this->assertSame(0.0, $row['remaining']);
        $this->assertTrue($row['is_exceeded']);
        $this->assertSame(120.0, $row['percentage_used']);
        $this->assertNotNull($row['period']);
    }

    public function test_create_budget_auto_creates_period(): void
    {
        $user = User::factory()->create();
        $category = $user->categories()->create(['name' => 'Food']);

        $budget = $this->service->create($user->id, [
            'category_id' => $category->id,
            'amount' => 500,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
        ]);

        $this->assertDatabaseHas('budget_periods', [
            'budget_id' => $budget->id,
            'amount_budgeted' => 500,
            'status' => BudgetPeriod::STATUS_ACTIVE,
        ]);
    }

    public function test_get_suggested_amounts_from_recurring_groups(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);
        $category = $user->categories()->create(['name' => 'Subscriptions']);

        $group = RecurringGroup::create([
            'user_id' => $user->id,
            'name' => 'Spotify',
            'interval' => 'monthly',
            'interval_days' => 30,
            'amount_min' => -9.99,
            'amount_max' => -9.99,
            'scope' => RecurringGroup::SCOPE_PER_USER,
            'status' => RecurringGroup::STATUS_CONFIRMED,
        ]);

        for ($i = 0; $i < 3; $i++) {
            Transaction::create([
                'account_id' => $account->id,
                'transaction_id' => "tx-spotify-{$i}",
                'amount' => -9.99,
                'currency' => 'EUR',
                'booked_date' => now()->subMonths($i)->format('Y-m-d'),
                'processed_date' => now()->subMonths($i)->format('Y-m-d'),
                'description' => 'Spotify',
                'type' => Transaction::TYPE_PAYMENT,
                'balance_after_transaction' => 0,
                'category_id' => $category->id,
                'recurring_group_id' => $group->id,
            ]);
        }

        $suggestions = $this->service->getSuggestedAmounts($user->id);
        $this->assertNotEmpty($suggestions);
        $this->assertSame($category->id, $suggestions[0]['category_id']);
        $this->assertSame('Subscriptions', $suggestions[0]['category_name']);
        $this->assertGreaterThan(9.99, $suggestions[0]['suggested_amount']); // includes 10% buffer
    }

    public function test_get_suggested_amounts_empty_when_no_confirmed_groups(): void
    {
        $user = User::factory()->create();
        $suggestions = $this->service->getSuggestedAmounts($user->id);
        $this->assertEmpty($suggestions);
    }

    public function test_budgets_with_progress_auto_creates_missing_periods(): void
    {
        $user = User::factory()->create();
        $category = $user->categories()->create(['name' => 'Food']);

        Budget::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'amount' => 300,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
            'auto_create_next' => true,
        ]);

        // Request progress for a month with no existing period
        $collection = $this->service->getBudgetsWithProgress($user->id, Budget::PERIOD_MONTHLY, 2026, 6);
        $this->assertCount(1, $collection);
        $row = $collection->first();
        $this->assertNotNull($row['period']);
        $this->assertSame(300.0, (float) $row['period']->amount_budgeted);
    }
}
