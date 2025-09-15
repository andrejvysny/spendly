<?php

namespace Tests\Unit\Services\TransactionImport;

use App\Services\TransactionImport\TransactionValidator;
use Tests\Unit\UnitTestCase;

class TransactionValidatorTest extends UnitTestCase
{
    private TransactionValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new TransactionValidator;
    }

    public function test_validate_valid_transaction(): void
    {
        $data = [
            'booked_date' => '2023-12-25 00:00:00',
            'amount' => 100.50,
            'partner' => 'John Doe',
            'description' => 'Payment',
            'account_id' => 123,
            'currency' => 'USD',
            'type' => 'PAYMENT',
        ];

        $result = $this->validator->validate($data);

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());
    }

    public function test_validate_missing_required_fields(): void
    {
        $data = [
            'amount' => 100.50,
            'currency' => 'USD',
        ];

        $result = $this->validator->validate($data);

        $this->assertFalse($result->isValid());
        $errors = $result->getErrors();
        $this->assertContains('Booked date is required', $errors);
        $this->assertContains('Transaction type is required', $errors);
        $this->assertContains('Account ID is required', $errors);
    }

    public function test_validate_invalid_amount(): void
    {
        $data = [
            'booked_date' => '2023-12-25 00:00:00',
            'amount' => 'not-a-number',
            'partner' => 'John Doe',
            'description' => 'Payment',
            'account_id' => 123,
            'currency' => 'USD',
            'type' => 'PAYMENT',
        ];

        $result = $this->validator->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertContains('Amount must be a number', $result->getErrors());
    }

    public function test_validate_zero_amount(): void
    {
        $data = [
            'booked_date' => '2023-12-25 00:00:00',
            'amount' => 0,
            'partner' => 'John Doe',
            'description' => 'Payment',
            'account_id' => 123,
            'currency' => 'USD',
            'type' => 'PAYMENT',
        ];

        $result = $this->validator->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertContains('Amount cannot be zero', $result->getErrors());
    }

    public function test_validate_invalid_date_format(): void
    {
        $data = [
            'booked_date' => 'invalid-date', // Truly invalid date
            'processed_date' => 'invalid-date',
            'amount' => 100.50,
            'partner' => 'John Doe',
            'description' => 'Payment',
            'account_id' => 123,
            'currency' => 'USD',
            'type' => 'PAYMENT',
        ];

        $result = $this->validator->validate($data);

        $this->assertFalse($result->isValid());
        $errors = $result->getErrors();
        $this->assertContains('Invalid booked date format', $errors);
        $this->assertContains('Invalid processed date format', $errors);
    }

    public function test_validate_invalid_currency(): void
    {
        $data = [
            'booked_date' => '2023-12-25 00:00:00',
            'amount' => 100.50,
            'partner' => 'John Doe',
            'description' => 'Payment',
            'account_id' => 123,
            'currency' => 'US', // Should be 3 letters
            'type' => 'PAYMENT',
        ];

        $result = $this->validator->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertContains('Invalid currency code', $result->getErrors());
    }

    public function test_validate_invalid_iban(): void
    {
        $data = [
            'booked_date' => '2023-12-25 00:00:00',
            'amount' => 100.50,
            'partner' => 'John Doe',
            'description' => 'Payment',
            'account_id' => 123,
            'currency' => 'EUR',
            'source_iban' => 'INVALID-IBAN',
            'target_iban' => 'GB',
            'type' => 'PAYMENT',
        ];

        $result = $this->validator->validate($data);

        $this->assertFalse($result->isValid());
        $errors = $result->getErrors();
        $this->assertContains('Invalid source IBAN format', $errors);
        $this->assertContains('Invalid target IBAN format', $errors);
    }

    public function test_validate_valid_iban(): void
    {
        $data = [
            'booked_date' => '2023-12-25 00:00:00',
            'amount' => 100.50,
            'partner' => 'John Doe',
            'description' => 'Payment',
            'account_id' => 123,
            'currency' => 'EUR',
            'source_iban' => 'GB82WEST12345698765432',
            'target_iban' => 'DE89370400440532013000',
            'type' => 'PAYMENT',
        ];

        $result = $this->validator->validate($data);

        $this->assertTrue($result->isValid());
    }

    public function test_validate_long_description(): void
    {
        $data = [
            'booked_date' => '2023-12-25 00:00:00',
            'amount' => 100.50,
            'partner' => 'John Doe',
            'description' => str_repeat('a', 1001), // 1001 characters
            'account_id' => 123,
            'currency' => 'USD',
            'type' => 'PAYMENT',
        ];

        $result = $this->validator->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertContains('Description is too long (max 1000 characters)', $result->getErrors());
    }

    public function test_validate_long_partner_name(): void
    {
        $data = [
            'booked_date' => '2023-12-25 00:00:00',
            'amount' => 100.50,
            'partner' => str_repeat('a', 256), // 256 characters
            'description' => 'Payment',
            'account_id' => 123,
            'currency' => 'USD',
            'type' => 'PAYMENT',
        ];

        $result = $this->validator->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertContains('Partner name is too long (max 255 characters)', $result->getErrors());
    }

    public function test_validate_preview_mode_without_account_id(): void
    {
        $data = [
            'booked_date' => '2023-12-25 00:00:00',
            'amount' => 100.50,
            'partner' => 'John Doe',
            'description' => 'Payment',
            'currency' => 'USD',
            'type' => 'PAYMENT',
            // No account_id
        ];

        $configuration = ['preview_mode' => true];

        $result = $this->validator->validate($data, $configuration);

        $this->assertFalse($result->isValid()); // Account ID is still required even in preview mode
        $this->assertContains('Account ID is required', $result->getErrors());
    }

    public function test_validate_preview_mode_with_account_id(): void
    {
        $data = [
            'booked_date' => '2023-12-25 00:00:00',
            'amount' => 100.50,
            'partner' => 'John Doe',
            'description' => 'Payment',
            'currency' => 'USD',
            'account_id' => 123,
            'type' => 'PAYMENT',
        ];

        $configuration = ['preview_mode' => true];

        $result = $this->validator->validate($data, $configuration);

        $this->assertTrue($result->isValid());
    }

    public function test_validate_multiple_errors(): void
    {
        $data = [
            'amount' => 'not-a-number',
            'currency' => 'US',
            'description' => str_repeat('a', 1001),
        ];

        $result = $this->validator->validate($data);

        $this->assertFalse($result->isValid());
        $errors = $result->getErrors();
        $this->assertGreaterThan(4, count($errors)); // Should have multiple errors
    }

    public function test_validate_empty_values(): void
    {
        $data = [
            'booked_date' => '',
            'amount' => '',
            'partner' => '',
            'description' => '',
            'account_id' => '',
            'currency' => '',
        ];

        $result = $this->validator->validate($data);

        $this->assertFalse($result->isValid());
        $errors = $result->getErrors();
        $this->assertGreaterThanOrEqual(6, count($errors)); // At least 6 errors for required fields
    }

    public function test_validate_lowercase_currency_code(): void
    {
        $data = [
            'booked_date' => '2023-12-25 00:00:00',
            'amount' => 100.50,
            'partner' => 'John Doe',
            'description' => 'Payment',
            'account_id' => 123,
            'currency' => 'usd', // lowercase
            'type' => 'PAYMENT',
        ];

        $result = $this->validator->validate($data);

        $this->assertTrue($result->isValid());
    }

    public function test_validate_lowercase_currency_code_invalid(): void
    {
        $data = [
            'booked_date' => '2023-12-25 00:00:00',
            'amount' => 100.50,
            'partner' => 'John Doe',
            'description' => 'Payment',
            'account_id' => 123,
            'currency' => 'feay', // lowercase
            'type' => 'PAYMENT',
        ];

        $result = $this->validator->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertContains('Invalid currency code', $result->getErrors());
    }
}
