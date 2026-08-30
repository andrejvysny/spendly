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

    public function test_analytics_index_supports_mixed_currency_accounts(): void
    {
        // Multi-currency is now normalized to the user's base currency (native_amount),
        // not rejected. The page renders and reports the display currency.
        $user = User::factory()->create(['base_currency' => 'EUR']);
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
                ->where('display_currency', 'EUR')
            );
    }

    public function test_balance_history_endpoint_supports_mixed_currency_accounts(): void
    {
        $user = User::factory()->create(['base_currency' => 'EUR']);
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
            ->assertOk()
            ->assertJsonPath('display_currency', 'EUR');
    }

    public function test_monthly_comparison_endpoint_supports_mixed_currency_accounts(): void
    {
        $user = User::factory()->create(['base_currency' => 'EUR']);
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
            ->assertOk();
    }

    // -----------------------------------------------------------------
    // Auth / guest redirect
    // -----------------------------------------------------------------

    public function test_guest_is_redirected_from_analytics_index(): void
    {
        $this->get('/analytics')->assertRedirect(route('login'));
    }

    public function test_guest_is_redirected_from_balance_history(): void
    {
        $this->getJson('/api/analytics/balance-history')->assertUnauthorized();
    }

    // -----------------------------------------------------------------
    // Authenticated — happy path
    // -----------------------------------------------------------------

    public function test_authenticated_user_can_view_analytics_with_no_data(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/analytics')->assertOk();
    }

    public function test_analytics_index_renders_inertia_component(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)
            ->get('/analytics')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Analytics/Index'));
    }

    // -----------------------------------------------------------------
    // Carbon null-guard: malformed period / date params must not throw
    // -----------------------------------------------------------------

    public function test_invalid_specific_month_param_does_not_crash(): void
    {
        // Carbon::createFromFormat('Y-m', 'foo') returns false in older Carbon
        // and the null-guard at line 110 must prevent a TypeError.
        $user = User::factory()->create();
        $this->actingAs($user)
            ->get('/analytics?period=specific_month&month=foo')
            ->assertOk();
    }

    public function test_valid_specific_month_param_renders_correctly(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)
            ->get('/analytics?period=specific_month&month=2026-03')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Analytics/Index'));
    }

    public function test_custom_period_with_valid_dates_renders_correctly(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)
            ->get('/analytics?period=custom&start_date=2026-01-01&end_date=2026-03-31')
            ->assertOk();
    }

    public function test_all_named_periods_do_not_crash(): void
    {
        $user = User::factory()->create();
        $periods = ['last_month', 'current_month', 'last_3_months', 'last_6_months', 'current_year', 'last_year'];

        foreach ($periods as $period) {
            $this->actingAs($user)
                ->get('/analytics?period='.$period)
                ->assertOk();
        }
    }

    // -----------------------------------------------------------------
    // Ownership: analytics only shows user's own data
    // -----------------------------------------------------------------

    public function test_analytics_only_shows_user_owned_accounts(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $accountB = Account::factory()->create(['user_id' => $userB->id, 'currency' => 'EUR']);

        // A has no accounts — the foreign account must not appear in the response
        $this->actingAs($userA)
            ->get('/analytics')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Analytics/Index')
                ->where('accounts', fn ($accounts) => collect($accounts)->doesntContain('id', $accountB->id))
            );
    }

    // -----------------------------------------------------------------
    // monthlyComparison null-guard (API endpoint)
    // -----------------------------------------------------------------

    public function test_monthly_comparison_with_valid_params_returns_json(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);

        $this->actingAs($user)
            ->getJson(
                '/api/analytics/monthly-comparison?account_ids[]='.$account->id.
                '&first_month=2026-01&second_month=2026-02'
            )
            ->assertOk()
            ->assertJsonStructure(['first_month', 'second_month']);
    }

    public function test_monthly_comparison_with_no_owned_accounts_returns_empty(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $accountB = Account::factory()->create(['user_id' => $userB->id, 'currency' => 'EUR']);

        // account_ids belong to userB — intersection is empty → early-return {}
        $this->actingAs($userA)
            ->getJson(
                '/api/analytics/monthly-comparison?account_ids[]='.$accountB->id.
                '&first_month=2026-01&second_month=2026-02'
            )
            ->assertOk()
            ->assertJson(['first_month' => [], 'second_month' => []]);
    }

    // -----------------------------------------------------------------
    // balanceHistory endpoint
    // -----------------------------------------------------------------

    public function test_balance_history_returns_expected_structure(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);

        $this->actingAs($user)
            ->getJson('/api/analytics/balance-history?account_ids[]='.$account->id)
            ->assertOk()
            ->assertJsonStructure(['accounts', 'balance_history', 'net_worth_history', 'date_range', 'granularity']);
    }

    public function test_balance_history_with_foreign_account_ids_returns_only_own_data(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $accountB = Account::factory()->create(['user_id' => $userB->id, 'currency' => 'EUR']);

        // userA passes userB's account id — intersection logic must filter it out
        $this->actingAs($userA)
            ->getJson('/api/analytics/balance-history?account_ids[]='.$accountB->id)
            ->assertOk()
            ->assertJsonPath('accounts', []);
    }
}
