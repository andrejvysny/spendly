<?php

declare(strict_types=1);

namespace Tests\Unit\Services\GoCardless;

use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Fixtures\GoCardlessFixtureLoader;
use Tests\TestCase;

#[Group('gocardless')]
#[Group('fixtures')]
class FixtureShapeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! GoCardlessFixtureLoader::fixturesAvailable()) {
            $this->markTestSkipped('GoCardless fixture directory gocardless_bank_account_data/ not available.');
        }
    }

    public function test_revolut_transactions_have_required_shape(): void
    {
        $transactions = GoCardlessFixtureLoader::loadRevolutTransactions();
        $this->assertNotEmpty($transactions, 'Revolut fixture should contain at least one transaction');

        foreach ($transactions as $tx) {
            $this->assertArrayHasKey('transactionId', $tx, 'Revolut transaction must have transactionId');
            $this->assertArrayHasKey('transactionAmount', $tx, 'Revolut transaction must have transactionAmount');
            $this->assertIsArray($tx['transactionAmount'], 'transactionAmount must be array');
            $this->assertArrayHasKey('amount', $tx['transactionAmount'], 'transactionAmount must have amount');
            $this->assertArrayHasKey('currency', $tx['transactionAmount'], 'transactionAmount must have currency');
            $this->assertTrue(
                array_key_exists('bookingDate', $tx) || array_key_exists('bookingDateTime', $tx),
                'Revolut transaction must have bookingDate or bookingDateTime'
            );
        }
    }

    public function test_revolut_transactions_frequently_have_remittance_information_unstructured_array(): void
    {
        $transactions = GoCardlessFixtureLoader::loadRevolutTransactions();
        $this->assertNotEmpty($transactions);

        $withArray = array_filter($transactions, static fn (array $tx): bool => isset($tx['remittanceInformationUnstructuredArray']));
        $this->assertGreaterThan(
            0,
            count($withArray),
            'At least some Revolut transactions should have remittanceInformationUnstructuredArray'
        );
        foreach ($withArray as $tx) {
            $this->assertIsArray($tx['remittanceInformationUnstructuredArray']);
        }
    }

    public function test_revolut_currency_exchange_when_present_is_object(): void
    {
        $transactions = GoCardlessFixtureLoader::loadRevolutTransactions();
        $withExchange = array_filter($transactions, static fn (array $tx): bool => isset($tx['currencyExchange']));

        foreach ($withExchange as $tx) {
            $this->assertIsArray($tx['currencyExchange'], 'currencyExchange must be object/array');
            $this->assertArrayHasKey('sourceCurrency', $tx['currencyExchange']);
            $this->assertArrayHasKey('targetCurrency', $tx['currencyExchange']);
            $this->assertArrayHasKey('exchangeRate', $tx['currencyExchange']);
        }
    }

    public function test_slsp_transactions_have_required_shape(): void
    {
        $transactions = GoCardlessFixtureLoader::loadSlspTransactions();
        $this->assertNotEmpty($transactions, 'SLSP fixture should contain at least one transaction');

        foreach ($transactions as $tx) {
            $this->assertArrayHasKey('transactionId', $tx);
            $this->assertArrayHasKey('transactionAmount', $tx);
            $this->assertIsArray($tx['transactionAmount']);
            $this->assertArrayHasKey('amount', $tx['transactionAmount']);
            $this->assertArrayHasKey('currency', $tx['transactionAmount']);
            $this->assertTrue(
                array_key_exists('bookingDate', $tx) || array_key_exists('bookingDateTime', $tx),
                'SLSP transaction must have bookingDate or bookingDateTime'
            );
        }
    }

    public function test_slsp_transactions_have_remittance_information_unstructured_as_string_or_absent(): void
    {
        $transactions = GoCardlessFixtureLoader::loadSlspTransactions();
        $this->assertNotEmpty($transactions);

        foreach ($transactions as $tx) {
            if (array_key_exists('remittanceInformationUnstructured', $tx)) {
                $this->assertIsString($tx['remittanceInformationUnstructured']);
            }
        }
    }

    public function test_slsp_transactions_frequently_have_bank_transaction_code(): void
    {
        $transactions = GoCardlessFixtureLoader::loadSlspTransactions();
        $this->assertNotEmpty($transactions);

        $withCode = array_filter($transactions, static fn (array $tx): bool => isset($tx['bankTransactionCode']));
        $this->assertGreaterThan(0, count($withCode), 'At least some SLSP transactions should have bankTransactionCode');
    }

    public function test_slsp_mcc_pattern_occurrences(): void
    {
        $transactions = GoCardlessFixtureLoader::loadSlspTransactions();
        $mccCount = 0;
        foreach ($transactions as $tx) {
            $rem = $tx['remittanceInformationUnstructured'] ?? null;
            if (is_string($rem) && preg_match('/^MCC-\d{4}$/', $rem)) {
                $mccCount++;
            }
        }
        $this->assertGreaterThan(0, $mccCount, 'At least one SLSP transaction should have MCC pattern in remittance');
    }

    public function test_revolut_details_shape(): void
    {
        $details = GoCardlessFixtureLoader::loadRevolutDetails('LT683250013083708433');
        if ($details === []) {
            $this->markTestSkipped('Revolut details fixture not found');
        }
        $this->assertArrayHasKey('iban', $details);
        $this->assertArrayHasKey('currency', $details);
    }

    public function test_slsp_details_shape(): void
    {
        $details = GoCardlessFixtureLoader::loadSlspDetails('SK6809000000005183172536');
        if ($details === []) {
            $this->markTestSkipped('SLSP details fixture not found');
        }
        $this->assertArrayHasKey('iban', $details);
        $this->assertArrayHasKey('currency', $details);
        $this->assertArrayHasKey('bic', $details);
    }
}
