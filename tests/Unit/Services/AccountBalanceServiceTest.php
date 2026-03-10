<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccountBalanceService;
use Carbon\Carbon;
use Tests\TestCase;

class AccountBalanceServiceTest extends TestCase
{
    public function test_calculate_balance_rolls_forward_from_latest_running_balance_when_newer_rows_have_null_balance(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'opening_balance' => null,
        ]);

        Transaction::factory()->create([
            'account_id' => $account->id,
            'booked_date' => Carbon::parse('2026-01-01'),
            'processed_date' => Carbon::parse('2026-01-01'),
            'amount' => 100.00,
            'balance_after_transaction' => 100.00,
        ]);
        Transaction::factory()->create([
            'account_id' => $account->id,
            'booked_date' => Carbon::parse('2026-01-02'),
            'processed_date' => Carbon::parse('2026-01-02'),
            'amount' => -20.00,
            'balance_after_transaction' => null,
        ]);

        $balance = $this->app->make(AccountBalanceService::class)->calculateBalanceForAccount($account);

        $this->assertSame(80.0, $balance);
    }

    public function test_calculate_balance_falls_back_to_opening_balance_plus_transaction_sum_when_no_running_balances_exist(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'opening_balance' => 50.00,
        ]);

        Transaction::factory()->create([
            'account_id' => $account->id,
            'booked_date' => Carbon::parse('2026-01-03'),
            'processed_date' => Carbon::parse('2026-01-03'),
            'amount' => 10.00,
            'balance_after_transaction' => null,
        ]);
        Transaction::factory()->create([
            'account_id' => $account->id,
            'booked_date' => Carbon::parse('2026-01-04'),
            'processed_date' => Carbon::parse('2026-01-04'),
            'amount' => -5.00,
            'balance_after_transaction' => null,
        ]);

        $balance = $this->app->make(AccountBalanceService::class)->calculateBalanceForAccount($account);

        $this->assertSame(55.0, $balance);
    }
}
