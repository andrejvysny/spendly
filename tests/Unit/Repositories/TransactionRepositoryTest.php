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
            'target_iban' => 'SK4211000000002948050714',
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
            'target_iban' => 'SK4211000000002948050714',
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
}
