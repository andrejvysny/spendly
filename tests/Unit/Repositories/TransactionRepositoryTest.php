<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\TransactionRepository;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private TransactionRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new TransactionRepository(new Transaction);
    }

    public function test_find_strong_matching_import_prefers_unique_exact_fingerprint_match(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'currency' => 'EUR',
        ]);

        $mappedData = [
            'account_id' => $account->id,
            'transaction_id' => 'GC-1',
            'amount' => -150.00,
            'currency' => 'EUR',
            'booked_date' => Carbon::parse('2026-02-02'),
            'description' => 'Finax savings transfer',
            'partner' => 'Finax',
            'type' => Transaction::TYPE_PAYMENT,
            'source_iban' => null,
            'target_iban' => 'SK9900000000000000000004',
        ];
        $mappedData['fingerprint'] = Transaction::generateFingerprint($mappedData);

        $expected = Transaction::factory()->create([
            'account_id' => $account->id,
            'transaction_id' => 'IMP-100',
            'amount' => -150.00,
            'currency' => 'EUR',
            'booked_date' => Carbon::parse('2026-02-02'),
            'processed_date' => Carbon::parse('2026-02-02'),
            'description' => 'Old CSV row',
            'partner' => 'Imported partner',
            'type' => Transaction::TYPE_PAYMENT,
            'target_iban' => 'SK9900000000000000000004',
            'source_iban' => null,
            'fingerprint' => $mappedData['fingerprint'],
            'import_data' => ['source' => 'csv'],
        ]);

        Transaction::factory()->create([
            'account_id' => $account->id,
            'transaction_id' => 'IMP-101',
            'amount' => -150.00,
            'currency' => 'EUR',
            'booked_date' => Carbon::parse('2026-02-02'),
            'processed_date' => Carbon::parse('2026-02-02'),
            'description' => 'Different candidate',
            'partner' => 'Another partner',
            'type' => Transaction::TYPE_PAYMENT,
            'target_iban' => null,
            'source_iban' => null,
            'fingerprint' => Transaction::generateFingerprint([
                'account_id' => $account->id,
                'amount' => -150.00,
                'currency' => 'EUR',
                'booked_date' => '2026-02-02',
                'description' => 'Different candidate',
                'partner' => 'Another partner',
                'type' => Transaction::TYPE_PAYMENT,
            ]),
            'import_data' => ['source' => 'csv'],
        ]);

        $match = $this->repository->findStrongMatchingImport($account->id, $mappedData);

        $this->assertNotNull($match);
        $this->assertTrue($expected->is($match));
    }

    public function test_find_strong_matching_import_returns_unique_high_similarity_match_without_fingerprint(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'currency' => 'EUR',
        ]);

        $expected = Transaction::factory()->create([
            'account_id' => $account->id,
            'transaction_id' => 'IMP-200',
            'amount' => -89.99,
            'currency' => 'EUR',
            'booked_date' => Carbon::parse('2026-02-05'),
            'processed_date' => Carbon::parse('2026-02-05'),
            'description' => 'Spotify subscription',
            'partner' => 'Spotify',
            'type' => Transaction::TYPE_PAYMENT,
            'fingerprint' => null,
            'import_data' => ['source' => 'csv'],
        ]);

        Transaction::factory()->create([
            'account_id' => $account->id,
            'transaction_id' => 'IMP-201',
            'amount' => -89.99,
            'currency' => 'EUR',
            'booked_date' => Carbon::parse('2026-02-05'),
            'processed_date' => Carbon::parse('2026-02-05'),
            'description' => 'Utility bill',
            'partner' => 'Water Company',
            'type' => Transaction::TYPE_PAYMENT,
            'fingerprint' => null,
            'import_data' => ['source' => 'csv'],
        ]);

        $match = $this->repository->findStrongMatchingImport($account->id, [
            'amount' => -89.99,
            'currency' => 'EUR',
            'booked_date' => '2026-02-05',
            'description' => 'Spotify subscription',
            'partner' => 'Spotify',
        ]);

        $this->assertNotNull($match);
        $this->assertTrue($expected->is($match));
    }

    public function test_find_strong_matching_import_returns_null_when_same_day_amount_case_is_ambiguous(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'currency' => 'EUR',
        ]);

        Transaction::factory()->create([
            'account_id' => $account->id,
            'transaction_id' => 'IMP-300',
            'amount' => -25.00,
            'currency' => 'EUR',
            'booked_date' => Carbon::parse('2026-02-10'),
            'processed_date' => Carbon::parse('2026-02-10'),
            'description' => 'Coffee House',
            'partner' => 'Coffee House',
            'type' => Transaction::TYPE_PAYMENT,
            'fingerprint' => null,
            'import_data' => ['source' => 'csv'],
        ]);

        Transaction::factory()->create([
            'account_id' => $account->id,
            'transaction_id' => 'IMP-301',
            'amount' => -25.00,
            'currency' => 'EUR',
            'booked_date' => Carbon::parse('2026-02-10'),
            'processed_date' => Carbon::parse('2026-02-10'),
            'description' => 'Coffee House',
            'partner' => 'Coffee House',
            'type' => Transaction::TYPE_PAYMENT,
            'fingerprint' => null,
            'import_data' => ['source' => 'csv'],
        ]);

        $match = $this->repository->findStrongMatchingImport($account->id, [
            'amount' => -25.00,
            'currency' => 'EUR',
            'booked_date' => '2026-02-10',
            'description' => 'Coffee House',
            'partner' => 'Coffee House',
        ]);

        $this->assertNull($match);
    }

    public function test_find_strong_matching_import_ignores_previously_synced_rows(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'currency' => 'EUR',
        ]);

        // The GoCardless mapper writes import_data on every synced row, so only
        // is_gocardless_synced distinguishes a real CSV import from a synced one.
        Transaction::factory()->create([
            'account_id' => $account->id,
            'transaction_id' => 'GC-400',
            'amount' => -12.50,
            'currency' => 'EUR',
            'booked_date' => Carbon::parse('2026-02-14'),
            'processed_date' => Carbon::parse('2026-02-14'),
            'description' => 'Bakery Corner',
            'partner' => 'Bakery Corner',
            'type' => Transaction::TYPE_PAYMENT,
            'fingerprint' => null,
            'import_data' => ['raw' => 'provider payload'],
            'is_gocardless_synced' => true,
        ]);

        $mappedData = [
            'amount' => -12.50,
            'currency' => 'EUR',
            'booked_date' => '2026-02-14',
            'description' => 'Bakery Corner',
            'partner' => 'Bakery Corner',
        ];

        $this->assertNull($this->repository->findStrongMatchingImport($account->id, $mappedData));
        $this->assertFalse($this->repository->hasPotentialImportMatch($account->id, $mappedData));
    }

    public function test_has_potential_import_match_detects_same_day_csv_row(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'currency' => 'EUR',
        ]);

        Transaction::factory()->create([
            'account_id' => $account->id,
            'transaction_id' => 'IMP-400',
            'amount' => -33.00,
            'currency' => 'EUR',
            'booked_date' => Carbon::parse('2026-02-16'),
            'processed_date' => Carbon::parse('2026-02-16'),
            'description' => 'Totally different text',
            'partner' => 'Someone else',
            'type' => Transaction::TYPE_PAYMENT,
            'fingerprint' => null,
            'import_data' => ['source' => 'csv'],
        ]);

        $this->assertTrue($this->repository->hasPotentialImportMatch($account->id, [
            'amount' => -33.00,
            'currency' => 'EUR',
            'booked_date' => '2026-02-16',
            'description' => 'Bookshop purchase',
            'partner' => 'Bookshop',
        ]));

        $this->assertFalse($this->repository->hasPotentialImportMatch($account->id, [
            'amount' => -33.00,
            'currency' => 'EUR',
            'booked_date' => '2026-02-17',
            'description' => 'Bookshop purchase',
            'partner' => 'Bookshop',
        ]));
    }

    public function test_create_batch_count_reflects_rows_actually_inserted(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'currency' => 'EUR',
        ]);

        Transaction::factory()->create([
            'account_id' => $account->id,
            'transaction_id' => 'DUP-1',
        ]);

        $inserted = $this->repository->createBatch([
            $this->insertRow($account->id, 'DUP-1'),
            $this->insertRow($account->id, 'NEW-1'),
        ]);

        // The duplicate is dropped by the (account_id, transaction_id) unique index.
        $this->assertSame(1, $inserted);
        $this->assertSame(2, Transaction::where('account_id', $account->id)->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function insertRow(int $accountId, string $transactionId): array
    {
        return [
            'account_id' => $accountId,
            'transaction_id' => $transactionId,
            'amount' => -10.00,
            'currency' => 'EUR',
            'booked_date' => '2026-02-20 00:00:00',
            'processed_date' => '2026-02-20 00:00:00',
            'description' => 'Batch row '.$transactionId,
            'type' => Transaction::TYPE_PAYMENT,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
