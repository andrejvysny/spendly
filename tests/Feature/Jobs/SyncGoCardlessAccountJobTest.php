<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Contracts\Repositories\AccountRepositoryInterface;
use App\Enums\GoCardlessRequisitionStatus;
use App\Exceptions\GoCardlessConsentExpiredException;
use App\Exceptions\GoCardlessRateLimitException;
use App\Jobs\RecurringDetectionJob;
use App\Jobs\SyncGoCardlessAccountJob;
use App\Models\Account;
use App\Models\GoCardlessRequisition;
use App\Models\RecurringDetectionSetting;
use App\Models\User;
use App\Services\GoCardless\BankDataClientInterface;
use App\Services\GoCardless\ClientFactory\GoCardlessClientFactoryInterface;
use App\Services\GoCardless\GoCardlessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * The sync no longer answers an HTTP request, so the account row is the only record of what
 * happened. These tests pin the three outcomes that differ — succeeded, rate limited, consent
 * gone — because each one has to leave a *different* trace and a different retry decision, and
 * getting them confused is silent: the user just sees a sync that never finishes.
 */
class SyncGoCardlessAccountJobTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->account = Account::factory()->create([
            'user_id' => $this->user->id,
            'gocardless_account_id' => 'mock_account_1',
            'is_gocardless_synced' => true,
            'gocardless_sync_status' => Account::SYNC_STATUS_QUEUED,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Swap the real service for one that answers the sync call in a chosen way.
     */
    /**
     * @param  array<string, mixed>|null  $stats  Stats the stubbed sync reports back.
     */
    private function fakeService(?\Throwable $throws = null, ?array $stats = null): void
    {
        $mock = Mockery::mock(GoCardlessService::class);

        if ($throws !== null) {
            // @phpstan-ignore-next-line — Mockery shouldReceive() union type; no phpstan-mockery extension configured
            $mock->shouldReceive('syncAccountTransactions')->andThrow($throws);
        } else {
            // @phpstan-ignore-next-line — Mockery shouldReceive() union type; no phpstan-mockery extension configured
            $mock->shouldReceive('syncAccountTransactions')->andReturn([
                'account_id' => $this->account->id,
                'stats' => $stats ?? ['created' => 1],
            ]);
        }

        $this->app->instance(GoCardlessService::class, $mock);
    }

    private function makeJob(bool $runRecurringDetection = true): SyncGoCardlessAccountJob
    {
        return new SyncGoCardlessAccountJob(
            (int) $this->account->id,
            (int) $this->user->id,
            true,
            false,
            $runRecurringDetection,
        );
    }

    /**
     * Run the job the way a worker would, with queue interactions faked so release()/fail()
     * are observable instead of blowing up on a missing job instance.
     */
    private function runJob(SyncGoCardlessAccountJob $job): SyncGoCardlessAccountJob
    {
        $job->withFakeQueueInteractions();

        $job->handle(
            $this->app->make(GoCardlessService::class),
            $this->app->make(AccountRepositoryInterface::class),
        );

        return $job;
    }

    // ── success ───────────────────────────────────────────────────────────

    public function test_successful_sync_records_success_and_finish_time(): void
    {
        Queue::fake();
        $this->fakeService();

        $this->runJob($this->makeJob());

        $this->account->refresh();
        $this->assertSame(Account::SYNC_STATUS_SUCCESS, $this->account->gocardless_sync_status);
        $this->assertNotNull($this->account->gocardless_sync_started_at);
        $this->assertNotNull($this->account->gocardless_sync_finished_at);
        $this->assertNull($this->account->gocardless_sync_error);
        $this->assertNull($this->account->gocardless_sync_retry_after);
    }

    public function test_successful_sync_stores_the_reported_counters(): void
    {
        Queue::fake();
        $this->fakeService(stats: ['total' => 5, 'created' => 4, 'updated' => 1, 'errors' => 0, 'dropped' => 0]);

        $this->runJob($this->makeJob());

        $this->account->refresh();
        $this->assertSame(4, $this->account->gocardless_sync_stats['created']);
        $this->assertSame(1, $this->account->gocardless_sync_stats['updated']);
    }

    /**
     * A run that fetched rows it could not store is not a success. Before the job kept the stats,
     * a sync where every transaction failed validation reported "Transactions synced."
     */
    public function test_sync_that_lost_rows_is_recorded_as_incomplete_not_success(): void
    {
        Queue::fake();
        $this->fakeService(stats: ['total' => 5, 'created' => 2, 'updated' => 0, 'errors' => 3, 'dropped' => 0]);

        $this->runJob($this->makeJob());

        $this->account->refresh();
        $this->assertSame(Account::SYNC_STATUS_INCOMPLETE, $this->account->gocardless_sync_status);
        $this->assertNotNull($this->account->gocardless_sync_finished_at);
        $this->assertStringContainsString('3 transaction(s)', (string) $this->account->gocardless_sync_error);
    }

    /**
     * Rows silently dropped by the unique index count the same way as validation failures.
     */
    public function test_sync_that_dropped_rows_is_recorded_as_incomplete(): void
    {
        Queue::fake();
        $this->fakeService(stats: ['total' => 3, 'created' => 2, 'updated' => 0, 'errors' => 0, 'dropped' => 1]);

        $this->runJob($this->makeJob());

        $this->assertSame(Account::SYNC_STATUS_INCOMPLETE, $this->account->refresh()->gocardless_sync_status);
    }

    public function test_successful_sync_dispatches_recurring_detection_when_enabled(): void
    {
        Queue::fake();
        $this->fakeService();

        $settings = RecurringDetectionSetting::forUser((int) $this->user->id);
        $settings->update(['run_after_import' => true]);

        $this->runJob($this->makeJob());

        Queue::assertPushed(RecurringDetectionJob::class);
    }

    public function test_successful_sync_skips_recurring_detection_when_setting_is_off(): void
    {
        Queue::fake();
        $this->fakeService();

        $settings = RecurringDetectionSetting::forUser((int) $this->user->id);
        $settings->update(['run_after_import' => false]);

        $this->runJob($this->makeJob());

        Queue::assertNotPushed(RecurringDetectionJob::class);
    }

    public function test_recurring_detection_can_be_switched_off_per_dispatch(): void
    {
        Queue::fake();
        $this->fakeService();

        $settings = RecurringDetectionSetting::forUser((int) $this->user->id);
        $settings->update(['run_after_import' => true]);

        $this->runJob($this->makeJob(runRecurringDetection: false));

        Queue::assertNotPushed(RecurringDetectionJob::class);
    }

    // ── rate limit ────────────────────────────────────────────────────────

    /**
     * A 429 is the bank scheduling us, not a failure of ours: the job goes back on the queue
     * with the bank's own delay and never spends an attempt.
     */
    public function test_rate_limit_releases_the_job_with_the_banks_delay(): void
    {
        Queue::fake();
        $this->fakeService(new GoCardlessRateLimitException(300));

        $job = $this->runJob($this->makeJob());

        $job->assertReleased(300);
        $job->assertNotFailed();

        $this->account->refresh();
        $this->assertSame(Account::SYNC_STATUS_RATE_LIMITED, $this->account->gocardless_sync_status);
        $this->assertNotNull($this->account->gocardless_sync_retry_after);
        $this->assertTrue($this->account->gocardless_sync_retry_after->isFuture());
    }

    /**
     * A bank that answers "retry after 0" would otherwise put the job into a hot loop.
     */
    public function test_rate_limit_delay_has_a_floor(): void
    {
        Queue::fake();
        $this->fakeService(new GoCardlessRateLimitException(5));

        $this->runJob($this->makeJob())->assertReleased(60);
    }

    /**
     * A daily-quota reset can be many hours out — longer than $uniqueFor and possibly longer than
     * retryUntil(). Parking the job that long would drop its unique lock (letting dispatch-sync
     * queue a duplicate) or see it failed on pickup without ever retrying. The account's cooldown
     * is the mechanism for waits that long, and dispatch-sync already honours it.
     */
    public function test_rate_limit_longer_than_the_release_cap_is_not_parked_on_the_queue(): void
    {
        Queue::fake();
        $this->fakeService(new GoCardlessRateLimitException(86400));

        $job = $this->runJob($this->makeJob());

        $job->assertNotReleased();
        $job->assertNotFailed();

        $this->account->refresh();
        $this->assertSame(Account::SYNC_STATUS_RATE_LIMITED, $this->account->gocardless_sync_status);
        // The full wait is still recorded, so no dispatch path will re-queue before it elapses.
        $this->assertTrue($this->account->gocardless_sync_retry_after->greaterThan(now()->addHours(23)));
    }

    /**
     * markSyncFailed() writes gocardless_sync_retry_after unconditionally, so a failed() hook that
     * did not skip a rate-limited account erased the very cooldown protecting the daily quota —
     * and the next dispatch-sync walked straight back into the same 429.
     */
    public function test_failed_hook_preserves_a_rate_limit_cooldown(): void
    {
        $cooldown = now()->addHours(6);
        $this->account->update([
            'gocardless_sync_status' => Account::SYNC_STATUS_RATE_LIMITED,
            'gocardless_sync_retry_after' => $cooldown,
        ]);

        $this->makeJob()->failed(new \RuntimeException('worker gave up'));

        $this->account->refresh();
        $this->assertSame(Account::SYNC_STATUS_RATE_LIMITED, $this->account->gocardless_sync_status);
        $this->assertNotNull($this->account->gocardless_sync_retry_after);
        $this->assertSame(
            $cooldown->format('Y-m-d H:i'),
            $this->account->gocardless_sync_retry_after->format('Y-m-d H:i')
        );
    }

    public function test_rate_limit_does_not_dispatch_recurring_detection(): void
    {
        Queue::fake();
        $this->fakeService(new GoCardlessRateLimitException(300));
        RecurringDetectionSetting::forUser((int) $this->user->id)->update(['run_after_import' => true]);

        $this->runJob($this->makeJob());

        Queue::assertNotPushed(RecurringDetectionJob::class);
    }

    // ── expired consent ───────────────────────────────────────────────────

    /**
     * Only the user re-authorizing can fix this, so the job must fail outright rather than
     * burn twelve hours of retries against a connection the bank has already closed.
     */
    public function test_expired_consent_fails_the_job_without_retrying(): void
    {
        Queue::fake();
        $this->fakeService(new GoCardlessConsentExpiredException('req_stale', 'mock_account_1'));

        $job = $this->runJob($this->makeJob());

        $job->assertFailedWith(GoCardlessConsentExpiredException::class);
        $job->assertNotReleased();

        $this->account->refresh();
        $this->assertSame(Account::SYNC_STATUS_NEEDS_RECONNECT, $this->account->gocardless_sync_status);
        $this->assertNull($this->account->gocardless_sync_retry_after);
    }

    /**
     * The same path end to end, with the real GoCardlessService in place: moving sync onto the
     * queue must not lose the side effects the inline controller used to produce — the account
     * flagged for reconnect and its requisition marked expired, which is what drives the UI's
     * reconnect prompt long after this job is gone.
     */
    public function test_expired_consent_still_flags_the_account_and_its_requisition(): void
    {
        Queue::fake();
        config(['services.gocardless.use_mock' => true]);

        $requisition = GoCardlessRequisition::factory()->linked()->for($this->user)->create([
            'requisition_id' => 'req_stale',
            'accounts' => ['mock_account_1'],
        ]);
        $this->account->update([
            'gocardless_requisition_id' => $requisition->id,
            'gocardless_needs_reconnect' => false,
        ]);

        $client = Mockery::mock(BankDataClientInterface::class);
        $client->shouldReceive('getTransactions')->andThrow(new GoCardlessConsentExpiredException('req_stale', 'mock_account_1')); // @phpstan-ignore-line — Mockery union type; no phpstan-mockery extension configured
        $client->shouldReceive('getBalances')->andThrow(new GoCardlessConsentExpiredException('req_stale', 'mock_account_1')); // @phpstan-ignore-line — Mockery union type; no phpstan-mockery extension configured

        $factory = Mockery::mock(GoCardlessClientFactoryInterface::class);
        $factory->shouldReceive('make')->andReturn($client); // @phpstan-ignore-line — Mockery union type; no phpstan-mockery extension configured

        $this->app->instance(GoCardlessClientFactoryInterface::class, $factory);
        $this->app->forgetInstance(GoCardlessService::class);

        $this->runJob($this->makeJob())->assertFailedWith(GoCardlessConsentExpiredException::class);

        $this->assertTrue((bool) $this->account->refresh()->gocardless_needs_reconnect);
        $this->assertSame(Account::SYNC_STATUS_NEEDS_RECONNECT, $this->account->gocardless_sync_status);
        $this->assertSame(GoCardlessRequisitionStatus::EXPIRED, $requisition->refresh()->status);
    }

    // ── generic failure ───────────────────────────────────────────────────

    /**
     * Rethrown so the worker's backoff and maxExceptions still govern, but the account is
     * stamped first so the UI has something to show while the retries play out.
     */
    public function test_unexpected_failure_records_status_and_rethrows(): void
    {
        Queue::fake();
        $this->fakeService(new \RuntimeException('upstream exploded'));

        $this->expectException(\RuntimeException::class);

        try {
            $this->runJob($this->makeJob());
        } finally {
            $this->account->refresh();
            $this->assertSame(Account::SYNC_STATUS_FAILED, $this->account->gocardless_sync_status);
            $this->assertSame('upstream exploded', $this->account->gocardless_sync_error);
        }
    }

    /**
     * The stored message is rendered in the UI, so a leaked token or an unbounded API body
     * would land in front of the user. Redacted and clipped before it is ever written.
     */
    public function test_stored_error_is_redacted_and_truncated(): void
    {
        Queue::fake();
        $this->fakeService(new \RuntimeException('{"access":"MARKER_SECRET_LEAK"} '.str_repeat('x', 500)));

        try {
            $this->runJob($this->makeJob());
        } catch (\RuntimeException) {
            // expected — assertions are about what was written, not what was thrown
        }

        $error = (string) $this->account->refresh()->gocardless_sync_error;
        $this->assertStringNotContainsString('MARKER_SECRET_LEAK', $error);
        // Clipped to 200 plus Str::limit's ellipsis, comfortably inside the varchar(255) column.
        $this->assertLessThan(255, mb_strlen($error));
        $this->assertStringEndsWith('...', $error);
    }

    // ── missing rows ──────────────────────────────────────────────────────

    public function test_job_is_a_no_op_when_the_account_vanished(): void
    {
        Queue::fake();
        $mock = Mockery::mock(GoCardlessService::class);
        $mock->shouldNotReceive('syncAccountTransactions');
        $this->app->instance(GoCardlessService::class, $mock);

        $job = new SyncGoCardlessAccountJob(999999, (int) $this->user->id);
        $this->runJob($job);

        $job->assertNotFailed();
        $job->assertNotReleased();
    }

    /**
     * A job dispatched for account A must not sync it on behalf of another user's request:
     * the lookup is ownership-scoped, so a mismatched pair finds nothing.
     */
    public function test_job_will_not_sync_an_account_owned_by_another_user(): void
    {
        Queue::fake();
        $mock = Mockery::mock(GoCardlessService::class);
        $mock->shouldNotReceive('syncAccountTransactions');
        $this->app->instance(GoCardlessService::class, $mock);

        $intruder = User::factory()->create();

        $this->runJob(new SyncGoCardlessAccountJob((int) $this->account->id, (int) $intruder->id));

        $this->assertSame(Account::SYNC_STATUS_QUEUED, $this->account->refresh()->gocardless_sync_status);
    }

    // ── failed() hook ─────────────────────────────────────────────────────

    /**
     * Covers the failures that never return from handle() at all — a worker timeout, a
     * serialization error, or the final attempt giving up.
     */
    public function test_failed_hook_stamps_an_account_still_marked_syncing(): void
    {
        $this->account->update(['gocardless_sync_status' => Account::SYNC_STATUS_SYNCING]);

        $this->makeJob()->failed(new \RuntimeException('worker timed out'));

        $this->account->refresh();
        $this->assertSame(Account::SYNC_STATUS_FAILED, $this->account->gocardless_sync_status);
        $this->assertSame('worker timed out', $this->account->gocardless_sync_error);
    }

    /**
     * needs_reconnect was written by the code that knew *why*; the generic hook must not
     * flatten it back to "failed" and cost the UI its reconnect prompt.
     */
    public function test_failed_hook_leaves_a_terminal_status_alone(): void
    {
        $this->account->update([
            'gocardless_sync_status' => Account::SYNC_STATUS_NEEDS_RECONNECT,
            'gocardless_sync_error' => 'Bank access ended. Reconnect the bank to resume syncing.',
        ]);

        $this->makeJob()->failed(new \RuntimeException('later, vaguer failure'));

        $this->account->refresh();
        $this->assertSame(Account::SYNC_STATUS_NEEDS_RECONNECT, $this->account->gocardless_sync_status);
        $this->assertSame('Bank access ended. Reconnect the bank to resume syncing.', $this->account->gocardless_sync_error);
    }

    // ── retry configuration ───────────────────────────────────────────────

    /**
     * Uniqueness is enforced by the framework against a cache lock, which Queue::fake()
     * bypasses entirely — so what is worth pinning here is the contract the lock is built
     * from: one key per account, held long enough to outlive the longest backoff below.
     */
    public function test_uniqueness_is_keyed_per_account_and_outlives_the_longest_backoff(): void
    {
        $job = $this->makeJob();

        $this->assertSame('gocardless-sync:'.$this->account->id, $job->uniqueId());
        $this->assertGreaterThan(max($job->backoff()), $job->uniqueFor);

        $other = new SyncGoCardlessAccountJob((int) $this->account->id + 1, (int) $this->user->id);
        $this->assertNotSame($job->uniqueId(), $other->uniqueId());
    }

    /**
     * Flags must not leak into the key: "sync this account" is one piece of work regardless
     * of which entry point asked or with which options.
     */
    public function test_uniqueness_ignores_sync_flags(): void
    {
        $a = new SyncGoCardlessAccountJob((int) $this->account->id, (int) $this->user->id, true, false);
        $b = new SyncGoCardlessAccountJob((int) $this->account->id, (int) $this->user->id, false, true);

        $this->assertSame($a->uniqueId(), $b->uniqueId());
    }

    /**
     * The job's own guard has to fire before the worker's --timeout=300 kills it, otherwise
     * failed() never runs and the account is stranded in 'syncing'.
     */
    public function test_job_timeout_leaves_room_under_the_worker_timeout(): void
    {
        $this->assertLessThan(300, $this->makeJob()->timeout);
    }

    public function test_job_targets_the_gocardless_queue(): void
    {
        $this->assertSame('gocardless', $this->makeJob()->queue);
    }
}
