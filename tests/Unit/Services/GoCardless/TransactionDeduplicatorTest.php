<?php

declare(strict_types=1);

namespace Tests\Unit\Services\GoCardless;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\TransactionRepository;
use App\Services\GoCardless\DTOs\DedupDecision;
use App\Services\GoCardless\TransactionDeduplicator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionDeduplicatorTest extends TestCase
{
    use RefreshDatabase;

    private TransactionDeduplicator $deduplicator;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->deduplicator = new TransactionDeduplicator(new TransactionRepository(new Transaction));

        $user = User::factory()->create();
        $this->account = Account::factory()->create([
            'user_id' => $user->id,
            'currency' => 'EUR',
        ]);
    }

    public function test_second_identical_purchase_with_a_distinct_provider_id_is_created(): void
    {
        $first = $this->mappedData('GC-1');
        $this->seedTransaction($first, ['is_gocardless_synced' => true, 'import_data' => ['raw' => true]]);

        $second = $this->mappedData('GC-2');
        $this->assertSame($first['fingerprint'], $second['fingerprint'], 'fixture must collide on fingerprint');

        $decision = $this->deduplicator->decide(
            (int) $this->account->id,
            $second,
            true,
            collect(['GC-1']),
            true
        );

        $this->assertTrue($decision->isCreate());
        $this->assertSame(DedupDecision::REASON_NEW, $decision->reason);
    }

    public function test_known_transaction_id_is_routed_to_an_update(): void
    {
        $data = $this->mappedData('GC-10');
        $this->seedTransaction($data, ['is_gocardless_synced' => true]);

        $decision = $this->deduplicator->decide(
            (int) $this->account->id,
            $data,
            true,
            collect(['GC-10']),
            true
        );

        $this->assertTrue($decision->isUpdate());
        $this->assertSame(DedupDecision::REASON_EXISTING_TRANSACTION_ID, $decision->reason);
        $this->assertSame('GC-10', $decision->targetTransactionId);
    }

    public function test_known_transaction_id_is_skipped_when_updates_are_disabled(): void
    {
        $data = $this->mappedData('GC-11');
        $this->seedTransaction($data, ['is_gocardless_synced' => true]);

        $decision = $this->deduplicator->decide(
            (int) $this->account->id,
            $data,
            true,
            collect(['GC-11']),
            false
        );

        $this->assertTrue($decision->isSkip());
        $this->assertSame(DedupDecision::REASON_UPDATE_DISABLED, $decision->reason);
    }

    public function test_row_without_provider_id_is_skipped_on_fingerprint_collision(): void
    {
        $stored = $this->mappedData('fallback_aaa');
        $this->seedTransaction($stored, ['is_gocardless_synced' => true]);

        $incoming = $this->mappedData('fallback_bbb');

        $decision = $this->deduplicator->decide(
            (int) $this->account->id,
            $incoming,
            false,
            collect([]),
            true
        );

        $this->assertTrue($decision->isSkip());
        $this->assertSame(DedupDecision::REASON_FINGERPRINT_DUPLICATE, $decision->reason);
    }

    public function test_csv_import_of_the_same_movement_is_adopted(): void
    {
        $incoming = $this->mappedData('GC-20');
        $this->seedTransaction($incoming, [
            'transaction_id' => 'IMP-20',
            'is_gocardless_synced' => false,
            'import_data' => ['source' => 'csv'],
        ]);

        $decision = $this->deduplicator->decide(
            (int) $this->account->id,
            $incoming,
            true,
            collect([]),
            true
        );

        $this->assertTrue($decision->isUpdate());
        $this->assertSame(DedupDecision::REASON_ADOPTED_CSV_IMPORT, $decision->reason);
        $this->assertSame('IMP-20', $decision->targetTransactionId);
    }

    public function test_previously_synced_row_is_never_adopted_as_a_csv_import(): void
    {
        // The mapper stamps import_data on every synced row; only is_gocardless_synced
        // separates a real CSV import from a row this integration already wrote.
        $incoming = $this->mappedData('GC-31');
        $this->seedTransaction($incoming, [
            'transaction_id' => 'GC-30',
            'is_gocardless_synced' => true,
            'import_data' => ['raw' => 'provider payload'],
        ]);

        $decision = $this->deduplicator->decide(
            (int) $this->account->id,
            $incoming,
            true,
            collect(['GC-30']),
            true
        );

        $this->assertTrue($decision->isCreate());
        $this->assertSame(DedupDecision::REASON_NEW, $decision->reason);
    }

    public function test_weak_csv_overlap_is_created_and_flagged_for_review(): void
    {
        $incoming = $this->mappedData('GC-40', 'ONLINE ORDER 8817', 'Acme GmbH');

        // Same day, currency and amount, but nothing else lines up.
        $this->seedTransaction(
            $this->mappedData('IMP-40', 'Cash withdrawal ATM Kosice', 'ATM'),
            [
                'is_gocardless_synced' => false,
                'import_data' => ['source' => 'csv'],
            ]
        );

        $decision = $this->deduplicator->decide(
            (int) $this->account->id,
            $incoming,
            true,
            collect([]),
            true
        );

        $this->assertTrue($decision->isCreate());
        $this->assertSame(DedupDecision::REASON_PROBABLE_DUPLICATE, $decision->reason);
        $this->assertTrue($decision->needsReview);
    }

    /**
     * @return array<string, mixed>
     */
    private function mappedData(
        string $transactionId,
        string $description = 'COFFEE SHOP BRATISLAVA',
        string $partner = 'Coffee Shop',
        float $amount = -4.50,
        string $date = '2026-05-10'
    ): array {
        $data = [
            'transaction_id' => $transactionId,
            'account_id' => (int) $this->account->id,
            'amount' => $amount,
            'currency' => 'EUR',
            'booked_date' => Carbon::parse($date),
            'processed_date' => Carbon::parse($date),
            'description' => $description,
            'partner' => $partner,
            'type' => Transaction::TYPE_PAYMENT,
            'source_iban' => null,
            'target_iban' => null,
        ];

        $data['fingerprint'] = Transaction::generateFingerprint($data);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $mapped
     * @param  array<string, mixed>  $overrides
     */
    private function seedTransaction(array $mapped, array $overrides = []): void
    {
        Transaction::factory()->create(array_merge([
            'account_id' => $mapped['account_id'],
            'transaction_id' => $mapped['transaction_id'],
            'amount' => $mapped['amount'],
            'currency' => $mapped['currency'],
            'booked_date' => $mapped['booked_date'],
            'processed_date' => $mapped['processed_date'],
            'description' => $mapped['description'],
            'partner' => $mapped['partner'],
            'type' => $mapped['type'],
            'source_iban' => null,
            'target_iban' => null,
            'fingerprint' => $mapped['fingerprint'],
        ], $overrides));
    }
}
