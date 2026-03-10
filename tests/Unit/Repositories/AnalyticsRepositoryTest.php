<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\AnalyticsRepository;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private AnalyticsRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new AnalyticsRepository;
    }

    public function test_get_cashflow_uses_booked_date_and_zero_fills_daily_series(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'currency' => 'EUR',
        ]);

        Transaction::factory()->create([
            'account_id' => $account->id,
            'amount' => -50.00,
            'currency' => 'EUR',
            'booked_date' => Carbon::parse('2025-01-15'),
            'processed_date' => Carbon::parse('2025-02-15'),
            'type' => Transaction::TYPE_PAYMENT,
        ]);

        Transaction::factory()->create([
            'account_id' => $account->id,
            'amount' => -20.00,
            'currency' => 'EUR',
            'booked_date' => Carbon::parse('2025-02-01'),
            'processed_date' => Carbon::parse('2025-01-15'),
            'type' => Transaction::TYPE_PAYMENT,
        ]);

        $cashflow = $this->repository->getCashflow(
            [$account->id],
            Carbon::parse('2025-01-14')->startOfDay(),
            Carbon::parse('2025-01-16')->endOfDay()
        )->values();

        $this->assertCount(3, $cashflow);
        $this->assertSame(0, $cashflow[0]['transaction_count']);
        $this->assertSame(1, $cashflow[1]['transaction_count']);
        $this->assertSame(50.0, (float) $cashflow[1]['total_expenses']);
        $this->assertSame(-50.0, (float) $cashflow[1]['day_balance']);
        $this->assertSame(0, $cashflow[2]['transaction_count']);
        $this->assertSame(0.0, (float) $cashflow[2]['total_expenses']);
    }

    public function test_get_cashflow_zero_fills_months_for_long_ranges(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'currency' => 'EUR',
        ]);

        Transaction::factory()->create([
            'account_id' => $account->id,
            'amount' => 200.00,
            'currency' => 'EUR',
            'booked_date' => Carbon::parse('2025-02-10'),
            'processed_date' => Carbon::parse('2025-02-10'),
            'type' => Transaction::TYPE_DEPOSIT,
        ]);

        $cashflow = $this->repository->getCashflow(
            [$account->id],
            Carbon::parse('2025-01-01')->startOfDay(),
            Carbon::parse('2025-03-31')->endOfDay()
        )->values();

        $this->assertCount(3, $cashflow);
        $this->assertSame(1, $cashflow[0]['month']);
        $this->assertSame(0, $cashflow[0]['transaction_count']);
        $this->assertSame(2, $cashflow[1]['month']);
        $this->assertSame(1, $cashflow[1]['transaction_count']);
        $this->assertSame(200.0, (float) $cashflow[1]['total_income']);
        $this->assertSame(3, $cashflow[2]['month']);
        $this->assertSame(0, $cashflow[2]['transaction_count']);
    }

    public function test_get_balance_history_is_anchored_to_requested_end_date(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'currency' => 'EUR',
            'balance' => 130.00,
        ]);

        Transaction::factory()->create([
            'account_id' => $account->id,
            'amount' => 100.00,
            'currency' => 'EUR',
            'booked_date' => Carbon::parse('2025-01-10'),
            'processed_date' => Carbon::parse('2025-01-10'),
            'type' => Transaction::TYPE_DEPOSIT,
        ]);

        Transaction::factory()->create([
            'account_id' => $account->id,
            'amount' => -20.00,
            'currency' => 'EUR',
            'booked_date' => Carbon::parse('2025-01-20'),
            'processed_date' => Carbon::parse('2025-01-20'),
            'type' => Transaction::TYPE_PAYMENT,
        ]);

        Transaction::factory()->create([
            'account_id' => $account->id,
            'amount' => 50.00,
            'currency' => 'EUR',
            'booked_date' => Carbon::parse('2025-02-05'),
            'processed_date' => Carbon::parse('2025-02-05'),
            'type' => Transaction::TYPE_DEPOSIT,
        ]);

        $history = $this->repository->getBalanceHistory(
            [$account->id],
            [$account->id => 130.0],
            Carbon::parse('2025-01-19')->startOfDay(),
            Carbon::parse('2025-01-21')->endOfDay(),
            'day'
        );

        $this->assertSame([
            ['date' => '2025-01-19', 'balance' => 100.0],
            ['date' => '2025-01-20', 'balance' => 80.0],
            ['date' => '2025-01-21', 'balance' => 80.0],
        ], $history[$account->id]);
    }
}
