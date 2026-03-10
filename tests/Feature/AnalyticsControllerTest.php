<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsControllerTest extends TestCase
{
    use RefreshDatabase;

    private const string MIXED_CURRENCY_MESSAGE = 'Analytics for multiple currencies is not supported yet. Please select accounts with the same currency.';

    public function test_analytics_index_exposes_currency_error_for_mixed_currency_accounts(): void
    {
        $user = User::factory()->create();
        $eurAccount = Account::factory()->create([
            'user_id' => $user->id,
            'currency' => 'EUR',
        ]);
        $usdAccount = Account::factory()->create([
            'user_id' => $user->id,
            'currency' => 'USD',
        ]);

        $this->actingAs($user)
            ->get('/analytics?account_ids[]='.$eurAccount->id.'&account_ids[]='.$usdAccount->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Analytics/Index')
                ->where('currency_error', self::MIXED_CURRENCY_MESSAGE)
            );
    }

    public function test_balance_history_endpoint_rejects_mixed_currency_accounts(): void
    {
        $user = User::factory()->create();
        $eurAccount = Account::factory()->create([
            'user_id' => $user->id,
            'currency' => 'EUR',
        ]);
        $usdAccount = Account::factory()->create([
            'user_id' => $user->id,
            'currency' => 'USD',
        ]);

        $this->actingAs($user)
            ->getJson('/api/analytics/balance-history?account_ids[]='.$eurAccount->id.'&account_ids[]='.$usdAccount->id)
            ->assertStatus(422)
            ->assertJson([
                'message' => self::MIXED_CURRENCY_MESSAGE,
            ]);
    }

    public function test_monthly_comparison_endpoint_rejects_mixed_currency_accounts(): void
    {
        $user = User::factory()->create();
        $eurAccount = Account::factory()->create([
            'user_id' => $user->id,
            'currency' => 'EUR',
        ]);
        $usdAccount = Account::factory()->create([
            'user_id' => $user->id,
            'currency' => 'USD',
        ]);

        $this->actingAs($user)
            ->getJson(
                '/api/analytics/monthly-comparison?account_ids[]='.$eurAccount->id.
                '&account_ids[]='.$usdAccount->id.
                '&first_month=2025-01&second_month=2025-02'
            )
            ->assertStatus(422)
            ->assertJson([
                'message' => self::MIXED_CURRENCY_MESSAGE,
            ]);
    }
}
