<?php

declare(strict_types=1);

namespace Tests\Unit\Services\GoCardless;

use App\Services\GoCardless\TransactionDataValidator;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_uncommon_but_well_formed_currency_is_kept_verbatim_and_flags_review(): void
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
        // Currency must NEVER be relabeled - relabeling without converting the amount
        // silently corrupts the transaction's monetary value.
        $this->assertFalse($result->hasErrors());
        $this->assertSame('XXX', $result->data['currency']);
        $this->assertContains('uncommon_currency', $result->reviewReasons);
        $this->assertContains('Uncommon currency', $result->warnings);
    }

    public function test_iso_currency_not_in_common_list_is_kept_and_flagged(): void
    {
        $syncDate = Carbon::parse('2026-02-05');
        $mapped = [
            'transaction_id' => 'tx-1',
            'amount' => 10.00,
            'currency' => 'ISK',
            'booked_date' => $syncDate,
            'description' => 'Test',
            'account_id' => 1,
        ];
        $result = $this->validator->validate($mapped, $syncDate);
        $this->assertFalse($result->hasErrors());
        $this->assertSame('ISK', $result->data['currency']);
        $this->assertTrue($result->needsReview);
        $this->assertContains('uncommon_currency', $result->reviewReasons);
    }

    public function test_lowercase_currency_is_normalized_to_uppercase(): void
    {
        $syncDate = Carbon::parse('2026-02-05');
        $mapped = [
            'transaction_id' => 'tx-1',
            'amount' => 10.00,
            'currency' => 'usd',
            'booked_date' => $syncDate,
            'description' => 'Test',
            'account_id' => 1,
        ];
        $result = $this->validator->validate($mapped, $syncDate);
        $this->assertFalse($result->hasErrors());
        $this->assertSame('USD', $result->data['currency']);
        $this->assertNotContains('uncommon_currency', $result->reviewReasons);
    }

    public static function malformedCurrencyProvider(): array
    {
        return [
            'four letter code' => ['EURO'],
            'currency symbol' => ['€'],
            'numeric' => ['12'],
            'too short' => ['eu'],
        ];
    }

    #[DataProvider('malformedCurrencyProvider')]
    public function test_malformed_currency_produces_error_and_is_not_relabeled(string $currency): void
    {
        $syncDate = Carbon::parse('2026-02-05');
        $mapped = [
            'transaction_id' => 'tx-1',
            'amount' => 10.00,
            'currency' => $currency,
            'booked_date' => $syncDate,
            'description' => 'Test',
            'account_id' => 1,
        ];
        $result = $this->validator->validate($mapped, $syncDate);
        $this->assertTrue($result->hasErrors());
        $this->assertContains('Currency is not a valid ISO 4217 code', $result->errors);
        // Must never be force-relabeled to EUR.
        $this->assertNotSame('EUR', $result->data['currency']);
    }

    public function test_missing_currency_defaults_to_eur_and_flags_review(): void
    {
        $syncDate = Carbon::parse('2026-02-05');
        $mapped = [
            'transaction_id' => 'tx-1',
            'amount' => 10.00,
            'currency' => null,
            'booked_date' => $syncDate,
            'description' => 'Test',
            'account_id' => 1,
        ];
        $result = $this->validator->validate($mapped, $syncDate);
        $this->assertFalse($result->hasErrors());
        $this->assertSame('EUR', $result->data['currency']);
        $this->assertContains('missing_currency', $result->reviewReasons);
        $this->assertContains('Missing currency, defaulting to EUR', $result->warnings);
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
