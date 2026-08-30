<?php

declare(strict_types=1);

namespace Tests\Feature\GoCardless;

use App\Models\Account;
use App\Models\User;
use App\Services\GoCardless\GoCardlessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Regression coverage for gocardless:sync-all --all: the scheduled run
 * (bootstrap/app.php) must iterate every user with GoCardless-linked
 * accounts, not just the first user in the database.
 */
class GocardlessSyncAllCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function runSyncAllCommand(array $parameters = []): \Illuminate\Testing\PendingCommand
    {
        $result = $this->artisan('gocardless:sync-all', $parameters);
        assert($result instanceof \Illuminate\Testing\PendingCommand);

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function syncedAccountResult(int $accountId): array
    {
        return [
            [
                'account_id' => $accountId,
                'status' => 'success',
                'stats' => ['created' => 1, 'updated' => 0],
            ],
        ];
    }

    public function test_all_flag_syncs_every_user_with_gocardless_accounts(): void
    {
        $users = User::factory()->count(3)->create();

        foreach ($users as $user) {
            Account::factory()->create([
                'user_id' => $user->id,
                'is_gocardless_synced' => true,
                'gocardless_account_id' => 'acc_'.$user->id,
            ]);
        }

        $syncedUserIds = [];

        $serviceMock = Mockery::mock(GoCardlessService::class);
        // @phpstan-ignore-next-line — Mockery shouldReceive() union type; no phpstan-mockery extension configured
        $serviceMock->shouldReceive('syncAllAccounts')
            ->times(3)
            ->andReturnUsing(function (User $user) use (&$syncedUserIds) {
                $syncedUserIds[] = $user->id;

                return $this->syncedAccountResult($user->id);
            });

        $this->app->instance(GoCardlessService::class, $serviceMock);

        $this->runSyncAllCommand(['--all' => true])->assertExitCode(0);

        $this->assertEqualsCanonicalizing($users->pluck('id')->all(), $syncedUserIds);
    }

    public function test_all_flag_skips_users_without_gocardless_accounts(): void
    {
        $withAccount = User::factory()->create();
        Account::factory()->create([
            'user_id' => $withAccount->id,
            'is_gocardless_synced' => true,
            'gocardless_account_id' => 'acc_1',
        ]);

        // User with no accounts at all, and a user whose account is not GoCardless-synced.
        User::factory()->create();
        $withUnsyncedAccount = User::factory()->create();
        Account::factory()->create([
            'user_id' => $withUnsyncedAccount->id,
            'is_gocardless_synced' => false,
        ]);

        $serviceMock = Mockery::mock(GoCardlessService::class);
        // @phpstan-ignore-next-line — Mockery shouldReceive() union type; no phpstan-mockery extension configured
        $serviceMock->shouldReceive('syncAllAccounts')
            ->once()
            ->with(Mockery::on(fn (User $u) => $u->id === $withAccount->id), true, false)
            ->andReturn($this->syncedAccountResult(1));

        $this->app->instance(GoCardlessService::class, $serviceMock);

        $this->runSyncAllCommand(['--all' => true])->assertExitCode(0);
    }

    public function test_all_flag_continues_after_one_user_fails(): void
    {
        $users = User::factory()->count(3)->create();

        foreach ($users as $user) {
            Account::factory()->create([
                'user_id' => $user->id,
                'is_gocardless_synced' => true,
                'gocardless_account_id' => 'acc_'.$user->id,
            ]);
        }

        $failingUser = $users->sortBy('id')->values()->get(1);
        $this->assertNotNull($failingUser);
        $failingUserId = $failingUser->id;
        $syncedUserIds = [];

        $serviceMock = Mockery::mock(GoCardlessService::class);
        // @phpstan-ignore-next-line — Mockery shouldReceive() union type; no phpstan-mockery extension configured
        $serviceMock->shouldReceive('syncAllAccounts')
            ->times(3)
            ->andReturnUsing(function (User $user) use (&$syncedUserIds, $failingUserId) {
                if ($user->id === $failingUserId) {
                    throw new \RuntimeException('boom');
                }

                $syncedUserIds[] = $user->id;

                return $this->syncedAccountResult($user->id);
            });

        $this->app->instance(GoCardlessService::class, $serviceMock);

        // Command must still exit successfully and process the remaining users.
        $this->runSyncAllCommand(['--all' => true])->assertExitCode(0);

        $expected = $users->pluck('id')->reject(fn ($id) => $id === $failingUserId)->values()->all();
        $this->assertEqualsCanonicalizing($expected, $syncedUserIds);
    }

    public function test_without_all_flag_defaults_to_first_user(): void
    {
        $users = User::factory()->count(2)->create();
        $firstUser = $users->sortBy('id')->first();
        $this->assertNotNull($firstUser);

        $serviceMock = Mockery::mock(GoCardlessService::class);
        // @phpstan-ignore-next-line — Mockery shouldReceive() union type; no phpstan-mockery extension configured
        $serviceMock->shouldReceive('syncAllAccounts')
            ->once()
            ->with(Mockery::on(fn (User $u) => $u->id === $firstUser->id), true, false)
            ->andReturn([]);

        $this->app->instance(GoCardlessService::class, $serviceMock);

        $this->runSyncAllCommand()
            ->expectsOutputToContain("No --user given; defaulting to first user (id={$firstUser->id}). Use --user= or --all.")
            ->assertExitCode(0);
    }

    public function test_explicit_user_option_does_not_print_default_message(): void
    {
        $user = User::factory()->create();

        $serviceMock = Mockery::mock(GoCardlessService::class);
        // @phpstan-ignore-next-line — Mockery shouldReceive() union type; no phpstan-mockery extension configured
        $serviceMock->shouldReceive('syncAllAccounts')
            ->once()
            ->with(Mockery::on(fn (User $u) => $u->id === $user->id), true, false)
            ->andReturn([]);

        $this->app->instance(GoCardlessService::class, $serviceMock);

        $this->runSyncAllCommand(['--user' => (string) $user->id])
            ->doesntExpectOutputToContain('No --user given')
            ->assertExitCode(0);
    }
}
