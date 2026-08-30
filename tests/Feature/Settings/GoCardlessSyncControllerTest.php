<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Enums\GoCardlessRequisitionStatus;
use App\Exceptions\GoCardlessConsentExpiredException;
use App\Jobs\SyncGoCardlessAccountJob;
use App\Models\Account;
use App\Models\GoCardlessRequisition;
use App\Models\User;
use App\Providers\GoCardlessServiceProvider;
use App\Services\GoCardless\BankDataClientInterface;
use App\Services\GoCardless\ClientFactory\GoCardlessClientFactoryInterface;
use App\Services\GoCardless\GoCardlessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
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

    /**
     * Sync is queued, so the endpoint acknowledges the request (202) instead of reporting
     * results it no longer has. The account is stamped before the response returns, so a
     * client that polls immediately never reads a stale 'idle'.
     */
    public function test_sync_request_queues_a_job_and_returns_202(): void
    {
        Queue::fake();

        $this->actingAs($this->user)
            ->postJson("/api/bank-data/gocardless/accounts/{$this->account->id}/sync-transactions")
            ->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('data.sync_status', 'queued')
            ->assertJsonStructure(['success', 'status', 'data' => ['account_id', 'sync_status', 'queued_at']]);

        Queue::assertPushed(
            SyncGoCardlessAccountJob::class,
            fn (SyncGoCardlessAccountJob $job): bool => $job->accountId === (int) $this->account->id
                && $job->userId === (int) $this->user->id,
        );

        $this->assertSame(Account::SYNC_STATUS_QUEUED, $this->account->refresh()->gocardless_sync_status);
    }

    public function test_sync_accepts_optional_flags(): void
    {
        Queue::fake();

        $this->actingAs($this->user)
            ->postJson("/api/bank-data/gocardless/accounts/{$this->account->id}/sync-transactions", [
                'update_existing' => false,
                'force_max_date_range' => true,
            ])
            ->assertStatus(202);

        Queue::assertPushed(
            SyncGoCardlessAccountJob::class,
            fn (SyncGoCardlessAccountJob $job): bool => $job->updateExisting === false && $job->forceMaxDateRange === true,
        );
    }

    public function test_user_cannot_sync_another_users_account(): void
    {
        Queue::fake();

        $otherUser = User::factory()->create();
        $otherAccount = Account::factory()->create([
            'user_id' => $otherUser->id,
            'gocardless_account_id' => 'mock_account_2',
            'is_gocardless_synced' => true,
        ]);

        $this->actingAs($this->user)
            ->postJson("/api/bank-data/gocardless/accounts/{$otherAccount->id}/sync-transactions")
            ->assertNotFound();

        Queue::assertNothingPushed();
    }

    /**
     * Pressing Sync twice must not book the work twice. The job's unique lock would collapse
     * the duplicate anyway, but re-queuing would reset the timestamps the client is polling.
     */
    public function test_repeat_sync_request_is_idempotent_while_a_job_is_booked(): void
    {
        Queue::fake();
        $this->account->update(['gocardless_sync_status' => Account::SYNC_STATUS_SYNCING]);

        $this->actingAs($this->user)
            ->postJson("/api/bank-data/gocardless/accounts/{$this->account->id}/sync-transactions")
            ->assertStatus(202)
            ->assertJsonPath('status', 'syncing');

        Queue::assertNothingPushed();
    }

    /**
     * The cooldown a rate-limited job recorded is enforced here too, otherwise a user with a
     * Sync button can walk the account straight back into the bank's limit.
     */
    public function test_sync_request_is_refused_while_the_account_is_cooling_down(): void
    {
        Queue::fake();
        $this->account->update([
            'gocardless_sync_status' => Account::SYNC_STATUS_RATE_LIMITED,
            'gocardless_sync_retry_after' => now()->addMinutes(5),
        ]);

        $this->actingAs($this->user)
            ->postJson("/api/bank-data/gocardless/accounts/{$this->account->id}/sync-transactions")
            ->assertStatus(429)
            ->assertJsonPath('error', 'rate_limited')
            ->assertJsonStructure(['retry_after']);

        Queue::assertNothingPushed();
    }

    public function test_sync_request_is_refused_when_the_account_needs_reconnecting(): void
    {
        Queue::fake();
        $this->account->update(['gocardless_needs_reconnect' => true]);

        $this->actingAs($this->user)
            ->postJson("/api/bank-data/gocardless/accounts/{$this->account->id}/sync-transactions")
            ->assertStatus(409)
            ->assertJsonPath('error', 'reconnect_required');

        Queue::assertNothingPushed();
    }

    // ── syncAllAccounts ───────────────────────────────────────────────────

    public function test_guest_cannot_sync_all_accounts(): void
    {
        $this->postJson('/api/bank-data/gocardless/accounts/sync-all')
            ->assertUnauthorized();
    }

    public function test_sync_all_queues_one_job_per_account_and_returns_202(): void
    {
        Queue::fake();

        Account::factory()->create([
            'user_id' => $this->user->id,
            'gocardless_account_id' => 'mock_account_2',
            'gocardless_institution_id' => 'MOCK_INSTITUTION',
            'is_gocardless_synced' => true,
            'currency' => 'EUR',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/bank-data/gocardless/accounts/sync-all')
            ->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['success', 'status', 'message', 'data']);

        $this->assertIsArray($response->json('data'));
        $this->assertCount(2, $response->json('data'));

        Queue::assertPushed(SyncGoCardlessAccountJob::class, 2);
    }

    /**
     * Accounts that cannot be queued are reported rather than dropped, so the client can tell
     * "nothing to do" apart from "your bank connection is dead".
     */
    public function test_sync_all_reports_accounts_it_could_not_queue(): void
    {
        Queue::fake();
        $this->account->update(['gocardless_needs_reconnect' => true]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/bank-data/gocardless/accounts/sync-all')
            ->assertStatus(202);

        $this->assertSame('needs_reconnect', $response->json('data.0.sync_status'));
        Queue::assertNothingPushed();
    }

    public function test_sync_all_accepts_optional_flags(): void
    {
        Queue::fake();

        $this->actingAs($this->user)
            ->postJson('/api/bank-data/gocardless/accounts/sync-all', [
                'update_existing' => false,
                'force_max_date_range' => true,
            ])
            ->assertStatus(202)
            ->assertJsonPath('success', true);

        Queue::assertPushed(
            SyncGoCardlessAccountJob::class,
            fn (SyncGoCardlessAccountJob $job): bool => $job->updateExisting === false && $job->forceMaxDateRange === true,
        );
    }

    // ── sync status ───────────────────────────────────────────────────────

    public function test_guest_cannot_read_sync_status(): void
    {
        $this->getJson("/api/bank-data/gocardless/accounts/{$this->account->id}/sync-status")
            ->assertUnauthorized();
        $this->getJson('/api/bank-data/gocardless/accounts/sync-status')
            ->assertUnauthorized();
    }

    /**
     * This payload is polled every few seconds by any logged-in browser, so it is whitelisted
     * rather than filtered: the account row also carries the IBAN and the provider's account id.
     */
    public function test_sync_status_exposes_only_whitelisted_fields(): void
    {
        $this->account->update([
            'gocardless_sync_status' => Account::SYNC_STATUS_FAILED,
            'gocardless_sync_error' => 'upstream exploded',
            'gocardless_sync_finished_at' => now(),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/bank-data/gocardless/accounts/{$this->account->id}/sync-status")
            ->assertOk()
            ->assertJsonPath('data.sync_status', 'failed')
            ->assertJsonPath('data.error', 'upstream exploded');

        $payload = $response->json('data');
        $this->assertIsArray($payload);
        $this->assertSame(
            ['error', 'finished_at', 'id', 'last_synced_at', 'needs_reconnect', 'retry_after_seconds', 'sync_status'],
            collect(array_keys($payload))->sort()->values()->all(),
        );
        $this->assertStringNotContainsString((string) $this->account->iban, $response->getContent() ?: '');
    }

    public function test_sync_status_reports_remaining_cooldown_in_seconds(): void
    {
        $this->account->update([
            'gocardless_sync_status' => Account::SYNC_STATUS_RATE_LIMITED,
            'gocardless_sync_retry_after' => now()->addMinutes(5),
        ]);

        $seconds = $this->actingAs($this->user)
            ->getJson("/api/bank-data/gocardless/accounts/{$this->account->id}/sync-status")
            ->assertOk()
            ->json('data.retry_after_seconds');

        $this->assertIsInt($seconds);
        $this->assertGreaterThan(0, $seconds);
        $this->assertLessThanOrEqual(300, $seconds);
    }

    public function test_expired_cooldown_is_not_reported(): void
    {
        $this->account->update(['gocardless_sync_retry_after' => now()->subMinute()]);

        $this->actingAs($this->user)
            ->getJson("/api/bank-data/gocardless/accounts/{$this->account->id}/sync-status")
            ->assertOk()
            ->assertJsonPath('data.retry_after_seconds', null);
    }

    public function test_user_cannot_read_another_users_sync_status(): void
    {
        $otherAccount = Account::factory()->create([
            'user_id' => User::factory()->create()->id,
            'gocardless_account_id' => 'mock_account_2',
            'is_gocardless_synced' => true,
        ]);

        $this->actingAs($this->user)
            ->getJson("/api/bank-data/gocardless/accounts/{$otherAccount->id}/sync-status")
            ->assertNotFound();
    }

    public function test_sync_status_list_is_scoped_to_the_owner(): void
    {
        Account::factory()->create([
            'user_id' => User::factory()->create()->id,
            'gocardless_account_id' => 'mock_account_2',
            'is_gocardless_synced' => true,
        ]);

        $data = $this->actingAs($this->user)
            ->getJson('/api/bank-data/gocardless/accounts/sync-status')
            ->assertOk()
            ->json('data');

        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $row = $data[0];
        $this->assertIsArray($row);
        $this->assertSame($this->account->id, $row['id']);
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

    // ── expired consent ───────────────────────────────────────────────────

    /**
     * Swap in a client that reports the bank has withdrawn access, leaving the real
     * GoCardlessService in place so the flagging it does is exercised too.
     */
    private function fakeConsentExpiredClient(): void
    {
        $client = Mockery::mock(BankDataClientInterface::class);
        // @phpstan-ignore-next-line — Mockery shouldReceive() union type; no phpstan-mockery extension configured
        $client->shouldReceive('getTransactions')->andThrow(new GoCardlessConsentExpiredException('req_stale', 'mock_account_1'));
        // @phpstan-ignore-next-line — Mockery shouldReceive() union type; no phpstan-mockery extension configured
        $client->shouldReceive('getBalances')->andThrow(new GoCardlessConsentExpiredException('req_stale', 'mock_account_1'));

        $factory = Mockery::mock(GoCardlessClientFactoryInterface::class);
        // @phpstan-ignore-next-line — Mockery shouldReceive() union type; no phpstan-mockery extension configured
        $factory->shouldReceive('make')->andReturn($client);

        $this->app->instance(GoCardlessClientFactoryInterface::class, $factory);
        $this->app->forgetInstance(GoCardlessService::class);
    }

    private function linkAccountToLiveRequisition(): GoCardlessRequisition
    {
        $requisition = GoCardlessRequisition::factory()->linked()->for($this->user)->create([
            'requisition_id' => 'req_stale',
            'accounts' => ['mock_account_1'],
        ]);

        $this->account->update([
            'gocardless_requisition_id' => $requisition->id,
            'gocardless_needs_reconnect' => false,
        ]);

        return $requisition;
    }

    /**
     * Balance refresh is the one GoCardless call still made in-request, so it is also the only
     * one that can still surface an expired consent as an HTTP status. The queued sync's
     * equivalent lives in SyncGoCardlessAccountJobTest.
     */
    public function test_expired_consent_during_balance_refresh_returns_409_and_flags_the_account(): void
    {
        $requisition = $this->linkAccountToLiveRequisition();
        $this->fakeConsentExpiredClient();

        $this->actingAs($this->user)
            ->postJson("/api/bank-data/gocardless/accounts/{$this->account->id}/refresh-balance")
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'reconnect_required');

        $this->assertTrue((bool) $this->account->refresh()->gocardless_needs_reconnect);
        $this->assertSame(GoCardlessRequisitionStatus::EXPIRED, $requisition->refresh()->status);
    }

    public function test_expired_consent_does_not_touch_another_users_requisition(): void
    {
        $intruder = User::factory()->create();
        $theirRequisition = GoCardlessRequisition::factory()->linked()->for($intruder)->create();

        // A stale/corrupt FK must fail closed rather than expire someone else's connection.
        $this->account->update(['gocardless_requisition_id' => $theirRequisition->id]);
        $this->fakeConsentExpiredClient();

        $this->actingAs($this->user)
            ->postJson("/api/bank-data/gocardless/accounts/{$this->account->id}/refresh-balance")
            ->assertStatus(409);

        $this->assertSame(GoCardlessRequisitionStatus::LINKED, $theirRequisition->refresh()->status);
    }

    /**
     * Once a sync has been refused for a rate limit, the balance endpoint must not be a way
     * around it — both calls spend from the same per-institution quota.
     */
    public function test_balance_refresh_honours_the_sync_cooldown(): void
    {
        $this->account->update([
            'gocardless_sync_status' => Account::SYNC_STATUS_RATE_LIMITED,
            'gocardless_sync_retry_after' => now()->addMinutes(5),
        ]);

        $this->actingAs($this->user)
            ->postJson("/api/bank-data/gocardless/accounts/{$this->account->id}/refresh-balance")
            ->assertStatus(429)
            ->assertJsonPath('error', 'rate_limited');
    }

    /**
     * A manual account never talks to GoCardless, so a stale cooldown on the row must not
     * block recalculating its balance from local transactions.
     */
    public function test_manual_account_balance_refresh_ignores_the_cooldown(): void
    {
        $manualAccount = Account::factory()->create([
            'user_id' => $this->user->id,
            'is_gocardless_synced' => false,
            'gocardless_sync_retry_after' => now()->addMinutes(5),
        ]);

        $this->actingAs($this->user)
            ->postJson("/api/bank-data/gocardless/accounts/{$manualAccount->id}/refresh-balance")
            ->assertOk()
            ->assertJsonPath('data.source', 'transactions');
    }
}
