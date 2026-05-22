<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Account;
use App\Models\User;
use App\Providers\GoCardlessServiceProvider;
use App\Services\GoCardless\ClientFactory\GoCardlessClientFactoryInterface;
use App\Services\GoCardless\GoCardlessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoCardlessSyncControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gocardless.use_mock' => true]);

        // Re-resolve mock factory after config change so singletons pick up mock
        $this->app->forgetInstance(GoCardlessClientFactoryInterface::class);
        $this->app->forgetInstance(GoCardlessService::class);
        (new GoCardlessServiceProvider($this->app))->register();

        $this->user = User::factory()->create();
        $this->account = Account::factory()->create([
            'user_id' => $this->user->id,
            'gocardless_account_id' => 'mock_account_1',
            'gocardless_institution_id' => 'MOCK_INSTITUTION',
            'is_gocardless_synced' => true,
            'currency' => 'EUR',
        ]);
    }

    // ── syncAccountTransactions ───────────────────────────────────────────

    public function test_guest_cannot_sync_account_transactions(): void
    {
        $this->postJson("/api/bank-data/gocardless/accounts/{$this->account->id}/sync-transactions")
            ->assertUnauthorized();
    }

    public function test_authenticated_user_can_sync_account_transactions(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/bank-data/gocardless/accounts/{$this->account->id}/sync-transactions");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Transactions synced successfully')
            ->assertJsonStructure(['success', 'message', 'data']);
    }

    public function test_sync_accepts_optional_flags(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/bank-data/gocardless/accounts/{$this->account->id}/sync-transactions", [
                'update_existing' => false,
                'force_max_date_range' => true,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_user_cannot_sync_another_users_account(): void
    {
        $otherUser = User::factory()->create();
        $otherAccount = Account::factory()->create([
            'user_id' => $otherUser->id,
            'gocardless_account_id' => 'mock_account_2',
            'is_gocardless_synced' => true,
        ]);

        // The GoCardless service will fail to find the account scoped to our user,
        // so it should return an error response (not a 200).
        $response = $this->actingAs($this->user)
            ->postJson("/api/bank-data/gocardless/accounts/{$otherAccount->id}/sync-transactions");

        // Either a 500 (sync error) or we can assert it doesn't succeed for the wrong user
        $this->assertNotEquals(200, $response->getStatusCode(), 'Should not successfully sync another user\'s account');
    }

    // ── syncAllAccounts ───────────────────────────────────────────────────

    public function test_guest_cannot_sync_all_accounts(): void
    {
        $this->postJson('/api/bank-data/gocardless/accounts/sync-all')
            ->assertUnauthorized();
    }

    public function test_authenticated_user_can_sync_all_accounts(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/bank-data/gocardless/accounts/sync-all');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'All accounts synced')
            ->assertJsonStructure(['success', 'message', 'data']);
    }

    public function test_sync_all_returns_array_of_results(): void
    {
        // Create a second GoCardless account for this user
        Account::factory()->create([
            'user_id' => $this->user->id,
            'gocardless_account_id' => 'mock_account_2',
            'gocardless_institution_id' => 'MOCK_INSTITUTION',
            'is_gocardless_synced' => true,
            'currency' => 'EUR',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/bank-data/gocardless/accounts/sync-all');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertIsArray($data);
    }

    public function test_sync_all_accepts_optional_flags(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/bank-data/gocardless/accounts/sync-all', [
                'update_existing' => false,
                'force_max_date_range' => true,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true);
    }

    // ── refreshAccountBalance ─────────────────────────────────────────────

    public function test_guest_cannot_refresh_balance(): void
    {
        $this->postJson("/api/bank-data/gocardless/accounts/{$this->account->id}/refresh-balance")
            ->assertUnauthorized();
    }

    public function test_refresh_balance_returns_404_for_nonexistent_account(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/bank-data/gocardless/accounts/99999/refresh-balance')
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_authenticated_user_can_refresh_gocardless_account_balance(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/bank-data/gocardless/accounts/{$this->account->id}/refresh-balance");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['account_id', 'balance', 'currency', 'source'],
            ]);

        $this->assertSame('gocardless_api', $response->json('data.source'));
    }

    public function test_refresh_balance_recalculates_from_transactions_for_manual_account(): void
    {
        $manualAccount = Account::factory()->create([
            'user_id' => $this->user->id,
            'is_gocardless_synced' => false,
            'currency' => 'EUR',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/bank-data/gocardless/accounts/{$manualAccount->id}/refresh-balance");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.source', 'transactions');
    }

    public function test_user_cannot_refresh_another_users_account_balance(): void
    {
        $otherUser = User::factory()->create();
        $otherAccount = Account::factory()->create([
            'user_id' => $otherUser->id,
            'is_gocardless_synced' => false,
        ]);

        $this->actingAs($this->user)
            ->postJson("/api/bank-data/gocardless/accounts/{$otherAccount->id}/refresh-balance")
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }
}
