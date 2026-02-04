<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_authenticated_users_can_visit_the_dashboard(): void
    {
        $this->actingAs($user = User::factory()->create());

        $this->get('/dashboard')->assertOk();
    }

    public function test_dashboard_returns_correct_stats_structure(): void
    {
        $user = User::factory()->create();
        
        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('dashboard')
                ->has('accounts') // Existing prop
                ->has('recentTransactions') // Existing prop
                ->has('monthlyBalances') // Updated prop
                ->has('currentMonthStats', fn ($stats) => $stats
                    ->has('income')
                    ->has('expenses')
                )
                ->has('expensesByCategory')
            );
    }
}
