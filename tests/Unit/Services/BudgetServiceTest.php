<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
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

    public function test_get_spent_for_budget_sums_expenses_in_period_and_currency(): void
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
            'year' => 2025,
            'month' => 2,
        ]);

        $febStart = '2025-02-01 00:00:00';
        $febEnd = '2025-02-28 23:59:59';

        Transaction::create([
            'account_id' => $account->id,
            'transaction_id' => 'tx-1',
            'amount' => -100,
            'currency' => 'EUR',
            'booked_date' => $febStart,
            'processed_date' => $febStart,
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
            'booked_date' => $febStart,
            'processed_date' => $febStart,
            'description' => 'Cafe',
            'type' => Transaction::TYPE_PAYMENT,
            'balance_after_transaction' => 0,
            'category_id' => $category->id,
        ]);
        Transaction::create([
            'account_id' => $account->id,
            'transaction_id' => 'tx-3',
            'amount' => 200,
            'currency' => 'EUR',
            'booked_date' => $febStart,
            'processed_date' => $febStart,
            'description' => 'Refund',
            'type' => Transaction::TYPE_DEPOSIT,
            'balance_after_transaction' => 0,
            'category_id' => $category->id,
        ]);

        $spent = $this->service->getSpentForBudget($budget);
        $this->assertSame(150.0, $spent);
    }

    public function test_get_spent_for_budget_excludes_transfers(): void
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
            'year' => 2025,
            'month' => 3,
        ]);

        $marStart = '2025-03-01 00:00:00';
        Transaction::create([
            'account_id' => $account->id,
            'transaction_id' => 'tx-transfer',
            'amount' => -200,
            'currency' => 'EUR',
            'booked_date' => $marStart,
            'processed_date' => $marStart,
            'description' => 'Transfer',
            'type' => Transaction::TYPE_TRANSFER,
            'balance_after_transaction' => 0,
            'category_id' => $category->id,
        ]);

        $spent = $this->service->getSpentForBudget($budget);
        $this->assertSame(0.0, $spent);
    }

    public function test_get_spent_for_budget_only_includes_matching_currency(): void
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
            'year' => 2025,
            'month' => 4,
        ]);

        $aprStart = '2025-04-01 00:00:00';
        Transaction::create([
            'account_id' => $account->id,
            'transaction_id' => 'tx-usd',
            'amount' => -100,
            'currency' => 'USD',
            'booked_date' => $aprStart,
            'processed_date' => $aprStart,
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
            'booked_date' => $aprStart,
            'processed_date' => $aprStart,
            'description' => 'EUR spend',
            'type' => Transaction::TYPE_PAYMENT,
            'balance_after_transaction' => 0,
            'category_id' => $category->id,
        ]);

        $spent = $this->service->getSpentForBudget($budget);
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
            'year' => 2025,
            'month' => 5,
        ]);

        $mayStart = '2025-05-01 00:00:00';
        Transaction::create([
            'account_id' => $account->id,
            'transaction_id' => 'tx-over',
            'amount' => -120,
            'currency' => 'EUR',
            'booked_date' => $mayStart,
            'processed_date' => $mayStart,
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
    }
}
