<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\GoCardlessSyncFailure;
use App\Models\Transaction;
use App\Models\User;
use App\Services\GoCardless\TransactionSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * The retry command must route through TransactionSyncService::resyncRawTransactions
 * (the canonical sync pipeline) instead of its own divergent write path, and resolve
 * failures individually based on whether the underlying transaction now exists.
 */
class RetryGoCardlessSyncFailuresCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * artisan() is typed to return PendingCommand|int depending on testing context;
     * narrow it so the fluent assertion methods below are statically known.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function runRetryCommand(array $parameters = []): \Illuminate\Testing\PendingCommand
    {
        $result = $this->artisan('gocardless:retry-failures', $parameters);
        assert($result instanceof \Illuminate\Testing\PendingCommand);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function rawTransaction(
        string $transactionId,
        float $amount = -25.00,
        string $currency = 'EUR',
        string $date = '2026-05-10',
        string $description = 'COFFEE SHOP BRATISLAVA',
        string $partner = 'Coffee Shop'
    ): array {
        return [
            'transactionId' => $transactionId,
            'bookingDate' => $date,
            'valueDate' => $date,
            'transactionAmount' => [
                'amount' => number_format($amount, 2, '.', ''),
                'currency' => $currency,
            ],
            'remittanceInformationUnstructured' => $description,
            'creditorName' => $partner,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createFailure(Account $account, array $overrides = []): GoCardlessSyncFailure
    {
        $createdAt = $overrides['created_at'] ?? now()->subMinutes(5);
        unset($overrides['created_at']);

        $failure = GoCardlessSyncFailure::create(array_merge([
            'account_id' => $account->id,
            'user_id' => $account->user_id,
            'external_transaction_id' => null,
            'error_type' => GoCardlessSyncFailure::ERROR_TYPE_VALIDATION,
            'error_message' => 'Simulated transient failure',
            'raw_data' => $this->rawTransaction('GC-DEFAULT'),
            'retry_count' => 0,
        ], $overrides));

        // created_at is not mass-assignable; force it so the 1-minute "never retried
        // yet" backoff (used when last_retry_at is null) has already elapsed by the
        // time due-selection runs, unless a test explicitly wants a different value.
        $failure->forceFill(['created_at' => $createdAt])->save();

        return $failure;
    }

    private function makeAccount(): Account
    {
        $user = User::factory()->create(['base_currency' => 'EUR']);

        return Account::factory()->create([
            'user_id' => $user->id,
            'currency' => 'EUR',
            'is_gocardless_synced' => true,
            'gocardless_account_id' => 'gc-account-'.fake()->unique()->numerify('####'),
        ]);
    }

    public function test_retry_persists_row_and_marks_resolved(): void
    {
        $user = User::factory()->create(['base_currency' => 'EUR']);
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'currency' => 'EUR',
            'is_gocardless_synced' => true,
            'gocardless_account_id' => 'gc-account-1',
        ]);

        $failure = $this->createFailure($account, [
            'external_transaction_id' => 'GC-RETRY-1',
            'raw_data' => $this->rawTransaction('GC-RETRY-1'),
        ]);

        $this->runRetryCommand()->assertExitCode(0);

        $this->assertDatabaseHas('transactions', [
            'account_id' => $account->id,
            'transaction_id' => 'GC-RETRY-1',
        ]);

        $failure->refresh();
        $this->assertNotNull($failure->resolved_at);
        $this->assertSame('auto_fixed', $failure->resolution);
    }

    public function test_retry_computes_native_amount_for_foreign_currency(): void
    {
        $user = User::factory()->create(['base_currency' => 'EUR']);
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'currency' => 'EUR',
            'is_gocardless_synced' => true,
            'gocardless_account_id' => 'gc-account-2',
        ]);

        // ExchangeRateService::convert() defaults to a 1.0 rate when nothing is seeded,
        // which would mask a broken conversion. Seed a real ECB-style rate so the
        // assertion actually exercises the USD -> EUR conversion path.
        //
        // Deliberately dated a few days before the transaction (not the same day):
        // ExchangeRate columns are cast as 'date' but Eloquent persists them via the
        // connection's full datetime format (e.g. "2026-05-10 00:00:00" on SQLite), so
        // a same-day row's stored value string-compares as *greater than* a bare
        // "Y-m-d" upper bound and would never satisfy findRateWithWalkback()'s
        // `date <= :date` filter. Walking back a few days avoids that day-boundary
        // string-comparison quirk while still exercising the intended lookback window.
        ExchangeRate::create([
            'base_currency' => 'EUR',
            'target_currency' => 'USD',
            'date' => '2026-05-07',
            'rate' => 1.1,
            'source' => 'ecb',
        ]);

        $failure = $this->createFailure($account, [
            'external_transaction_id' => 'GC-USD-1',
            'raw_data' => $this->rawTransaction('GC-USD-1', -50.00, 'USD'),
        ]);

        $this->runRetryCommand()->assertExitCode(0);

        $transaction = Transaction::where('account_id', $account->id)
            ->where('transaction_id', 'GC-USD-1')
            ->firstOrFail();

        $this->assertNotNull($transaction->native_amount);
        // -50 USD at 1.1 EUR->USD (i.e. 1/1.1 USD->EUR) = -45.45 EUR.
        $this->assertEqualsWithDelta(-45.45, (float) $transaction->native_amount, 0.01);

        $failure->refresh();
        $this->assertNotNull($failure->resolved_at);
    }

    public function test_retry_increments_retry_count_when_still_invalid(): void
    {
        $user = User::factory()->create(['base_currency' => 'EUR']);
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'currency' => 'EUR',
            'is_gocardless_synced' => true,
            'gocardless_account_id' => 'gc-account-3',
        ]);

        // 'XX' is not a valid ISO 4217 code: the validator flags it as a hard error, so
        // the row still cannot be persisted even after going through the pipeline again.
        $failure = $this->createFailure($account, [
            'external_transaction_id' => 'GC-INVALID-1',
            'error_message' => 'Currency is not a valid ISO 4217 code',
            'raw_data' => $this->rawTransaction('GC-INVALID-1', -10.00, 'XX'),
        ]);

        $this->runRetryCommand()->assertExitCode(0);

        $failure->refresh();
        $this->assertNull($failure->resolved_at);
        $this->assertSame(1, $failure->retry_count);
        $this->assertNotNull($failure->last_retry_at);

        $this->assertDatabaseMissing('transactions', [
            'account_id' => $account->id,
            'transaction_id' => 'GC-INVALID-1',
        ]);
    }

    public function test_backoff_skips_not_yet_due(): void
    {
        $user = User::factory()->create(['base_currency' => 'EUR']);
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'currency' => 'EUR',
            'is_gocardless_synced' => true,
            'gocardless_account_id' => 'gc-account-4',
        ]);

        // retry_count=1 => 2^1=2 minute backoff; last_retry_at "now" means it is not due yet.
        $failure = $this->createFailure($account, [
            'external_transaction_id' => 'GC-BACKOFF-1',
            'raw_data' => $this->rawTransaction('GC-BACKOFF-1'),
            'retry_count' => 1,
            'last_retry_at' => now(),
            'created_at' => now()->subMinutes(5),
        ]);

        $this->runRetryCommand()
            ->expectsOutputToContain('No failures due for retry.')
            ->assertExitCode(0);

        $failure->refresh();
        $this->assertSame(1, $failure->retry_count);
        $this->assertNull($failure->resolved_at);
        $this->assertDatabaseMissing('transactions', [
            'account_id' => $account->id,
            'transaction_id' => 'GC-BACKOFF-1',
        ]);
    }

    public function test_one_account_failure_does_not_block_others(): void
    {
        $userA = User::factory()->create(['base_currency' => 'EUR']);
        $accountA = Account::factory()->create([
            'user_id' => $userA->id,
            'currency' => 'EUR',
            'is_gocardless_synced' => true,
            'gocardless_account_id' => 'gc-account-a',
        ]);

        $userB = User::factory()->create(['base_currency' => 'EUR']);
        $accountB = Account::factory()->create([
            'user_id' => $userB->id,
            'currency' => 'EUR',
            'is_gocardless_synced' => true,
            'gocardless_account_id' => 'gc-account-b',
        ]);

        $failureA = $this->createFailure($accountA, [
            'external_transaction_id' => 'GC-A-1',
            'raw_data' => $this->rawTransaction('GC-A-1'),
        ]);

        $failureB = $this->createFailure($accountB, [
            'external_transaction_id' => 'GC-B-1',
            'raw_data' => $this->rawTransaction('GC-B-1'),
        ]);

        // Replace the real pipeline with a double that blows up for account A's group
        // (simulating an unexpected error deep in the pipeline) but succeeds for B's,
        // so the test proves isolation without depending on a specific real failure mode.
        $mock = Mockery::mock(TransactionSyncService::class);
        // @phpstan-ignore-next-line — Mockery shouldReceive() union type; no phpstan-mockery extension configured
        $mock->shouldReceive('resyncRawTransactions')
            ->with(Mockery::any(), Mockery::on(fn (Account $account) => $account->id === $accountA->id))
            ->once()
            ->andThrow(new \RuntimeException('boom'));

        // @phpstan-ignore-next-line — Mockery shouldReceive() union type; no phpstan-mockery extension configured
        $mock->shouldReceive('resyncRawTransactions')
            ->with(Mockery::any(), Mockery::on(fn (Account $account) => $account->id === $accountB->id))
            ->once()
            ->andReturnUsing(function (array $raw, Account $account) {
                Transaction::factory()->create([
                    'account_id' => $account->id,
                    'transaction_id' => 'GC-B-1',
                ]);

                return [
                    'created' => 1, 'updated' => 0, 'skipped' => 0, 'errors' => 0,
                    'needs_review' => 0, 'skipped_reasons' => [], 'earliest_unsynced_date' => null,
                ];
            });

        $this->app->instance(TransactionSyncService::class, $mock);

        $this->runRetryCommand()->assertExitCode(0);

        $failureA->refresh();
        $this->assertNull($failureA->resolved_at);
        $this->assertSame(1, $failureA->retry_count);

        $failureB->refresh();
        $this->assertNotNull($failureB->resolved_at);
        $this->assertSame('auto_fixed', $failureB->resolution);
    }

    /**
     * A deterministically bad payload fails on every sync, and retry-failures replays it every 30
     * minutes. Inserting a fresh record each time turned one broken transaction into unbounded
     * table growth.
     */
    public function test_recording_the_same_failing_row_twice_updates_one_record(): void
    {
        $repository = app(\App\Contracts\Repositories\GoCardlessSyncFailureRepositoryInterface::class);
        $account = $this->makeAccount();

        $payload = [
            'account_id' => $account->id,
            'user_id' => $account->user_id,
            'external_transaction_id' => 'GC-DUP-1',
            'error_type' => \App\Models\GoCardlessSyncFailure::ERROR_TYPE_VALIDATION,
            'error_message' => 'Currency is required',
            'raw_data' => ['transactionId' => 'GC-DUP-1'],
        ];

        $repository->create($payload);
        $repository->create($payload);

        $this->assertSame(1, \App\Models\GoCardlessSyncFailure::where('external_transaction_id', 'GC-DUP-1')->count());
    }

    /**
     * Rows the provider gave no id for cannot be recognised as the same movement, so they still
     * append rather than being wrongly collapsed onto each other.
     */
    public function test_failures_without_a_provider_id_are_not_collapsed(): void
    {
        $repository = app(\App\Contracts\Repositories\GoCardlessSyncFailureRepositoryInterface::class);
        $account = $this->makeAccount();

        foreach ([1, 2] as $_) {
            $repository->create([
                'account_id' => $account->id,
                'user_id' => $account->user_id,
                'external_transaction_id' => null,
                'error_type' => \App\Models\GoCardlessSyncFailure::ERROR_TYPE_MAPPING,
                'error_message' => 'boom',
                'raw_data' => ['x' => 1],
            ]);
        }

        $this->assertSame(2, \App\Models\GoCardlessSyncFailure::whereNull('external_transaction_id')->count());
    }

    /**
     * Exhausted rows used to sit with resolved_at NULL forever, indistinguishable by any query
     * from a failure recorded a minute ago.
     */
    public function test_failures_past_the_retry_ceiling_are_parked_as_exhausted(): void
    {
        $account = $this->makeAccount();
        $failure = \App\Models\GoCardlessSyncFailure::create([
            'account_id' => $account->id,
            'user_id' => $account->user_id,
            'external_transaction_id' => 'GC-EXHAUSTED',
            'error_type' => \App\Models\GoCardlessSyncFailure::ERROR_TYPE_VALIDATION,
            'error_message' => 'always fails',
            'raw_data' => ['transactionId' => 'GC-EXHAUSTED'],
            'retry_count' => \App\Models\GoCardlessSyncFailure::MAX_RETRIES,
        ]);

        $this->artisan('gocardless:retry-failures')->assertSuccessful();

        $failure->refresh();
        $this->assertNotNull($failure->resolved_at);
        $this->assertTrue($failure->isExhausted());
    }

    /**
     * raw_data holds the provider's full payload — amounts, IBANs, counterparty names. It was the
     * only unencrypted copy of bank data in the schema.
     */
    public function test_raw_data_is_encrypted_at_rest(): void
    {
        $account = $this->makeAccount();
        \App\Models\GoCardlessSyncFailure::create([
            'account_id' => $account->id,
            'user_id' => $account->user_id,
            'external_transaction_id' => 'GC-SECRET',
            'error_type' => \App\Models\GoCardlessSyncFailure::ERROR_TYPE_VALIDATION,
            'error_message' => 'nope',
            'raw_data' => ['debtorAccount' => ['iban' => 'SK3112000000198742637541']],
        ]);

        $stored = \Illuminate\Support\Facades\DB::table('gocardless_sync_failures')
            ->where('external_transaction_id', 'GC-SECRET')
            ->value('raw_data');

        $this->assertIsString($stored);
        $this->assertStringNotContainsString('SK3112000000198742637541', $stored);

        // Still readable through the model.
        $this->assertSame(
            'SK3112000000198742637541',
            \App\Models\GoCardlessSyncFailure::where('external_transaction_id', 'GC-SECRET')
                ->first()->raw_data['debtorAccount']['iban']
        );
    }
}
