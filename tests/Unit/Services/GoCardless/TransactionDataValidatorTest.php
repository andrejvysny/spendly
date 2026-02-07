<?php

declare(strict_types=1);

namespace Tests\Unit\Services\GoCardless;

use App\Services\GoCardless\TransactionDataValidator;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('gocardless')]
class TransactionDataValidatorTest extends TestCase
{
    private TransactionDataValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new TransactionDataValidator;
    }

    public function test_missing_transaction_id_generates_fallback_and_flags_review(): void
    {
        $syncDate = Carbon::parse('2026-02-05');
        $mapped = [
            'transaction_id' => null,
            'amount' => 10.00,
            'currency' => 'EUR',
            'booked_date' => $syncDate,
            'description' => 'Test',
            'account_id' => 1,
        ];
        $result = $this->validator->validate($mapped, $syncDate);
        $this->assertFalse($result->hasErrors());
        $this->assertTrue($result->needsReview);
        $this->assertContains('generated_transaction_id', $result->reviewReasons);
        $this->assertStringStartsWith('fallback_', $result->data['transaction_id']);
    }

    public function test_missing_booked_date_uses_sync_date_and_flags_review(): void
    {
        $syncDate = Carbon::parse('2026-02-05');
        $mapped = [
            'transaction_id' => 'tx-1',
            'amount' => 10.00,
            'currency' => 'EUR',
            'booked_date' => null,
            'description' => 'Test',
            'account_id' => 1,
        ];
        $result = $this->validator->validate($mapped, $syncDate);
        $this->assertFalse($result->hasErrors());
        $this->assertTrue($result->needsReview);
        $this->assertContains('missing_booked_date', $result->reviewReasons);
        $this->assertEquals($syncDate, $result->data['booked_date']);
    }

    public function test_near_zero_amount_flags_review(): void
    {
        $syncDate = Carbon::parse('2026-02-05');
        $mapped = [
            'transaction_id' => 'tx-1',
            'amount' => 0.005,
            'currency' => 'EUR',
            'booked_date' => $syncDate,
            'description' => 'Test',
            'account_id' => 1,
        ];
        $result = $this->validator->validate($mapped, $syncDate);
        $this->assertFalse($result->hasErrors());
        $this->assertTrue($result->needsReview);
        $this->assertContains('near_zero_amount', $result->reviewReasons);
    }

    public function test_invalid_currency_defaults_to_eur_and_flags_review(): void
    {
        $syncDate = Carbon::parse('2026-02-05');
        $mapped = [
            'transaction_id' => 'tx-1',
            'amount' => 10.00,
            'currency' => 'XXX',
            'booked_date' => $syncDate,
            'description' => 'Test',
            'account_id' => 1,
        ];
        $result = $this->validator->validate($mapped, $syncDate);
        $this->assertFalse($result->hasErrors());
        $this->assertSame('EUR', $result->data['currency']);
        $this->assertContains('invalid_currency', $result->reviewReasons);
    }

    public function test_missing_amount_produces_error(): void
    {
        $syncDate = Carbon::parse('2026-02-05');
        $mapped = [
            'transaction_id' => 'tx-1',
            'amount' => null,
            'currency' => 'EUR',
            'booked_date' => $syncDate,
            'description' => 'Test',
            'account_id' => 1,
        ];
        $result = $this->validator->validate($mapped, $syncDate);
        $this->assertTrue($result->hasErrors());
        $this->assertContains('Amount is required', $result->errors);
    }

    public function test_valid_data_has_no_errors_and_no_review(): void
    {
        $syncDate = Carbon::parse('2026-02-05');
        $mapped = [
            'transaction_id' => 'tx-1',
            'amount' => 10.50,
            'currency' => 'EUR',
            'booked_date' => $syncDate,
            'processed_date' => $syncDate,
            'description' => 'Valid transaction',
            'account_id' => 1,
        ];
        $result = $this->validator->validate($mapped, $syncDate);
        $this->assertFalse($result->hasErrors());
        $this->assertFalse($result->needsReview);
        $this->assertSame('tx-1', $result->data['transaction_id']);
        $this->assertSame(10.50, $result->data['amount']);
    }

    public function test_future_date_flags_review(): void
    {
        $syncDate = Carbon::parse('2026-02-05');
        $futureDate = Carbon::parse('2027-01-01');
        $mapped = [
            'transaction_id' => 'tx-1',
            'amount' => 10.00,
            'currency' => 'EUR',
            'booked_date' => $futureDate,
            'description' => 'Test',
            'account_id' => 1,
        ];
        $result = $this->validator->validate($mapped, $syncDate);
        $this->assertFalse($result->hasErrors());
        $this->assertTrue($result->needsReview);
        $this->assertContains('future_date', $result->reviewReasons);
    }
}
