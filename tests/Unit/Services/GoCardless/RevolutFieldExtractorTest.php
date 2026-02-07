<?php

declare(strict_types=1);

namespace Tests\Unit\Services\GoCardless;

use App\Models\Transaction;
use App\Services\GoCardless\FieldExtractors\RevolutFieldExtractor;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Fixtures\GoCardlessFixtureLoader;
use Tests\TestCase;

#[Group('gocardless')]
#[Group('fixtures')]
class RevolutFieldExtractorTest extends TestCase
{
    private RevolutFieldExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new RevolutFieldExtractor;
        if (! GoCardlessFixtureLoader::fixturesAvailable()) {
            $this->markTestSkipped('GoCardless fixture directory not available.');
        }
    }

    public function test_extract_description_joins_remittance_information_unstructured_array(): void
    {
        $transactions = GoCardlessFixtureLoader::loadRevolutTransactions();
        $withArray = array_filter($transactions, static fn (array $tx): bool => isset($tx['remittanceInformationUnstructuredArray']));
        $this->assertNotEmpty($withArray);

        $tx = array_values($withArray)[0];
        $description = $this->extractor->extractDescription($tx);
        $this->assertNotEmpty($description);
        $arr = $tx['remittanceInformationUnstructuredArray'];
        $expected = implode(' | ', array_map('strval', $arr));
        $this->assertSame($expected, $description);
    }

    public function test_extract_description_falls_back_to_creditor_name_when_present(): void
    {
        $transactions = GoCardlessFixtureLoader::loadRevolutTransactions();
        $withCreditor = array_filter($transactions, static fn (array $tx): bool => ! empty($tx['creditorName']));
        $this->assertNotEmpty($withCreditor);

        $tx = array_values($withCreditor)[0];
        $description = $this->extractor->extractDescription($tx);
        $this->assertNotEmpty($description);
        if (isset($tx['remittanceInformationUnstructuredArray']) && $tx['remittanceInformationUnstructuredArray'] !== []) {
            $this->assertStringContainsString(' | ', $description);
        } else {
            $this->assertSame($tx['creditorName'], $description);
        }
    }

    public function test_extract_partner_returns_creditor_for_outgoing(): void
    {
        $tx = [
            'transactionAmount' => ['amount' => '-6.49', 'currency' => 'EUR'],
            'creditorName' => 'Billa 127',
            'remittanceInformationUnstructuredArray' => ['Billa 127'],
            'proprietaryBankTransactionCode' => 'CARD_PAYMENT',
        ];
        $partner = $this->extractor->extractPartner($tx);
        $this->assertSame('Billa 127', $partner);
    }

    public function test_extract_partner_from_array_for_card_payment_single_element(): void
    {
        $tx = [
            'transactionAmount' => ['amount' => '-1.00', 'currency' => 'EUR'],
            'remittanceInformationUnstructuredArray' => ['Some Merchant'],
            'proprietaryBankTransactionCode' => 'CARD_PAYMENT',
        ];
        $partner = $this->extractor->extractPartner($tx);
        $this->assertSame('Some Merchant', $partner);
    }

    public function test_extract_currency_exchange_returns_exchange_object_when_present(): void
    {
        $transactions = GoCardlessFixtureLoader::loadRevolutTransactions();
        $withExchange = array_filter($transactions, static fn (array $tx): bool => isset($tx['currencyExchange']));
        if ($withExchange === []) {
            $this->markTestSkipped('No Revolut fixture with currencyExchange');
        }
        $tx = array_values($withExchange)[0];
        $exchange = $this->extractor->extractCurrencyExchange($tx);
        $this->assertIsArray($exchange);
        $this->assertArrayHasKey('sourceCurrency', $exchange);
        $this->assertArrayHasKey('targetCurrency', $exchange);
    }

    public function test_extract_currency_exchange_returns_null_when_absent(): void
    {
        $tx = [
            'transactionAmount' => ['amount' => '-6.49', 'currency' => 'EUR'],
            'creditorName' => 'Billa 127',
        ];
        $this->assertNull($this->extractor->extractCurrencyExchange($tx));
    }

    public function test_extract_transaction_type_maps_transfer(): void
    {
        $tx = [
            'transactionAmount' => ['amount' => '-0.06', 'currency' => 'EUR'],
            'proprietaryBankTransactionCode' => 'TRANSFER',
        ];
        $type = $this->extractor->extractTransactionType($tx, -0.06);
        $this->assertSame(Transaction::TYPE_TRANSFER, $type);
    }

    public function test_extract_transaction_type_maps_card_payment(): void
    {
        $tx = [
            'transactionAmount' => ['amount' => '-6.49', 'currency' => 'EUR'],
            'proprietaryBankTransactionCode' => 'CARD_PAYMENT',
        ];
        $type = $this->extractor->extractTransactionType($tx, -6.49);
        $this->assertSame(Transaction::TYPE_CARD_PAYMENT, $type);
    }

    public function test_extract_transaction_type_maps_deposit_for_positive_amount(): void
    {
        $tx = [
            'transactionAmount' => ['amount' => '100.00', 'currency' => 'EUR'],
            'proprietaryBankTransactionCode' => 'TOPUP',
        ];
        $type = $this->extractor->extractTransactionType($tx, 100.0);
        $this->assertSame(Transaction::TYPE_DEPOSIT, $type);
    }
}
