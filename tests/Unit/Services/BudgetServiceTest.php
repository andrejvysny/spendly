<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Account;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Category;
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

    // -------------------------------------------------------------------------
    // getSpentForPeriod
    // -------------------------------------------------------------------------

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

    public function test_get_spent_for_period_returns_zero_when_user_has_no_accounts(): void
    {
        $user = User::factory()->create();
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
            'start_date' => '2025-06-01',
            'end_date' => '2025-06-30',
            'amount_budgeted' => 500,
            'status' => BudgetPeriod::STATUS_ACTIVE,
        ]);

        $spent = $this->service->getSpentForPeriod($budget, $period);
        $this->assertSame(0.0, $spent);
    }

    public function test_get_spent_for_period_excludes_transactions_outside_date_range(): void
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
            'start_date' => '2025-07-01',
            'end_date' => '2025-07-31',
            'amount_budgeted' => 500,
            'status' => BudgetPeriod::STATUS_ACTIVE,
        ]);

        // Inside period
        Transaction::create([
            'account_id' => $account->id,
            'transaction_id' => 'tx-inside',
            'amount' => -60,
            'currency' => 'EUR',
            'booked_date' => '2025-07-15',
            'processed_date' => '2025-07-15',
            'description' => 'Inside',
            'type' => Transaction::TYPE_PAYMENT,
            'balance_after_transaction' => 0,
            'category_id' => $category->id,
        ]);
        // Before period
        Transaction::create([
            'account_id' => $account->id,
            'transaction_id' => 'tx-before',
            'amount' => -200,
            'currency' => 'EUR',
            'booked_date' => '2025-06-30',
            'processed_date' => '2025-06-30',
            'description' => 'Before',
            'type' => Transaction::TYPE_PAYMENT,
            'balance_after_transaction' => 0,
            'category_id' => $category->id,
        ]);
        // After period
        Transaction::create([
            'account_id' => $account->id,
            'transaction_id' => 'tx-after',
            'amount' => -300,
            'currency' => 'EUR',
            'booked_date' => '2025-08-01',
            'processed_date' => '2025-08-01',
            'description' => 'After',
            'type' => Transaction::TYPE_PAYMENT,
            'balance_after_transaction' => 0,
            'category_id' => $category->id,
        ]);

        $spent = $this->service->getSpentForPeriod($budget, $period);
        $this->assertSame(60.0, $spent);
    }

    public function test_get_spent_for_period_sums_all_categories_when_budget_has_no_category(): void
    {
        // Budget with category_id=null is an "overall" budget — no category filter applied.
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);
        $catA = $user->categories()->create(['name' => 'Food']);
        $catB = $user->categories()->create(['name' => 'Transport']);

        $budget = Budget::create([
            'user_id' => $user->id,
            'category_id' => null,
            'amount' => 2000,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
        ]);

        $period = BudgetPeriod::create([
            'budget_id' => $budget->id,
            'start_date' => '2025-08-01',
            'end_date' => '2025-08-31',
            'amount_budgeted' => 2000,
            'status' => BudgetPeriod::STATUS_ACTIVE,
        ]);

        Transaction::create([
            'account_id' => $account->id,
            'transaction_id' => 'tx-food',
            'amount' => -100,
            'currency' => 'EUR',
            'booked_date' => '2025-08-10',
            'processed_date' => '2025-08-10',
            'description' => 'Food',
            'type' => Transaction::TYPE_PAYMENT,
            'balance_after_transaction' => 0,
            'category_id' => $catA->id,
        ]);
        Transaction::create([
            'account_id' => $account->id,
            'transaction_id' => 'tx-transport',
            'amount' => -40,
            'currency' => 'EUR',
            'booked_date' => '2025-08-10',
            'processed_date' => '2025-08-10',
            'description' => 'Bus',
            'type' => Transaction::TYPE_PAYMENT,
            'balance_after_transaction' => 0,
            'category_id' => $catB->id,
        ]);

        $spent = $this->service->getSpentForPeriod($budget, $period);
        $this->assertSame(140.0, $spent);
    }

    public function test_get_spent_for_period_uses_rollover_as_effective_amount_in_progress(): void
    {
        // Verify getEffectiveAmount() on period includes rollover (tested via model directly).
        $user = User::factory()->create();
        $budget = Budget::create([
            'user_id' => $user->id,
            'category_id' => null,
            'amount' => 200,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
            'rollover_enabled' => true,
        ]);

        $period = BudgetPeriod::factory()->create([
            'budget_id' => $budget->id,
            'amount_budgeted' => 200,
            'rollover_amount' => 50,
            'status' => BudgetPeriod::STATUS_ACTIVE,
        ]);

        // Effective amount = 200 + 50 = 250
        $this->assertSame(250.0, $period->getEffectiveAmount());
    }

    // -------------------------------------------------------------------------
    // getBudgetsWithProgress
    // -------------------------------------------------------------------------

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

    public function test_get_budgets_with_progress_returns_empty_when_no_budgets_exist(): void
    {
        $user = User::factory()->create();

        $collection = $this->service->getBudgetsWithProgress($user->id, Budget::PERIOD_MONTHLY, 2025, 1);
        $this->assertCount(0, $collection);
    }

    public function test_get_budgets_with_progress_excludes_inactive_budgets(): void
    {
        $user = User::factory()->create();
        $category = $user->categories()->create(['name' => 'Food']);

        // Inactive budget should not appear in results.
        Budget::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'amount' => 300,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
            'is_active' => false,
        ]);

        $collection = $this->service->getBudgetsWithProgress($user->id, Budget::PERIOD_MONTHLY, 2025, 9);
        $this->assertCount(0, $collection);
    }

    public function test_get_budgets_with_progress_calculates_percentage_correctly(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);
        $category = $user->categories()->create(['name' => 'Food']);
        $budget = Budget::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'amount' => 200,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
        ]);

        BudgetPeriod::create([
            'budget_id' => $budget->id,
            'start_date' => '2025-10-01',
            'end_date' => '2025-10-31',
            'amount_budgeted' => 200,
            'status' => BudgetPeriod::STATUS_ACTIVE,
        ]);

        Transaction::create([
            'account_id' => $account->id,
            'transaction_id' => 'tx-half',
            'amount' => -100,
            'currency' => 'EUR',
            'booked_date' => '2025-10-15',
            'processed_date' => '2025-10-15',
            'description' => 'Half',
            'type' => Transaction::TYPE_PAYMENT,
            'balance_after_transaction' => 0,
            'category_id' => $category->id,
        ]);

        $collection = $this->service->getBudgetsWithProgress($user->id, Budget::PERIOD_MONTHLY, 2025, 10);
        $row = $collection->first();
        $this->assertNotNull($row);
        $this->assertSame(50.0, $row['percentage_used']);
        $this->assertSame(100.0, $row['remaining']);
        $this->assertFalse($row['is_exceeded']);
    }

    public function test_get_budgets_with_progress_yearly_budget_covers_full_year(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);
        $category = $user->categories()->create(['name' => 'Travel']);

        $budget = Budget::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'amount' => 1200,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_YEARLY,
            'auto_create_next' => true,
        ]);

        BudgetPeriod::create([
            'budget_id' => $budget->id,
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
            'amount_budgeted' => 1200,
            'status' => BudgetPeriod::STATUS_ACTIVE,
        ]);

        // Transaction in middle of year
        Transaction::create([
            'account_id' => $account->id,
            'transaction_id' => 'tx-travel',
            'amount' => -300,
            'currency' => 'EUR',
            'booked_date' => '2025-06-15',
            'processed_date' => '2025-06-15',
            'description' => 'Flight',
            'type' => Transaction::TYPE_PAYMENT,
            'balance_after_transaction' => 0,
            'category_id' => $category->id,
        ]);

        $collection = $this->service->getBudgetsWithProgress($user->id, Budget::PERIOD_YEARLY, 2025, null);
        $this->assertCount(1, $collection);
        $row = $collection->first();
        $this->assertNotNull($row);
        $this->assertSame(300.0, $row['spent']);
        $this->assertSame(900.0, $row['remaining']);
        $this->assertFalse($row['is_exceeded']);
    }

    // -------------------------------------------------------------------------
    // create (auto period creation)
    // -------------------------------------------------------------------------

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

    public function test_create_yearly_budget_auto_creates_period_spanning_full_year(): void
    {
        $user = User::factory()->create();
        $category = $user->categories()->create(['name' => 'Travel']);

        $budget = $this->service->create($user->id, [
            'category_id' => $category->id,
            'amount' => 3000,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_YEARLY,
        ]);

        $period = BudgetPeriod::where('budget_id', $budget->id)->first();
        $this->assertNotNull($period);

        $currentYear = (int) now()->format('Y');
        $this->assertSame("{$currentYear}-01-01", $period->start_date->format('Y-m-d'));
        $this->assertSame("{$currentYear}-12-31", $period->end_date->format('Y-m-d'));
    }

    // -------------------------------------------------------------------------
    // update
    // -------------------------------------------------------------------------

    public function test_update_budget_persists_changes(): void
    {
        $user = User::factory()->create();
        $category = $user->categories()->create(['name' => 'Food']);
        $budget = Budget::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'amount' => 200,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
        ]);

        $updated = $this->service->update($budget, ['amount' => 400]);

        $this->assertSame('400.00', $updated->amount);
    }

    public function test_update_sets_period_type_to_yearly(): void
    {
        $user = User::factory()->create();
        $category = $user->categories()->create(['name' => 'Food']);
        $budget = Budget::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'amount' => 500,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
        ]);

        // Switching to yearly — month column was removed in migration; only period_type persists.
        $updated = $this->service->update($budget, ['period_type' => Budget::PERIOD_YEARLY]);

        $this->assertSame(Budget::PERIOD_YEARLY, $updated->period_type);
        $this->assertDatabaseHas('budgets', [
            'id' => $budget->id,
            'period_type' => Budget::PERIOD_YEARLY,
        ]);
    }

    // -------------------------------------------------------------------------
    // delete
    // -------------------------------------------------------------------------

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

        $result = $this->service->delete($budget);

        $this->assertTrue($result);
        $this->assertDatabaseMissing('budgets', ['id' => $budget->id]);
    }

    // -------------------------------------------------------------------------
    // Auto-create missing periods
    // -------------------------------------------------------------------------

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

    public function test_budgets_with_progress_does_not_auto_create_period_when_auto_create_disabled(): void
    {
        $user = User::factory()->create();
        $category = $user->categories()->create(['name' => 'Food']);

        Budget::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'amount' => 300,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
            'auto_create_next' => false,
        ]);

        $collection = $this->service->getBudgetsWithProgress($user->id, Budget::PERIOD_MONTHLY, 2026, 8);
        $this->assertCount(1, $collection);
        $row = $collection->first();
        // No period should be created — period is null
        $this->assertNull($row['period']);
        // Spent defaults to 0 when no period
        $this->assertSame(0.0, $row['spent']);
    }

    public function test_auto_create_copies_amount_from_previous_period(): void
    {
        $user = User::factory()->create();
        $category = $user->categories()->create(['name' => 'Food']);

        $budget = Budget::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'amount' => 300,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
            'auto_create_next' => true,
        ]);

        // Existing previous period with a different amount
        BudgetPeriod::create([
            'budget_id' => $budget->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
            'amount_budgeted' => 450,
            'status' => BudgetPeriod::STATUS_CLOSED,
        ]);

        // Request July — should copy 450 from June, not 300 from budget default
        $collection = $this->service->getBudgetsWithProgress($user->id, Budget::PERIOD_MONTHLY, 2026, 7);
        $row = $collection->first();
        $this->assertNotNull($row['period']);
        $this->assertSame(450.0, (float) $row['period']->amount_budgeted);
    }

    // -------------------------------------------------------------------------
    // getSuggestedAmounts
    // -------------------------------------------------------------------------

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

    public function test_get_suggested_amounts_ignores_non_confirmed_recurring_groups(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);
        $category = $user->categories()->create(['name' => 'Subscriptions']);

        $group = RecurringGroup::create([
            'user_id' => $user->id,
            'name' => 'Netflix',
            'interval' => 'monthly',
            'interval_days' => 30,
            'amount_min' => -15,
            'amount_max' => -15,
            'scope' => RecurringGroup::SCOPE_PER_USER,
            'status' => RecurringGroup::STATUS_SUGGESTED,
        ]);

        Transaction::create([
            'account_id' => $account->id,
            'transaction_id' => 'tx-netflix',
            'amount' => -15,
            'currency' => 'EUR',
            'booked_date' => now()->format('Y-m-d'),
            'processed_date' => now()->format('Y-m-d'),
            'description' => 'Netflix',
            'type' => Transaction::TYPE_PAYMENT,
            'balance_after_transaction' => 0,
            'category_id' => $category->id,
            'recurring_group_id' => $group->id,
        ]);

        $suggestions = $this->service->getSuggestedAmounts($user->id);
        $this->assertEmpty($suggestions);
    }

    public function test_get_suggested_amounts_weekly_interval_converts_to_monthly(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);
        $category = $user->categories()->create(['name' => 'Coffee']);

        $group = RecurringGroup::create([
            'user_id' => $user->id,
            'name' => 'Weekly Coffee',
            'interval' => 'weekly',
            'interval_days' => 7,
            'amount_min' => -5,
            'amount_max' => -5,
            'scope' => RecurringGroup::SCOPE_PER_USER,
            'status' => RecurringGroup::STATUS_CONFIRMED,
        ]);

        for ($i = 0; $i < 4; $i++) {
            Transaction::create([
                'account_id' => $account->id,
                'transaction_id' => "tx-coffee-{$i}",
                'amount' => -5,
                'currency' => 'EUR',
                'booked_date' => now()->subWeeks($i)->format('Y-m-d'),
                'processed_date' => now()->subWeeks($i)->format('Y-m-d'),
                'description' => 'Coffee',
                'type' => Transaction::TYPE_PAYMENT,
                'balance_after_transaction' => 0,
                'category_id' => $category->id,
                'recurring_group_id' => $group->id,
            ]);
        }

        $suggestions = $this->service->getSuggestedAmounts($user->id);
        $this->assertNotEmpty($suggestions);

        // Weekly 5 => monthly: 5 * (52/12) ≈ 21.67, with 10% buffer ≈ 23.83
        $suggestedAmount = $suggestions[0]['suggested_amount'];
        $this->assertGreaterThan(20.0, $suggestedAmount);
    }

    public function test_get_suggested_amounts_sorts_by_highest_amount_first(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);
        $catA = $user->categories()->create(['name' => 'Cheap']);
        $catB = $user->categories()->create(['name' => 'Expensive']);

        foreach ([
            ['name' => 'Small', 'amount' => -5, 'category' => $catA, 'interval' => 'monthly', 'interval_days' => 30, 'id_suffix' => 'small'],
            ['name' => 'Big',   'amount' => -500, 'category' => $catB, 'interval' => 'monthly', 'interval_days' => 30, 'id_suffix' => 'big'],
        ] as $spec) {
            $group = RecurringGroup::create([
                'user_id' => $user->id,
                'name' => $spec['name'],
                'interval' => $spec['interval'],
                'interval_days' => $spec['interval_days'],
                'amount_min' => $spec['amount'],
                'amount_max' => $spec['amount'],
                'scope' => RecurringGroup::SCOPE_PER_USER,
                'status' => RecurringGroup::STATUS_CONFIRMED,
            ]);
            Transaction::create([
                'account_id' => $account->id,
                'transaction_id' => "tx-{$spec['id_suffix']}",
                'amount' => $spec['amount'],
                'currency' => 'EUR',
                'booked_date' => now()->format('Y-m-d'),
                'processed_date' => now()->format('Y-m-d'),
                'description' => $spec['name'],
                'type' => Transaction::TYPE_PAYMENT,
                'balance_after_transaction' => 0,
                'category_id' => $spec['category']->id,
                'recurring_group_id' => $group->id,
            ]);
        }

        $suggestions = $this->service->getSuggestedAmounts($user->id);
        $this->assertCount(2, $suggestions);
        // Highest suggested_amount must come first
        $this->assertGreaterThan($suggestions[1]['suggested_amount'], $suggestions[0]['suggested_amount']);
    }

    // -------------------------------------------------------------------------
    // Subcategory spending
    // -------------------------------------------------------------------------

    public function test_spent_includes_subcategories(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);
        $parent = $user->categories()->create(['name' => 'Food']);
        $child = $user->categories()->create(['name' => 'Groceries', 'parent_category_id' => $parent->id]);

        $budget = Budget::create([
            'user_id' => $user->id,
            'category_id' => $parent->id,
            'amount' => 500,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
            'include_subcategories' => true,
        ]);

        $period = BudgetPeriod::create([
            'budget_id' => $budget->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
            'amount_budgeted' => 500,
            'status' => BudgetPeriod::STATUS_ACTIVE,
        ]);

        Transaction::create([
            'account_id' => $account->id,
            'transaction_id' => 'tx-parent',
            'amount' => -100,
            'currency' => 'EUR',
            'booked_date' => '2026-01-10',
            'processed_date' => '2026-01-10',
            'description' => 'Restaurant',
            'type' => Transaction::TYPE_PAYMENT,
            'balance_after_transaction' => 0,
            'category_id' => $parent->id,
        ]);
        Transaction::create([
            'account_id' => $account->id,
            'transaction_id' => 'tx-child',
            'amount' => -50,
            'currency' => 'EUR',
            'booked_date' => '2026-01-10',
            'processed_date' => '2026-01-10',
            'description' => 'Supermarket',
            'type' => Transaction::TYPE_PAYMENT,
            'balance_after_transaction' => 0,
            'category_id' => $child->id,
        ]);

        $spent = $this->service->getSpentForPeriod($budget, $period);
        $this->assertSame(150.0, $spent);
    }

    public function test_spent_excludes_subcategories_when_disabled(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);
        $parent = $user->categories()->create(['name' => 'Food']);
        $child = $user->categories()->create(['name' => 'Groceries', 'parent_category_id' => $parent->id]);

        $budget = Budget::create([
            'user_id' => $user->id,
            'category_id' => $parent->id,
            'amount' => 500,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
            'include_subcategories' => false,
        ]);

        $period = BudgetPeriod::create([
            'budget_id' => $budget->id,
            'start_date' => '2026-02-01',
            'end_date' => '2026-02-28',
            'amount_budgeted' => 500,
            'status' => BudgetPeriod::STATUS_ACTIVE,
        ]);

        Transaction::create([
            'account_id' => $account->id,
            'transaction_id' => 'tx-parent-only',
            'amount' => -100,
            'currency' => 'EUR',
            'booked_date' => '2026-02-10',
            'processed_date' => '2026-02-10',
            'description' => 'Restaurant',
            'type' => Transaction::TYPE_PAYMENT,
            'balance_after_transaction' => 0,
            'category_id' => $parent->id,
        ]);
        Transaction::create([
            'account_id' => $account->id,
            'transaction_id' => 'tx-child-excluded',
            'amount' => -50,
            'currency' => 'EUR',
            'booked_date' => '2026-02-10',
            'processed_date' => '2026-02-10',
            'description' => 'Supermarket',
            'type' => Transaction::TYPE_PAYMENT,
            'balance_after_transaction' => 0,
            'category_id' => $child->id,
        ]);

        $spent = $this->service->getSpentForPeriod($budget, $period);
        $this->assertSame(100.0, $spent);
    }

    // -------------------------------------------------------------------------
    // Rollover calculation
    // -------------------------------------------------------------------------

    public function test_rollover_surplus(): void
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
            'rollover_enabled' => true,
        ]);

        $period = BudgetPeriod::create([
            'budget_id' => $budget->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
            'amount_budgeted' => 500,
            'rollover_amount' => 0,
            'status' => BudgetPeriod::STATUS_ACTIVE,
        ]);

        // Spend 300 of 500 → surplus of 200
        Transaction::create([
            'account_id' => $account->id,
            'transaction_id' => 'tx-rollover-1',
            'amount' => -300,
            'currency' => 'EUR',
            'booked_date' => '2026-01-15',
            'processed_date' => '2026-01-15',
            'description' => 'Shopping',
            'type' => Transaction::TYPE_PAYMENT,
            'balance_after_transaction' => 0,
            'category_id' => $category->id,
        ]);

        $rollover = $this->service->calculateRollover($budget, $period);
        $this->assertSame(200.0, $rollover);
    }

    public function test_rollover_deficit(): void
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
            'rollover_enabled' => true,
        ]);

        $period = BudgetPeriod::create([
            'budget_id' => $budget->id,
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-31',
            'amount_budgeted' => 500,
            'rollover_amount' => 0,
            'status' => BudgetPeriod::STATUS_ACTIVE,
        ]);

        // Spend 700 of 500 → deficit of -200
        Transaction::create([
            'account_id' => $account->id,
            'transaction_id' => 'tx-rollover-deficit',
            'amount' => -700,
            'currency' => 'EUR',
            'booked_date' => '2026-03-15',
            'processed_date' => '2026-03-15',
            'description' => 'Shopping',
            'type' => Transaction::TYPE_PAYMENT,
            'balance_after_transaction' => 0,
            'category_id' => $category->id,
        ]);

        $rollover = $this->service->calculateRollover($budget, $period);
        $this->assertSame(-200.0, $rollover);
    }

    public function test_rollover_capped_deficit(): void
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
            'rollover_enabled' => true,
            'rollover_cap' => 100, // cap at -100
        ]);

        $period = BudgetPeriod::create([
            'budget_id' => $budget->id,
            'start_date' => '2026-04-01',
            'end_date' => '2026-04-30',
            'amount_budgeted' => 500,
            'rollover_amount' => 0,
            'status' => BudgetPeriod::STATUS_ACTIVE,
        ]);

        // Spend 800 of 500 → raw deficit -300, capped to -100
        Transaction::create([
            'account_id' => $account->id,
            'transaction_id' => 'tx-rollover-capped',
            'amount' => -800,
            'currency' => 'EUR',
            'booked_date' => '2026-04-15',
            'processed_date' => '2026-04-15',
            'description' => 'Shopping',
            'type' => Transaction::TYPE_PAYMENT,
            'balance_after_transaction' => 0,
            'category_id' => $category->id,
        ]);

        $rollover = $this->service->calculateRollover($budget, $period);
        $this->assertSame(-100.0, $rollover);
    }

    public function test_rollover_unlimited_when_cap_null(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);
        $category = $user->categories()->create(['name' => 'Food']);

        $budget = Budget::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'amount' => 200,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
            'rollover_enabled' => true,
            'rollover_cap' => null,
        ]);

        $period = BudgetPeriod::create([
            'budget_id' => $budget->id,
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
            'amount_budgeted' => 200,
            'rollover_amount' => 0,
            'status' => BudgetPeriod::STATUS_ACTIVE,
        ]);

        // Spend 900 of 200 → raw deficit -700, no cap
        Transaction::create([
            'account_id' => $account->id,
            'transaction_id' => 'tx-rollover-unlimited',
            'amount' => -900,
            'currency' => 'EUR',
            'booked_date' => '2026-05-15',
            'processed_date' => '2026-05-15',
            'description' => 'Shopping',
            'type' => Transaction::TYPE_PAYMENT,
            'balance_after_transaction' => 0,
            'category_id' => $category->id,
        ]);

        $rollover = $this->service->calculateRollover($budget, $period);
        $this->assertSame(-700.0, $rollover);
    }

    public function test_rollover_zero_when_disabled(): void
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
            'rollover_enabled' => false,
            'auto_create_next' => true,
        ]);

        BudgetPeriod::create([
            'budget_id' => $budget->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
            'amount_budgeted' => 500,
            'rollover_amount' => 0,
            'status' => BudgetPeriod::STATUS_ACTIVE,
        ]);

        Transaction::create([
            'account_id' => $account->id,
            'transaction_id' => 'tx-no-rollover',
            'amount' => -300,
            'currency' => 'EUR',
            'booked_date' => '2026-06-15',
            'processed_date' => '2026-06-15',
            'description' => 'Shopping',
            'type' => Transaction::TYPE_PAYMENT,
            'balance_after_transaction' => 0,
            'category_id' => $category->id,
        ]);

        // Auto-create July period — rollover should be 0
        $collection = $this->service->getBudgetsWithProgress($user->id, Budget::PERIOD_MONTHLY, 2026, 7);
        $row = $collection->first();
        $this->assertNotNull($row['period']);
        $this->assertSame(0.0, (float) $row['period']->rollover_amount);
    }

    // -------------------------------------------------------------------------
    // Period closing
    // -------------------------------------------------------------------------

    public function test_auto_create_closes_previous_period(): void
    {
        $user = User::factory()->create();
        $category = $user->categories()->create(['name' => 'Food']);

        $budget = Budget::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'amount' => 300,
            'currency' => 'EUR',
            'period_type' => Budget::PERIOD_MONTHLY,
            'auto_create_next' => true,
        ]);

        $previousPeriod = BudgetPeriod::create([
            'budget_id' => $budget->id,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
            'amount_budgeted' => 300,
            'status' => BudgetPeriod::STATUS_ACTIVE,
        ]);

        // Request September — should close August
        $this->service->getBudgetsWithProgress($user->id, Budget::PERIOD_MONTHLY, 2026, 9);

        $previousPeriod->refresh();
        $this->assertSame(BudgetPeriod::STATUS_CLOSED, $previousPeriod->status);
        $this->assertNotNull($previousPeriod->closed_at);
    }

    // -------------------------------------------------------------------------
    // Pace calculation
    // -------------------------------------------------------------------------

    public function test_get_budgets_with_progress_includes_pace_data(): void
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
            'start_date' => now()->startOfMonth()->format('Y-m-d'),
            'end_date' => now()->endOfMonth()->format('Y-m-d'),
            'amount_budgeted' => 100,
            'status' => BudgetPeriod::STATUS_ACTIVE,
        ]);

        $collection = $this->service->getBudgetsWithProgress(
            $user->id,
            Budget::PERIOD_MONTHLY,
            (int) now()->format('Y'),
            (int) now()->format('n')
        );

        $row = $collection->first();
        $this->assertNotNull($row);
        $this->assertArrayHasKey('pace_percentage', $row);
        $this->assertArrayHasKey('projected_total', $row);
        $this->assertArrayHasKey('days_elapsed', $row);
        $this->assertArrayHasKey('days_in_period', $row);
        $this->assertGreaterThan(0, $row['days_elapsed']);
        $this->assertGreaterThan(0, $row['days_in_period']);
    }

    // -------------------------------------------------------------------------
    // Budget history
    // -------------------------------------------------------------------------

    public function test_get_budget_history_returns_periods_with_spent(): void
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

        BudgetPeriod::create([
            'budget_id' => $budget->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
            'amount_budgeted' => 500,
            'rollover_amount' => 0,
            'status' => BudgetPeriod::STATUS_CLOSED,
        ]);

        BudgetPeriod::create([
            'budget_id' => $budget->id,
            'start_date' => '2026-02-01',
            'end_date' => '2026-02-28',
            'amount_budgeted' => 500,
            'rollover_amount' => 50,
            'status' => BudgetPeriod::STATUS_ACTIVE,
        ]);

        Transaction::create([
            'account_id' => $account->id,
            'transaction_id' => 'tx-history',
            'amount' => -200,
            'currency' => 'EUR',
            'booked_date' => '2026-02-10',
            'processed_date' => '2026-02-10',
            'description' => 'Shop',
            'type' => Transaction::TYPE_PAYMENT,
            'balance_after_transaction' => 0,
            'category_id' => $category->id,
        ]);

        $history = $this->service->getBudgetHistory($user->id, $budget->id, 6);
        $this->assertCount(2, $history);
        $this->assertSame('Jan 2026', $history[0]['label']);
        $this->assertSame(500.0, $history[0]['budgeted']);
        $this->assertSame('Feb 2026', $history[1]['label']);
        $this->assertSame(200.0, $history[1]['spent']);
        $this->assertSame(50.0, $history[1]['rollover']);
    }
}
