<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\DuplicateTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DuplicateTransactionServiceTest extends TestCase
{
    use RefreshDatabase;

    private DuplicateTransactionService $service;

    protected function setUp(): void
    {
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
            'exact duplicate same fingerprint' => [
                [
                    'booked_date' => '2024-01-01',
                    'processed_date' => '2024-01-01',
                    'amount' => 9.99,
                    'currency' => 'EUR',
                    'description' => 'Netflix EU',
                    'partner' => 'Netflix',
                    'transaction_id' => 'abc',
                    'type' => 'PAYMENT',
                ],
                [
                    'booked_date' => '2024-01-01',
                    'processed_date' => '2024-01-01',
                    'amount' => 9.99,
                    'currency' => 'EUR',
                    'description' => 'Netflix EU',
                    'partner' => 'Netflix',
                    'transaction_id' => 'abc',
                    'type' => 'PAYMENT',
                ],
                true,
            ],
            'recurring payment different date' => [
                [
                    'booked_date' => '2024-01-01',
                    'processed_date' => '2024-01-01',
                    'amount' => 20.00,
                    'currency' => 'EUR',
                    'description' => 'Gym',
                    'partner' => 'Gym',
                    'transaction_id' => 'r1',
                    'type' => 'PAYMENT',
                ],
                [
                    'booked_date' => '2024-01-10',
                    'processed_date' => '2024-01-10',
                    'amount' => 20.00,
                    'currency' => 'EUR',
                    'description' => 'Gym',
                    'partner' => 'Gym',
                    'transaction_id' => 'r2',
                    'type' => 'PAYMENT',
                ],
                false,
            ],
        ];
    }

    #[DataProvider('duplicateProvider')]
    public function test_duplicate_detection(array $existing, array $new, bool $expected): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);

        $existing['account_id'] = $account->id;
        $existing['fingerprint'] = Transaction::generateFingerprint($existing);
        Transaction::factory()->create($existing);

        $new['account_id'] = $account->id;
        if (($new['fingerprint'] ?? null) === null) {
            $new['fingerprint'] = Transaction::generateFingerprint($new);
        }
        $result = $this->service->isDuplicate($new, (int) $user->id);

        $this->assertSame($expected, $result);
    }

    public function test_same_amount_date_partner_with_row_disambiguated_fingerprint_not_duplicate(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);

        $baseData = [
            'account_id' => $account->id,
            'booked_date' => '2024-03-15',
            'processed_date' => '2024-03-15',
            'amount' => 30.00,
            'currency' => 'EUR',
            'description' => 'FIIT STU',
            'partner' => 'FIIT STU',
            'type' => 'PAYMENT',
        ];

        $baseFingerprint = Transaction::generateFingerprint($baseData);
        $fingerprintRow1 = hash('sha256', $baseFingerprint.'|1|1');
        $fingerprintRow2 = hash('sha256', $baseFingerprint.'|1|2');

        Transaction::factory()->create(array_merge($baseData, [
            'transaction_id' => 'IMP-row1',
            'fingerprint' => $fingerprintRow1,
        ]));

        $newRowData = array_merge($baseData, [
            'transaction_id' => 'IMP-row2',
            'fingerprint' => $fingerprintRow2,
        ]);
        $isDup = $this->service->isDuplicate($newRowData, (int) $user->id);

        $this->assertFalse($isDup, 'Second row with same amount/date/partner but row-disambiguated fingerprint must not be considered duplicate');
    }
}
