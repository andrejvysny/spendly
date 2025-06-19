<?php

namespace Tests\Unit;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\TransactionFingerprint;
use App\Models\User;
use App\Services\DuplicateTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DuplicateTransactionServiceTest extends TestCase
{
    use RefreshDatabase;

    private DuplicateTransactionService $service;

    protected function setUp(): void
    {
        $this->markTestIncomplete("This test is incomplete and needs to be implemented.");
        parent::setUp();
        $mapping = [
            'description' => ['notes', 'description'],
            'booked_date' => ['booked_date', 'date'],
            'reference_id' => ['reference', 'transaction_id'],
        ];
        $this->service = new DuplicateTransactionService($mapping);
    }

    public static function duplicateProvider(): array
    {
        return [
            'exact duplicate' => [
                [
                    'booked_date' => '2024-01-01',
                    'processed_date' => '2024-01-01',
                    'amount' => 9.99,
                    'description' => 'Netflix EU',
                    'transaction_id' => 'abc',
                ],
                [
                    'booked_date' => '2024-01-01',
                    'processed_date' => '2024-01-01',
                    'amount' => 9.99,
                    'description' => 'Netflix EU',
                    'transaction_id' => 'abc',
                ],
                true,
            ],
            'recurring payment different date' => [
                [
                    'booked_date' => '2024-01-01',
                    'processed_date' => '2024-01-01',
                    'amount' => 20.00,
                    'description' => 'Gym',
                    'transaction_id' => 'r1',
                ],
                [
                    'booked_date' => '2024-01-10',
                    'processed_date' => '2024-01-10',
                    'amount' => 20.00,
                    'description' => 'Gym',
                    'transaction_id' => 'r2',
                ],
                false,
            ],
            'fuzzy description' => [
                [
                    'booked_date' => '2024-02-01',
                    'processed_date' => '2024-02-01',
                    'amount' => 15.00,
                    'description' => 'Netflix EU',
                    'transaction_id' => 'f1',
                ],
                [
                    'booked_date' => '2024-02-01',
                    'processed_date' => '2024-02-01',
                    'amount' => 15.00,
                    'description' => 'Netflx EU',
                    'transaction_id' => 'f2',
                ],
                true,
            ],
            'mapped fields' => [
                [
                    'booked_date' => '2024-03-01',
                    'processed_date' => '2024-03-01',
                    'amount' => 30.00,
                    'description' => 'Utility Bill',
                    'transaction_id' => 'm1',
                ],
                [
                    'date' => '2024-03-01',
                    'processed_date' => '2024-03-01',
                    'amount' => 30.00,
                    'notes' => 'Utility Bill',
                    'reference' => 'm1',
                ],
                true,
            ],
        ];
    }

    /**
     * @dataProvider duplicateProvider
     */
    public function test_duplicate_detection(array $existing, array $new, bool $expected): void
    {
        $user = User::factory()->create();
        $account = Account::create([
            'user_id' => $user->id,
            'name' => 'Test',
            'bank_name' => 'Bank',
            'iban' => 'DE89370400440532013000',
            'type' => 'checking',
            'currency' => 'EUR',
            'balance' => 0,
        ]);

        $existing['account_id'] = $account->id;
        $existingTransaction = Transaction::factory()->create($existing);

        TransactionFingerprint::create([
            'user_id' => $user->id,
            'transaction_id' => $existingTransaction->id,
            'fingerprint' => $this->service->buildFingerprint(
                $this->service->normalizeRecord($existing)
            ),
        ]);

        $new['account_id'] = $account->id;
        $result = $this->service->isDuplicate($new, $user->id);

        $this->assertSame($expected, $result);
    }
}
