<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\GoCardlessRequisitionStatus;
use App\Exceptions\GoCardlessConsentExpiredException;
use App\Models\Account;
use App\Models\GoCardlessRequisition;
use App\Models\User;
use App\Services\GoCardless\GoCardlessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * A GoCardless connection dies 90 days after authorization and nothing announces it.
 * This command is the only thing that notices, so its three passes — expire, warn, poll —
 * each need to hold on their own, and none of them may take the run down with a single
 * unreachable bank.
 */
class GocardlessCheckConsentCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.gocardless.use_mock' => true,
            'services.gocardless.consent_warning_days' => 7,
            'services.gocardless.consent_check_stale_hours' => 24,
        ]);

        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function runCommand(array $parameters = []): \Illuminate\Testing\PendingCommand
    {
        $result = $this->artisan('gocardless:check-consent', $parameters);
        assert($result instanceof \Illuminate\Testing\PendingCommand);

        return $result;
    }

    /**
     * Stub the remote poll so tests that are not about polling stay deterministic.
     *
     * @param  array<string, mixed>  $remote
     */
    private function fakeRemoteStatus(array $remote = ['status' => 'LN']): void
    {
        $mock = Mockery::mock(GoCardlessService::class);
        // @phpstan-ignore-next-line — Mockery shouldReceive() union type; no phpstan-mockery extension configured
        $mock->shouldReceive('getRequisition')->andReturn($remote);

        $this->app->instance(GoCardlessService::class, $mock);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function requisition(array $attributes, ?User $owner = null): GoCardlessRequisition
    {
        return GoCardlessRequisition::factory()
            ->for($owner ?? $this->user)
            ->create($attributes);
    }

    // ── expire ────────────────────────────────────────────────────────────

    public function test_lapsed_connection_is_expired_and_all_its_accounts_flagged(): void
    {
        $this->fakeRemoteStatus();

        $row = $this->requisition([
            'status' => GoCardlessRequisitionStatus::LINKED,
            'gocardless_status' => 'LN',
            'access_valid_until' => now()->subDay(),
            'accounts' => ['mock_account_1', 'mock_account_2'],
        ]);

        // Linked by FK (the callback relinked it) …
        $linked = Account::factory()->create([
            'user_id' => $this->user->id,
            'gocardless_account_id' => 'mock_account_1',
            'gocardless_requisition_id' => $row->id,
            'gocardless_needs_reconnect' => false,
        ]);

        // … and one that only appears in the row's raw accounts payload, as pre-FK
        // connections do. Both must be flagged.
        $unlinked = Account::factory()->create([
            'user_id' => $this->user->id,
            'gocardless_account_id' => 'mock_account_2',
            'gocardless_requisition_id' => null,
            'gocardless_needs_reconnect' => false,
        ]);

        $this->runCommand()->assertExitCode(0);

        $row->refresh();
        $this->assertSame(GoCardlessRequisitionStatus::EXPIRED, $row->status);
        // Our own inference must not masquerade as something the API told us.
        $this->assertSame('LN', $row->getAttribute('gocardless_status'));
        $this->assertNotNull($row->last_checked_at);

        $this->assertTrue((bool) $linked->refresh()->gocardless_needs_reconnect);
        $this->assertTrue((bool) $unlinked->refresh()->gocardless_needs_reconnect);
    }

    public function test_another_users_account_sharing_a_gocardless_id_is_not_flagged(): void
    {
        $this->fakeRemoteStatus();

        $this->requisition([
            'status' => GoCardlessRequisitionStatus::LINKED,
            'access_valid_until' => now()->subDay(),
            'accounts' => ['mock_account_1'],
        ]);

        $intruder = User::factory()->create();
        $intruderAccount = Account::factory()->create([
            'user_id' => $intruder->id,
            'gocardless_account_id' => 'mock_account_1',
            'gocardless_needs_reconnect' => false,
        ]);

        $this->runCommand()->assertExitCode(0);

        $this->assertFalse((bool) $intruderAccount->refresh()->gocardless_needs_reconnect);
    }

    public function test_terminal_statuses_are_never_resurrected_or_expired(): void
    {
        $this->fakeRemoteStatus();

        $revoked = $this->requisition([
            'status' => GoCardlessRequisitionStatus::REVOKED,
            'access_valid_until' => now()->subDay(),
        ]);
        $replaced = $this->requisition([
            'status' => GoCardlessRequisitionStatus::REPLACED,
            'access_valid_until' => now()->addDay(),
        ]);

        $this->runCommand()->assertExitCode(0);

        $this->assertSame(GoCardlessRequisitionStatus::REVOKED, $revoked->refresh()->status);
        $this->assertSame(GoCardlessRequisitionStatus::REPLACED, $replaced->refresh()->status);
        $this->assertNull($replaced->expiry_warning_sent_at);
    }

    // ── warn ──────────────────────────────────────────────────────────────

    public function test_expiry_warning_is_stamped_exactly_once(): void
    {
        $this->fakeRemoteStatus();

        $row = $this->requisition([
            'status' => GoCardlessRequisitionStatus::LINKED,
            'gocardless_status' => 'LN',
            'access_valid_until' => now()->addDays(3),
        ]);

        $this->runCommand()->assertExitCode(0);

        $row->refresh();
        $firstStamp = $row->expiry_warning_sent_at;
        $this->assertNotNull($firstStamp);
        $this->assertSame(GoCardlessRequisitionStatus::LINKED, $row->status);

        $this->travel(1)->hours();
        $this->runCommand()->assertExitCode(0);

        $secondStamp = $row->refresh()->expiry_warning_sent_at;
        $this->assertNotNull($secondStamp);
        // A daily run must not re-announce the same connection every day.
        $this->assertTrue($firstStamp->equalTo($secondStamp));
    }

    public function test_connection_outside_the_warning_window_is_left_alone(): void
    {
        $this->fakeRemoteStatus();

        $row = $this->requisition([
            'status' => GoCardlessRequisitionStatus::LINKED,
            'access_valid_until' => now()->addDays(30),
        ]);

        $this->runCommand()->assertExitCode(0);

        $this->assertNull($row->refresh()->expiry_warning_sent_at);
    }

    // ── poll ──────────────────────────────────────────────────────────────

    public function test_poll_promotes_a_pending_requisition_that_never_got_a_callback(): void
    {
        $this->fakeRemoteStatus(['status' => 'LN']);

        $row = $this->requisition([
            'status' => GoCardlessRequisitionStatus::PENDING,
            'gocardless_status' => 'CR',
            'access_valid_until' => null,
            'last_checked_at' => null,
        ]);

        $this->runCommand()->assertExitCode(0);

        $row->refresh();
        $this->assertSame(GoCardlessRequisitionStatus::LINKED, $row->status);
        $this->assertSame('LN', $row->getAttribute('gocardless_status'));
        $this->assertNotNull($row->last_checked_at);
    }

    public function test_recently_checked_requisitions_are_not_polled_again(): void
    {
        $mock = Mockery::mock(GoCardlessService::class);
        // @phpstan-ignore-next-line — Mockery shouldReceive() union type; no phpstan-mockery extension configured
        $mock->shouldReceive('getRequisition')->never();
        $this->app->instance(GoCardlessService::class, $mock);

        $this->requisition([
            'status' => GoCardlessRequisitionStatus::LINKED,
            'access_valid_until' => now()->addDays(60),
            'last_checked_at' => now()->subHour(),
        ]);

        $this->runCommand()->assertExitCode(0);
    }

    public function test_remote_revocation_expires_the_row_and_flags_the_account(): void
    {
        $this->fakeRemoteStatus(['status' => 'EX']);

        $row = $this->requisition([
            'status' => GoCardlessRequisitionStatus::LINKED,
            'gocardless_status' => 'LN',
            'access_valid_until' => now()->addDays(60),
            'accounts' => ['mock_account_1'],
        ]);
        $account = Account::factory()->create([
            'user_id' => $this->user->id,
            'gocardless_account_id' => 'mock_account_1',
            'gocardless_needs_reconnect' => false,
        ]);

        $this->runCommand()->assertExitCode(0);

        $row->refresh();
        $this->assertSame(GoCardlessRequisitionStatus::EXPIRED, $row->status);
        $this->assertSame('EX', $row->getAttribute('gocardless_status'));
        $this->assertTrue((bool) $account->refresh()->gocardless_needs_reconnect);
    }

    public function test_consent_exception_during_poll_expires_the_row(): void
    {
        $mock = Mockery::mock(GoCardlessService::class);
        // @phpstan-ignore-next-line — Mockery shouldReceive() union type; no phpstan-mockery extension configured
        $mock->shouldReceive('getRequisition')->andThrow(new GoCardlessConsentExpiredException('req_1', 'mock_account_1'));
        $this->app->instance(GoCardlessService::class, $mock);

        $row = $this->requisition([
            'status' => GoCardlessRequisitionStatus::LINKED,
            'access_valid_until' => now()->addDays(60),
            'accounts' => ['mock_account_1'],
        ]);
        $account = Account::factory()->create([
            'user_id' => $this->user->id,
            'gocardless_account_id' => 'mock_account_1',
            'gocardless_needs_reconnect' => false,
        ]);

        $this->runCommand()->assertExitCode(0);

        $this->assertSame(GoCardlessRequisitionStatus::EXPIRED, $row->refresh()->status);
        $this->assertTrue((bool) $account->refresh()->gocardless_needs_reconnect);
    }

    public function test_one_failing_poll_does_not_abort_the_run(): void
    {
        $failing = $this->requisition([
            'status' => GoCardlessRequisitionStatus::PENDING,
            'requisition_id' => 'req_broken',
            'access_valid_until' => null,
        ]);
        $healthy = $this->requisition([
            'status' => GoCardlessRequisitionStatus::PENDING,
            'requisition_id' => 'req_ok',
            'access_valid_until' => null,
        ]);

        $mock = Mockery::mock(GoCardlessService::class);
        // @phpstan-ignore-next-line — Mockery shouldReceive() union type; no phpstan-mockery extension configured
        $mock->shouldReceive('getRequisition')
            ->andReturnUsing(function (string $requisitionId): array {
                if ($requisitionId === 'req_broken') {
                    throw new \RuntimeException('bank unreachable');
                }

                return ['status' => 'LN'];
            });
        $this->app->instance(GoCardlessService::class, $mock);

        $this->runCommand()->assertExitCode(0);

        $this->assertSame(GoCardlessRequisitionStatus::PENDING, $failing->refresh()->status);
        $this->assertSame(GoCardlessRequisitionStatus::LINKED, $healthy->refresh()->status);
    }

    // ── scoping and dry run ───────────────────────────────────────────────

    public function test_user_option_restricts_the_run_to_that_owner(): void
    {
        $this->fakeRemoteStatus();

        $other = User::factory()->create();
        $mine = $this->requisition([
            'status' => GoCardlessRequisitionStatus::LINKED,
            'access_valid_until' => now()->subDay(),
        ]);
        $theirs = $this->requisition([
            'status' => GoCardlessRequisitionStatus::LINKED,
            'access_valid_until' => now()->subDay(),
        ], $other);

        $this->runCommand(['--user' => (string) $this->user->id])->assertExitCode(0);

        $this->assertSame(GoCardlessRequisitionStatus::EXPIRED, $mine->refresh()->status);
        $this->assertSame(GoCardlessRequisitionStatus::LINKED, $theirs->refresh()->status);
    }

    public function test_dry_run_reports_without_writing_anything(): void
    {
        $this->fakeRemoteStatus();

        $lapsed = $this->requisition([
            'status' => GoCardlessRequisitionStatus::LINKED,
            'access_valid_until' => now()->subDay(),
            'accounts' => ['mock_account_1'],
        ]);
        $warning = $this->requisition([
            'status' => GoCardlessRequisitionStatus::LINKED,
            'access_valid_until' => now()->addDays(2),
        ]);
        $account = Account::factory()->create([
            'user_id' => $this->user->id,
            'gocardless_account_id' => 'mock_account_1',
            'gocardless_needs_reconnect' => false,
        ]);

        $this->runCommand(['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(GoCardlessRequisitionStatus::LINKED, $lapsed->refresh()->status);
        $this->assertNull($warning->refresh()->expiry_warning_sent_at);
        $this->assertNull($warning->last_checked_at);
        $this->assertFalse((bool) $account->refresh()->gocardless_needs_reconnect);
    }

    public function test_unknown_user_fails_the_command(): void
    {
        $this->runCommand(['--user' => 'nobody@example.com'])->assertExitCode(1);
    }
}
