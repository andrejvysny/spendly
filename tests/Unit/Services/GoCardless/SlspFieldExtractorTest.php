<?php

declare(strict_types=1);

namespace Tests\Unit\Services\GoCardless;

use App\Models\Transaction;
use App\Services\GoCardless\FieldExtractors\SlspFieldExtractor;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Fixtures\GoCardlessFixtureLoader;
use Tests\TestCase;

#[Group('gocardless')]
#[Group('fixtures')]
class SlspFieldExtractorTest extends TestCase
{
    private SlspFieldExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new SlspFieldExtractor;
        if (! GoCardlessFixtureLoader::fixturesAvailable()) {
            $this->markTestSkipped('GoCardless fixture directory not available.');
        }
    }

    public function test_extract_description_uses_remittance_unstructured_when_not_mcc(): void
    {
        $tx = [
            'remittanceInformationUnstructured' => 'Monthly Budget',
            'creditorName' => 'Someone',
            'debtorName' => 'Vyšný Andrej',
        ];
        $description = $this->extractor->extractDescription($tx);
        $this->assertSame('Monthly Budget', $description);
    }

    public function test_extract_description_uses_creditor_when_remittance_is_mcc(): void
    {
        $tx = [
            'remittanceInformationUnstructured' => 'MCC-4899',
            'creditorName' => 'NETFLIX INTERNATIONAL B.V',
        ];
        $description = $this->extractor->extractDescription($tx);
        $this->assertSame('NETFLIX INTERNATIONAL B.V', $description);
    }

    public function test_extract_merchant_category_code_extracts_mcc_from_remittance(): void
    {
        $tx = ['remittanceInformationUnstructured' => 'MCC-4899'];
        $mcc = $this->extractor->extractMerchantCategoryCode($tx);
        $this->assertSame('4899', $mcc);
    }

    public function test_extract_merchant_category_code_returns_null_when_no_mcc_pattern(): void
    {
        $tx = ['remittanceInformationUnstructured' => 'Monthly Budget'];
        $this->assertNull($this->extractor->extractMerchantCategoryCode($tx));
    }

    public function test_extract_partner_returns_creditor_for_negative_amount(): void
    {
        $tx = [
            'transactionAmount' => ['amount' => '-13.99', 'currency' => 'EUR'],
            'creditorName' => 'NETFLIX INTERNATIONAL B.V',
        ];
        $partner = $this->extractor->extractPartner($tx);
        $this->assertSame('NETFLIX INTERNATIONAL B.V', $partner);
    }

    public function test_extract_partner_returns_debtor_for_positive_amount(): void
    {
        $tx = [
            'transactionAmount' => ['amount' => '150.00', 'currency' => 'EUR'],
            'debtorName' => 'Lejková Rebeka',
        ];
        $partner = $this->extractor->extractPartner($tx);
        $this->assertSame('Lejková Rebeka', $partner);
    }

    public function test_extract_transaction_type_maps_pospayment_to_card_payment(): void
    {
        $tx = [
            'transactionAmount' => ['amount' => '-13.99', 'currency' => 'EUR'],
            'bankTransactionCode' => 'PMNT-MCRD-POSP',
            'proprietaryBankTransactionCode' => 'POSPAYMENT',
        ];
        $type = $this->extractor->extractTransactionType($tx, -13.99);
        $this->assertSame(Transaction::TYPE_CARD_PAYMENT, $type);
    }

    public function test_extract_transaction_type_maps_manual_positive_to_deposit(): void
    {
        $tx = [
            'transactionAmount' => ['amount' => '150.00', 'currency' => 'EUR'],
            'proprietaryBankTransactionCode' => 'MANUAL',
        ];
        $type = $this->extractor->extractTransactionType($tx, 150.0);
        $this->assertSame(Transaction::TYPE_DEPOSIT, $type);
    }

    public function test_extract_transaction_type_maps_standing_order_to_payment(): void
    {
        // STANDINGORDER no longer maps to TRANSFER; type is set in GocardlessMapper only when counterparty is own account
        $tx = [
            'transactionAmount' => ['amount' => '-150.00', 'currency' => 'EUR'],
            'proprietaryBankTransactionCode' => 'STANDINGORDER',
            'creditorName' => 'Finax',
        ];
        $type = $this->extractor->extractTransactionType($tx, -150.0);
        $this->assertSame(Transaction::TYPE_PAYMENT, $type);
    }

    public function test_slsp_fixture_transactions_extract_correctly(): void
    {
        $transactions = GoCardlessFixtureLoader::loadSlspTransactions();
        $this->assertNotEmpty($transactions);

        $mccTx = null;
        foreach ($transactions as $tx) {
            if (isset($tx['remittanceInformationUnstructured']) && preg_match('/^MCC-\d{4}$/', $tx['remittanceInformationUnstructured'])) {
                $mccTx = $tx;
                break;
            }
        }
        $this->assertNotNull($mccTx);
        $mcc = $this->extractor->extractMerchantCategoryCode($mccTx);
        $this->assertNotEmpty($mcc);
        $this->assertMatchesRegularExpression('/^\d{4}$/', $mcc);
    }
}
