<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\GoCardlessRequisitionStatus;
use App\Models\Account;
use App\Models\GoCardlessRequisition;
use App\Models\User;
use App\Services\GoCardless\GoCardlessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Installs that linked banks before requisitions were stored locally have accounts syncing
 * against connections the app knows nothing about — so no expiry warning, no reconnect.
 * This command adopts them.
 */
class GocardlessBackfillRequisitionsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gocardless.use_mock' => true]);
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
        $result = $this->artisan('gocardless:backfill-requisitions', $parameters);
        assert($result instanceof \Illuminate\Testing\PendingCommand);

        return $result;
    }

    /**
     * @param  array<int, string>  $accounts
     * @return array<string, mixed>
     */
    private function remoteRequisition(string $id, array $accounts = ['mock_account_1'], string $status = 'LN'): array
    {
        return [
            'id' => $id,
            'created' => now()->toIso8601String(),
            'status' => $status,
            'institution_id' => 'MOCK_INSTITUTION',
            'agreement' => 'mock_agreement_id',
            'reference' => 'ref_'.$id,
            'accounts' => $accounts,
            'link' => 'https://ob.gocardless.com/psd2/start/'.$id,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @return array{count: int, next: string|null, previous: string|null, results: array<int, array<string, mixed>>}
     */
    private function remoteList(array $results): array
    {
        return [
            'count' => count($results),
            'next' => null,
            'previous' => null,
            'results' => $results,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     */
    private function fakeRemoteList(array $results): void
    {
        $mock = Mockery::mock(GoCardlessService::class);
        // @phpstan-ignore-next-line — Mockery shouldReceive() union type; no phpstan-mockery extension configured
        $mock->shouldReceive('getRequisitionsList')->andReturn($this->remoteList($results));

        $this->app->instance(GoCardlessService::class, $mock);
    }

    public function test_remote_requisitions_are_imported_and_accounts_adopted(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'gocardless_account_id' => 'mock_account_1',
            'is_gocardless_synced' => true,
            'gocardless_requisition_id' => null,
        ]);

        $this->fakeRemoteList([$this->remoteRequisition('req_remote_1')]);

        $this->runCommand(['--user' => (string) $user->id])->assertExitCode(0);

        $row = GoCardlessRequisition::where('requisition_id', 'req_remote_1')->firstOrFail();
        $this->assertSame($user->id, $row->getAttribute('user_id'));
        $this->assertSame(GoCardlessRequisitionStatus::LINKED, $row->status);
        $this->assertSame('MOCK_INSTITUTION', $row->getAttribute('institution_id'));

        $this->assertSame($row->id, $account->refresh()->gocardless_requisition_id);
    }

    public function test_an_account_already_linked_by_the_callback_is_not_re_pointed(): void
    {
        $user = User::factory()->create();
        $existing = GoCardlessRequisition::factory()->linked()->for($user)->create([
            'requisition_id' => 'req_from_callback',
        ]);
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'gocardless_account_id' => 'mock_account_1',
            'gocardless_requisition_id' => $existing->id,
        ]);

        $this->fakeRemoteList([$this->remoteRequisition('req_remote_1')]);

        $this->runCommand(['--user' => (string) $user->id])->assertExitCode(0);

        // The callback knows more than a bulk listing does; its FK wins.
        $this->assertSame($existing->id, $account->refresh()->gocardless_requisition_id);
    }

    public function test_rerunning_updates_the_existing_row_instead_of_duplicating_it(): void
    {
        $user = User::factory()->create();

        // Both payloads are queued up front: Artisan caches the resolved command, so the
        // service it was built with cannot be swapped between the two runs.
        $mock = Mockery::mock(GoCardlessService::class);
        // @phpstan-ignore-next-line — Mockery shouldReceive() union type; no phpstan-mockery extension configured
        $mock->shouldReceive('getRequisitionsList')->andReturnValues([
            $this->remoteList([$this->remoteRequisition('req_remote_1', ['mock_account_1'], 'CR')]),
            $this->remoteList([$this->remoteRequisition('req_remote_1', ['mock_account_1'], 'LN')]),
        ]);
        $this->app->instance(GoCardlessService::class, $mock);

        $this->runCommand(['--user' => (string) $user->id])->assertExitCode(0);
        $this->runCommand(['--user' => (string) $user->id])->assertExitCode(0);

        $this->assertSame(1, GoCardlessRequisition::where('requisition_id', 'req_remote_1')->count());
        $row = GoCardlessRequisition::where('requisition_id', 'req_remote_1')->firstOrFail();
        $this->assertSame(GoCardlessRequisitionStatus::LINKED, $row->status);
    }

    public function test_all_flag_covers_users_with_linked_accounts_and_isolates_failures(): void
    {
        $broken = User::factory()->create();
        Account::factory()->create([
            'user_id' => $broken->id,
            'is_gocardless_synced' => true,
            'gocardless_account_id' => 'mock_account_1',
        ]);

        $healthy = User::factory()->create();
        Account::factory()->create([
            'user_id' => $healthy->id,
            'is_gocardless_synced' => true,
            'gocardless_account_id' => 'mock_account_2',
        ]);

        // A user with no GoCardless footprint at all must never be asked about.
        User::factory()->create();

        $mock = Mockery::mock(GoCardlessService::class);
        // @phpstan-ignore-next-line — Mockery shouldReceive() union type; no phpstan-mockery extension configured
        $mock->shouldReceive('getRequisitionsList')
            ->twice()
            ->andReturnUsing(function (User $user) use ($broken): array {
                if ($user->id === $broken->id) {
                    throw new \RuntimeException('credentials rejected');
                }

                return [
                    'count' => 1,
                    'next' => null,
                    'previous' => null,
                    'results' => [$this->remoteRequisition('req_healthy', ['mock_account_2'])],
                ];
            });
        $this->app->instance(GoCardlessService::class, $mock);

        $this->runCommand(['--all' => true])->assertExitCode(0);

        $this->assertSame(1, GoCardlessRequisition::count());
        $this->assertSame($healthy->id, GoCardlessRequisition::firstOrFail()->getAttribute('user_id'));
    }

    public function test_all_flag_includes_a_user_with_no_own_credentials_or_accounts_when_instance_credentials_are_configured(): void
    {
        // Raw `whereNotNull('gocardless_secret_id')` would miss this user entirely: no
        // personal pair, no linked accounts yet — only reachable through the instance fallback.
        config([
            'services.gocardless.secret_id' => 'instance-secret-id',
            'services.gocardless.secret_key' => 'instance-secret-key',
        ]);

        $user = User::factory()->create();

        $this->fakeRemoteList([$this->remoteRequisition('req_remote_1')]);

        $this->runCommand(['--all' => true])->assertExitCode(0);

        $row = GoCardlessRequisition::where('requisition_id', 'req_remote_1')->firstOrFail();
        $this->assertSame($user->id, $row->getAttribute('user_id'));
    }

    public function test_dry_run_reports_without_writing_anything(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'gocardless_account_id' => 'mock_account_1',
            'gocardless_requisition_id' => null,
        ]);

        $this->fakeRemoteList([$this->remoteRequisition('req_remote_1')]);

        $this->runCommand(['--user' => (string) $user->id, '--dry-run' => true])->assertExitCode(0);

        $this->assertSame(0, GoCardlessRequisition::count());
        $this->assertNull($account->refresh()->gocardless_requisition_id);
    }

    public function test_unknown_user_fails_the_command(): void
    {
        $this->runCommand(['--user' => 'nobody@example.com'])->assertExitCode(1);
    }
}
