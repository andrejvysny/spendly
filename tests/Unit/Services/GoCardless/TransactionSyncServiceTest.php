<?php

declare(strict_types=1);

namespace Tests\Unit\Services\GoCardless;

use App\Contracts\Repositories\TransactionRepositoryInterface;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\GoCardless\GocardlessMapper;
use App\Services\GoCardless\TransactionSyncService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private TransactionSyncService $service;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(TransactionSyncService::class);

        $user = User::factory()->create(['base_currency' => 'EUR']);
        $this->account = Account::factory()->create([
            'user_id' => $user->id,
            'currency' => 'EUR',
            'is_gocardless_synced' => true,
            'gocardless_account_id' => 'gc-account-1',
        ]);
    }

    /**
     * createBatch() inserts with insertOrIgnore, so a row rejected by the
     * (account_id, transaction_id) unique index — a row that lost a race to a concurrent write —
     * disappears with no exception and no failure record. The shortfall between what was offered
     * and what was stored is the only trace it leaves, and the caller needs it to hold the
     * watermark back.
     */
    public function test_rows_dropped_by_the_unique_index_are_counted(): void
    {
        $repository = \Mockery::mock(TransactionRepositoryInterface::class);
        $repository->shouldReceive('getExistingTransactionIds')->andReturn(collect());
        $repository->shouldReceive('findStrongMatchingImport')->andReturn(null);
        $repository->shouldReceive('fingerprintExists')->andReturn(false);
        $repository->shouldReceive('hasPotentialImportMatch')->andReturn(false);
        $repository->shouldReceive('transaction')->andReturnUsing(fn (callable $callback) => $callback());
        $repository->shouldReceive('updateBatch')->andReturn(0);
        // Two rows offered; the database accepted one.
        $repository->shouldReceive('createBatch')->once()->andReturn(1);

        $this->app->instance(TransactionRepositoryInterface::class, $repository);
        $this->app->forgetInstance(\App\Services\GoCardless\TransactionDeduplicator::class);
        $this->app->forgetInstance(TransactionSyncService::class);

        $stats = app(TransactionSyncService::class)->syncTransactions([
            $this->rawTransaction('GC-RACE-1'),
            $this->rawTransaction('GC-RACE-2'),
        ], $this->account);

        $this->assertSame(1, $stats['created']);
        $this->assertSame(1, $stats['dropped']);
    }

    /**
     * The ordinary case must not report phantom drops.
     */
    public function test_clean_sync_reports_no_dropped_rows(): void
    {
        $stats = $this->service->syncTransactions([
            $this->rawTransaction('GC-1'),
            $this->rawTransaction('GC-2'),
        ], $this->account);

        $this->assertSame(2, $stats['created']);
        $this->assertSame(0, $stats['dropped']);
    }

    /**
     * The other half of the first-sync guarantee: importing an account must not claim a data
     * watermark it never earned. Asserted here rather than in GocardlessMapperTest because that
     * class is fixture-gated and skips entirely in CI.
     */
    public function test_mapped_account_data_does_not_claim_a_sync_watermark(): void
    {
        $mapped = app(GocardlessMapper::class)->mapAccountData([
            'id' => 'gc-account-2',
            'iban' => 'DE89370400440532013000',
            'currency' => 'EUR',
            'balance' => 10.0,
        ]);

        $this->assertArrayNotHasKey('gocardless_last_synced_at', $mapped);
    }

    /**
     * A freshly imported account has never fetched anything, so its first sync must take the whole
     * 90-day window the consent was negotiated for.
     *
     * Regression: mapAccountData() used to stamp gocardless_last_synced_at = now() at import time,
     * which made this window `now()-1day … now()` and silently threw away 89 days of history.
     */
    public function test_first_sync_of_a_freshly_imported_account_uses_the_full_window(): void
    {
        $this->account->gocardless_last_synced_at = null;

        $range = $this->service->calculateDateRange($this->account, 90);

        $this->assertSame(now()->subDays(90)->format('Y-m-d'), $range['date_from']);
        $this->assertSame(now()->format('Y-m-d'), $range['date_to']);
    }

    /**
     * An account that has synced before only re-fetches from its watermark, minus a day of overlap.
     */
    public function test_incremental_sync_starts_one_day_before_the_watermark(): void
    {
        $this->account->gocardless_last_synced_at = now()->subDays(5);

        $range = $this->service->calculateDateRange($this->account, 90);

        $this->assertSame(now()->subDays(6)->format('Y-m-d'), $range['date_from']);
    }

    /**
     * A watermark older than the cap cannot widen the window past what the provider will serve.
     */
    public function test_watermark_older_than_the_cap_is_clamped(): void
    {
        $this->account->gocardless_last_synced_at = now()->subDays(400);

        $range = $this->service->calculateDateRange($this->account, 90);

        $this->assertSame(now()->subDays(90)->format('Y-m-d'), $range['date_from']);
    }

    /**
     * A watermark in the future is not trustworthy; fall back to the full window rather than
     * producing an inverted range.
     */
    public function test_future_watermark_falls_back_to_the_full_window(): void
    {
        $this->account->gocardless_last_synced_at = now()->addDays(3);

        $range = $this->service->calculateDateRange($this->account, 90);

        $this->assertSame(now()->subDays(90)->format('Y-m-d'), $range['date_from']);
    }

    /**
     * force_max_date_range ignores the watermark entirely — this is what the account detail toggle
     * and the console commands use to re-pull history.
     */
    public function test_force_max_date_range_ignores_the_watermark(): void
    {
        $this->account->gocardless_last_synced_at = now()->subDay();

        $range = $this->service->calculateDateRange($this->account, 90, forceMax: true);

        $this->assertSame(now()->subDays(90)->format('Y-m-d'), $range['date_from']);
    }

    /**
     * The headline regression: buying the same coffee twice on one day produces two
     * provider transactions that share every fingerprinted field. Both are real.
     */
    public function test_two_identical_same_day_transactions_with_distinct_ids_both_created(): void
    {
        $stats = $this->service->syncTransactions([
            $this->rawTransaction('GC-1'),
            $this->rawTransaction('GC-2'),
        ], $this->account);

        $this->assertSame(2, $stats['created']);
        $this->assertSame(0, $stats['skipped']);
        $this->assertSame(2, $this->transactionCount());
        $this->assertSame(
            ['GC-1', 'GC-2'],
            Transaction::where('account_id', $this->account->id)->orderBy('transaction_id')->pluck('transaction_id')->all()
        );

        $fingerprints = Transaction::where('account_id', $this->account->id)->pluck('fingerprint')->unique();
        $this->assertCount(1, $fingerprints, 'fixture must actually collide on fingerprint');
    }

    public function test_identical_transaction_resynced_with_same_id_is_updated_not_duplicated(): void
    {
        $this->service->syncTransactions([$this->rawTransaction('GC-100')], $this->account);

        $stats = $this->service->syncTransactions([$this->rawTransaction('GC-100')], $this->account);

        $this->assertSame(0, $stats['created']);
        $this->assertSame(1, $stats['updated']);
        $this->assertSame(1, $this->transactionCount());
    }

    public function test_id_less_duplicate_is_skipped_by_fingerprint_and_counted(): void
    {
        // No transactionId: the validator synthesises one from the internal id, so two
        // payloads that differ only there are indistinguishable movements.
        $first = $this->rawTransaction(null);
        $first['internalTransactionId'] = 'internal-a';

        $second = $this->rawTransaction(null);
        $second['internalTransactionId'] = 'internal-b';

        $this->service->syncTransactions([$first], $this->account);
        $stats = $this->service->syncTransactions([$second], $this->account);

        $this->assertSame(0, $stats['created']);
        $this->assertSame(1, $stats['skipped']);
        $this->assertSame(1, $stats['skipped_reasons']['fingerprint_duplicate']);
        $this->assertSame(1, $this->transactionCount());
    }

    public function test_id_less_row_resynced_matches_via_generated_fallback_id(): void
    {
        $raw = $this->rawTransaction(null);
        $raw['internalTransactionId'] = 'internal-stable';

        $this->service->syncTransactions([$raw], $this->account);
        $stats = $this->service->syncTransactions([$raw], $this->account);

        $this->assertSame(0, $stats['created']);
        $this->assertSame(1, $stats['updated']);
        $this->assertSame(0, $stats['skipped']);
        $this->assertSame(1, $this->transactionCount());

        $stored = Transaction::where('account_id', $this->account->id)->firstOrFail();
        $this->assertStringStartsWith('fallback_', (string) $stored->transaction_id);
    }

    public function test_csv_import_is_adopted_when_same_movement_arrives_via_sync(): void
    {
        $raw = $this->rawTransaction('GC-500', -150.00, 'FINAX SAVINGS', 'Finax');

        $this->seedCsvImport('IMP-500', $raw);

        $stats = $this->service->syncTransactions([$raw], $this->account);

        $this->assertSame(0, $stats['created']);
        $this->assertSame(1, $stats['updated']);
        $this->assertSame(1, $this->transactionCount());

        $adopted = Transaction::where('account_id', $this->account->id)->firstOrFail();
        $this->assertSame('GC-500', $adopted->transaction_id);
        $this->assertTrue($adopted->is_gocardless_synced);
    }

    public function test_previously_synced_gocardless_row_is_not_treated_as_csv_import(): void
    {
        // Every synced row carries import_data, so without the is_gocardless_synced
        // narrowing this second purchase would overwrite the first one.
        $this->service->syncTransactions([$this->rawTransaction('GC-600')], $this->account);

        $stats = $this->service->syncTransactions([$this->rawTransaction('GC-601')], $this->account);

        $this->assertSame(1, $stats['created']);
        $this->assertSame(0, $stats['updated']);
        $this->assertSame(2, $this->transactionCount());
        $this->assertSame(
            ['GC-600', 'GC-601'],
            Transaction::where('account_id', $this->account->id)->orderBy('transaction_id')->pluck('transaction_id')->all()
        );
    }

    public function test_probable_duplicate_of_csv_import_is_created_and_flagged_for_review(): void
    {
        // Same day, currency and amount as the incoming row, but no textual overlap.
        $unrelated = $this->rawTransaction('IMP-700', -4.50, 'Cash withdrawal ATM Kosice', 'ATM Kosice');
        $this->seedCsvImport('IMP-700', $unrelated);

        $stats = $this->service->syncTransactions([$this->rawTransaction('GC-700')], $this->account);

        $this->assertSame(1, $stats['created']);
        $this->assertSame(2, $this->transactionCount());

        $created = Transaction::where('transaction_id', 'GC-700')->firstOrFail();
        $this->assertTrue($created->needs_manual_review);
        $this->assertStringContainsString('probable_duplicate', (string) $created->review_reason);
    }

    public function test_update_existing_false_skips_with_update_disabled_reason(): void
    {
        $this->service->syncTransactions([$this->rawTransaction('GC-800')], $this->account);

        $stats = $this->service->syncTransactions([$this->rawTransaction('GC-800')], $this->account, false);

        $this->assertSame(0, $stats['created']);
        $this->assertSame(0, $stats['updated']);
        $this->assertSame(1, $stats['skipped']);
        $this->assertSame(1, $stats['skipped_reasons']['update_disabled']);
        $this->assertSame(1, $this->transactionCount());
    }

    public function test_duplicate_transaction_id_within_one_batch_skipped_not_inserted_twice(): void
    {
        $stats = $this->service->syncTransactions([
            $this->rawTransaction('GC-900'),
            $this->rawTransaction('GC-900'),
        ], $this->account);

        $this->assertSame(1, $stats['created']);
        $this->assertSame(1, $stats['skipped']);
        $this->assertSame(1, $stats['skipped_reasons']['duplicate_in_batch']);
        $this->assertSame(1, $this->transactionCount());
    }

    private function transactionCount(): int
    {
        return Transaction::where('account_id', $this->account->id)->count();
    }

    /**
     * Seed a CSV-imported row that mirrors the given provider payload, including the
     * fingerprint the sync pipeline would compute for it.
     *
     * @param  array<string, mixed>  $raw
     */
    private function seedCsvImport(string $transactionId, array $raw): void
    {
        $mapped = app(GocardlessMapper::class)->mapTransactionData($raw, $this->account, Carbon::now());

        Transaction::factory()->create([
            'account_id' => $this->account->id,
            'transaction_id' => $transactionId,
            'amount' => $mapped['amount'],
            'currency' => $mapped['currency'],
            'booked_date' => $mapped['booked_date'],
            'processed_date' => $mapped['processed_date'],
            'description' => $mapped['description'],
            'partner' => $mapped['partner'],
            'type' => $mapped['type'],
            'source_iban' => null,
            'target_iban' => null,
            'fingerprint' => Transaction::generateFingerprint($mapped),
            'is_gocardless_synced' => false,
            'import_data' => ['source' => 'csv'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rawTransaction(
        ?string $transactionId,
        float $amount = -4.50,
        string $description = 'COFFEE SHOP BRATISLAVA',
        string $partner = 'Coffee Shop',
        string $date = '2026-05-10'
    ): array {
        $raw = [
            'bookingDate' => $date,
            'valueDate' => $date,
            'transactionAmount' => [
                'amount' => number_format($amount, 2, '.', ''),
                'currency' => 'EUR',
            ],
            'remittanceInformationUnstructured' => $description,
            'creditorName' => $partner,
        ];

        if ($transactionId !== null) {
            $raw['transactionId'] = $transactionId;
        }

        return $raw;
    }
}
