<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Jobs\SyncGoCardlessAccountJob;
use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * This command is the scheduled entry point for every bank sync on the installation, so its
 * two jobs are equally important: dispatch what is due, and — more easily got wrong — refuse to
 * dispatch what is not. Every skip below exists because dispatching anyway costs the user real
 * GoCardless request quota, which is capped per account per day.
 */
class GocardlessDispatchSyncCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.gocardless.min_sync_interval_hours' => 8,
            'services.gocardless.dispatch_stagger_seconds' => 20,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function syncedAccount(User $user, array $attributes = []): Account
    {
        return Account::factory()->create(array_merge([
            'user_id' => $user->id,
            'is_gocardless_synced' => true,
            'gocardless_account_id' => 'gc_'.fake()->unique()->numerify('######'),
            'gocardless_last_synced_at' => now()->subDays(2),
            'gocardless_sync_status' => Account::SYNC_STATUS_IDLE,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function runCommand(array $parameters = []): \Illuminate\Testing\PendingCommand
    {
        $result = $this->artisan('gocardless:dispatch-sync', $parameters);
        assert($result instanceof \Illuminate\Testing\PendingCommand);

        return $result;
    }

    // ── dispatching ───────────────────────────────────────────────────────

    /**
     * The whole point of replacing `gocardless:sync-all --all`: the run covers every user, and
     * no one user's accounts can starve or break the others.
     */
    public function test_dispatches_a_job_for_every_users_synced_accounts(): void
    {
        Queue::fake();

        foreach (range(1, 3) as $ignored) {
            $this->syncedAccount(User::factory()->create());
        }

        $this->runCommand()->assertSuccessful();

        Queue::assertPushed(SyncGoCardlessAccountJob::class, 3);
    }

    public function test_dispatched_jobs_target_the_gocardless_queue(): void
    {
        Queue::fake();
        $this->syncedAccount(User::factory()->create());

        $this->runCommand()->assertSuccessful();

        Queue::assertPushed(SyncGoCardlessAccountJob::class, fn (SyncGoCardlessAccountJob $job): bool => $job->queue === 'gocardless');
    }

    public function test_dispatch_marks_the_account_queued(): void
    {
        Queue::fake();
        $account = $this->syncedAccount(User::factory()->create());

        $this->runCommand()->assertSuccessful();

        $account->refresh();
        $this->assertSame(Account::SYNC_STATUS_QUEUED, $account->gocardless_sync_status);
        $this->assertNotNull($account->gocardless_sync_queued_at);
    }

    public function test_manual_accounts_are_never_dispatched(): void
    {
        Queue::fake();
        Account::factory()->create([
            'user_id' => User::factory()->create()->id,
            'is_gocardless_synced' => false,
        ]);

        $this->runCommand()->assertSuccessful();

        Queue::assertNothingPushed();
    }

    // ── stagger ───────────────────────────────────────────────────────────

    /**
     * A hundred accounts arriving at the bank in the same second is how an installation
     * rate-limits itself. Each dispatch is pushed one stagger step further out than the last.
     */
    public function test_dispatches_are_staggered(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        foreach (range(1, 3) as $ignored) {
            $this->syncedAccount($user);
        }

        $this->runCommand()->assertSuccessful();

        $delays = [];
        Queue::assertPushed(SyncGoCardlessAccountJob::class, function (SyncGoCardlessAccountJob $job) use (&$delays): bool {
            $delays[] = $job->delay;

            return true;
        });

        $seconds = array_map(
            static fn (mixed $delay): int => $delay instanceof \DateTimeInterface ? max(0, $delay->getTimestamp() - now()->getTimestamp()) : 0,
            $delays,
        );
        sort($seconds);

        // One stagger step (20s) further out per dispatch; the first goes immediately.
        $this->assertSame([0, 20, 40], $seconds);
    }

    // ── skips ─────────────────────────────────────────────────────────────

    /**
     * A dead connection cannot be revived by asking again; only the user re-authorizing helps.
     */
    public function test_skips_accounts_needing_reconnect(): void
    {
        Queue::fake();
        $this->syncedAccount(User::factory()->create(), ['gocardless_needs_reconnect' => true]);

        $this->runCommand()->assertSuccessful();

        Queue::assertNothingPushed();
    }

    /**
     * The cooldown a rate-limited job recorded has to survive the next scheduled run, or the
     * schedule simply walks the account back into the same limit.
     */
    public function test_skips_accounts_still_in_rate_limit_cooldown(): void
    {
        Queue::fake();
        $this->syncedAccount(User::factory()->create(), [
            'gocardless_sync_status' => Account::SYNC_STATUS_RATE_LIMITED,
            'gocardless_sync_retry_after' => now()->addMinutes(30),
        ]);

        $this->runCommand()->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_dispatches_once_the_cooldown_has_passed(): void
    {
        Queue::fake();
        $this->syncedAccount(User::factory()->create(), [
            'gocardless_sync_status' => Account::SYNC_STATUS_RATE_LIMITED,
            'gocardless_sync_retry_after' => now()->subMinute(),
        ]);

        $this->runCommand()->assertSuccessful();

        Queue::assertPushed(SyncGoCardlessAccountJob::class, 1);
    }

    /**
     * On a four-hourly schedule most accounts are not due; re-syncing them anyway spends
     * quota to learn nothing.
     */
    public function test_skips_accounts_synced_within_the_minimum_interval(): void
    {
        Queue::fake();
        $this->syncedAccount(User::factory()->create(), ['gocardless_last_synced_at' => now()->subHours(2)]);

        $this->runCommand()->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_min_interval_can_be_overridden_per_run(): void
    {
        Queue::fake();
        $this->syncedAccount(User::factory()->create(), ['gocardless_last_synced_at' => now()->subHours(2)]);

        $this->runCommand(['--min-interval-hours' => 1])->assertSuccessful();

        Queue::assertPushed(SyncGoCardlessAccountJob::class, 1);
    }

    public function test_never_synced_accounts_are_always_due(): void
    {
        Queue::fake();
        $this->syncedAccount(User::factory()->create(), ['gocardless_last_synced_at' => null]);

        $this->runCommand()->assertSuccessful();

        Queue::assertPushed(SyncGoCardlessAccountJob::class, 1);
    }

    /**
     * The unique lock would collapse a duplicate anyway, but skipping keeps the stagger honest:
     * a job that never gets pushed must not consume a delay slot.
     */
    public function test_skips_accounts_with_a_job_already_booked(): void
    {
        Queue::fake();
        $this->syncedAccount(User::factory()->create(), ['gocardless_sync_status' => Account::SYNC_STATUS_QUEUED]);
        $this->syncedAccount(User::factory()->create(), ['gocardless_sync_status' => Account::SYNC_STATUS_SYNCING]);

        $this->runCommand()->assertSuccessful();

        Queue::assertNothingPushed();
    }

    /**
     * The unattended half of the stale-sync recovery: an account whose worker died stays `syncing`
     * forever, and this command skips in-progress accounts — so without reaping it here, the
     * scheduled sync would never touch that account again.
     */
    public function test_reaps_and_requeues_an_account_wedged_in_syncing(): void
    {
        Queue::fake();
        $account = $this->syncedAccount(User::factory()->create(), [
            'gocardless_sync_status' => Account::SYNC_STATUS_SYNCING,
            'gocardless_sync_started_at' => now()->subSeconds(Account::SYNC_STALE_SYNCING_SECONDS + 60),
        ]);

        $this->runCommand()->assertSuccessful();

        Queue::assertPushed(SyncGoCardlessAccountJob::class);
        $this->assertSame(Account::SYNC_STATUS_QUEUED, $account->refresh()->gocardless_sync_status);
    }

    /**
     * A run that is merely slow must not be reaped out from under itself.
     */
    public function test_does_not_reap_a_sync_that_only_just_started(): void
    {
        Queue::fake();
        $this->syncedAccount(User::factory()->create(), [
            'gocardless_sync_status' => Account::SYNC_STATUS_SYNCING,
            'gocardless_sync_started_at' => now()->subSeconds(60),
        ]);

        $this->runCommand()->assertSuccessful();

        Queue::assertNothingPushed();
    }

    // ── scoping and dry run ───────────────────────────────────────────────

    public function test_user_option_restricts_the_run_to_that_owner(): void
    {
        Queue::fake();

        $target = User::factory()->create();
        $targetAccount = $this->syncedAccount($target);
        $this->syncedAccount(User::factory()->create());

        $this->runCommand(['--user' => (string) $target->id])->assertSuccessful();

        Queue::assertPushed(
            SyncGoCardlessAccountJob::class,
            fn (SyncGoCardlessAccountJob $job): bool => $job->accountId === (int) $targetAccount->id,
        );
        Queue::assertPushed(SyncGoCardlessAccountJob::class, 1);
    }

    public function test_account_option_restricts_the_run_to_one_account(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $wanted = $this->syncedAccount($user);
        $this->syncedAccount($user);

        $this->runCommand(['--account' => (string) $wanted->id])->assertSuccessful();

        Queue::assertPushed(SyncGoCardlessAccountJob::class, 1);
        Queue::assertPushed(
            SyncGoCardlessAccountJob::class,
            fn (SyncGoCardlessAccountJob $job): bool => $job->accountId === (int) $wanted->id,
        );
    }

    public function test_account_option_will_not_reach_across_owners(): void
    {
        Queue::fake();

        $theirAccount = $this->syncedAccount(User::factory()->create());
        $me = User::factory()->create();

        $this->runCommand(['--account' => (string) $theirAccount->id, '--user' => (string) $me->id])
            ->assertFailed();

        Queue::assertNothingPushed();
    }

    public function test_dry_run_reports_without_dispatching_or_writing(): void
    {
        Queue::fake();
        $account = $this->syncedAccount(User::factory()->create());

        $this->runCommand(['--dry-run' => true])->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertSame(Account::SYNC_STATUS_IDLE, $account->refresh()->gocardless_sync_status);
    }

    public function test_summary_counts_dispatches_and_skips(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $this->syncedAccount($user);
        $this->syncedAccount($user, ['gocardless_needs_reconnect' => true]);
        $this->syncedAccount($user, ['gocardless_last_synced_at' => now()->subHour()]);

        $this->runCommand()
            ->expectsOutputToContain('dispatched 1, skipped 2')
            ->assertSuccessful();
    }
}
