<?php

declare(strict_types=1);

namespace Tests\Unit\Services\GoCardless;

use App\Models\Transaction;
use App\Services\GoCardless\FieldExtractors\GenericFieldExtractor;
use Tests\Unit\UnitTestCase;

/**
 * The fallback extractor, used for every institution that is not Revolut or SLSP — i.e. very nearly
 * every real user of a self-hosted install. It had no tests at all, while the two bank-specific
 * extractors that cover far fewer users each had a dedicated suite (and those suites are themselves
 * fixture-gated, so they skip in CI).
 *
 * Deliberately fixture-free so this actually runs everywhere.
 */
class GenericFieldExtractorTest extends UnitTestCase
{
    private GenericFieldExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new GenericFieldExtractor;
    }

    // ── description ───────────────────────────────────────────────────────

    public function test_description_prefers_unstructured_remittance(): void
    {
        $this->assertSame('TESCO STORES 1234', $this->extractor->extractDescription([
            'remittanceInformationUnstructured' => 'TESCO STORES 1234',
            'creditorName' => 'Tesco',
        ]));
    }

    public function test_description_joins_the_remittance_array_when_there_is_no_scalar(): void
    {
        $this->assertSame('LINE ONE | LINE TWO', $this->extractor->extractDescription([
            'remittanceInformationUnstructuredArray' => ['LINE ONE', 'LINE TWO'],
        ]));
    }

    public function test_description_falls_back_through_creditor_then_debtor(): void
    {
        $this->assertSame('Creditor Ltd', $this->extractor->extractDescription(['creditorName' => 'Creditor Ltd']));
        $this->assertSame('Debtor Ltd', $this->extractor->extractDescription(['debtorName' => 'Debtor Ltd']));
    }

    public function test_description_falls_back_to_the_proprietary_code_then_a_placeholder(): void
    {
        $this->assertSame('CARD', $this->extractor->extractDescription(['proprietaryBankTransactionCode' => 'CARD']));
        $this->assertSame('Transaction', $this->extractor->extractDescription([]));
    }

    /**
     * An empty string is not a usable description, so it must not short-circuit the fallbacks.
     */
    public function test_empty_remittance_does_not_win_over_a_real_creditor(): void
    {
        $this->assertSame('Creditor Ltd', $this->extractor->extractDescription([
            'remittanceInformationUnstructured' => '',
            'creditorName' => 'Creditor Ltd',
        ]));
    }

    // ── partner ───────────────────────────────────────────────────────────

    /**
     * Direction decides which side is the counterparty: money out means the creditor is who was
     * paid; money in means the debtor is who paid. Getting this backwards mislabels every row.
     */
    public function test_partner_is_the_creditor_when_money_leaves(): void
    {
        $this->assertSame('Shop', $this->extractor->extractPartner([
            'transactionAmount' => ['amount' => '-12.34', 'currency' => 'EUR'],
            'creditorName' => 'Shop',
            'debtorName' => 'Me',
        ]));
    }

    public function test_partner_is_the_debtor_when_money_arrives(): void
    {
        $this->assertSame('Employer', $this->extractor->extractPartner([
            'transactionAmount' => ['amount' => '2500.00', 'currency' => 'EUR'],
            'creditorName' => 'Me',
            'debtorName' => 'Employer',
        ]));
    }

    /**
     * When the expected side is absent the other one is still better than nothing.
     */
    public function test_partner_falls_back_to_whichever_side_is_present(): void
    {
        $this->assertSame('Only Debtor', $this->extractor->extractPartner([
            'transactionAmount' => ['amount' => '-5.00', 'currency' => 'EUR'],
            'debtorName' => 'Only Debtor',
        ]));
    }

    public function test_partner_is_null_when_neither_side_is_named(): void
    {
        $this->assertNull($this->extractor->extractPartner([
            'transactionAmount' => ['amount' => '-5.00', 'currency' => 'EUR'],
        ]));
    }

    /**
     * A malformed amount must not throw here — the validator is what rejects the row.
     */
    public function test_partner_survives_an_unreadable_amount(): void
    {
        $this->assertSame('Someone', $this->extractor->extractPartner([
            'transactionAmount' => ['amount' => 'INVALID', 'currency' => 'EUR'],
            'debtorName' => 'Someone',
        ]));
    }

    // ── merchant category code ────────────────────────────────────────────

    public function test_mcc_is_read_from_the_dedicated_field(): void
    {
        $this->assertSame('5411', $this->extractor->extractMerchantCategoryCode(['merchantCategoryCode' => '5411']));
    }

    public function test_mcc_is_recovered_from_an_mcc_shaped_remittance(): void
    {
        $this->assertSame('5812', $this->extractor->extractMerchantCategoryCode([
            'remittanceInformationUnstructured' => 'MCC-5812',
        ]));
    }

    public function test_ordinary_remittance_is_not_mistaken_for_an_mcc(): void
    {
        $this->assertNull($this->extractor->extractMerchantCategoryCode([
            'remittanceInformationUnstructured' => 'MCC-58',
        ]));
        $this->assertNull($this->extractor->extractMerchantCategoryCode([
            'remittanceInformationUnstructured' => 'PAYMENT MCC-5812 EXTRA',
        ]));
    }

    // ── currency exchange ─────────────────────────────────────────────────

    public function test_currency_exchange_is_passed_through_when_present(): void
    {
        $exchange = ['sourceCurrency' => 'USD', 'exchangeRate' => '0.92'];

        $this->assertSame($exchange, $this->extractor->extractCurrencyExchange([
            'currencyExchange' => $exchange,
        ]));
    }

    public function test_currency_exchange_is_null_when_absent_or_empty(): void
    {
        $this->assertNull($this->extractor->extractCurrencyExchange([]));
        $this->assertNull($this->extractor->extractCurrencyExchange(['currencyExchange' => []]));
    }

    // ── type ──────────────────────────────────────────────────────────────

    public function test_type_is_derived_from_the_direction_of_the_amount(): void
    {
        $this->assertSame(Transaction::TYPE_DEPOSIT, $this->extractor->extractTransactionType([], 10.0));
        $this->assertSame(Transaction::TYPE_PAYMENT, $this->extractor->extractTransactionType([], -10.0));
    }

    /**
     * Zero is not income. It falls to PAYMENT, and the validator separately flags it for review.
     */
    public function test_zero_amount_is_not_treated_as_a_deposit(): void
    {
        $this->assertSame(Transaction::TYPE_PAYMENT, $this->extractor->extractTransactionType([], 0.0));
    }

    /**
     * TRANSFER is owned by GocardlessMapper, which only assigns it once the counterparty IBAN is
     * confirmed to be one of the user's own accounts. The extractor must never guess it from a
     * bank's proprietary code.
     */
    public function test_type_is_never_guessed_as_transfer_from_a_bank_code(): void
    {
        $this->assertSame(Transaction::TYPE_PAYMENT, $this->extractor->extractTransactionType(
            ['proprietaryBankTransactionCode' => 'TRANSFER'],
            -50.0,
        ));
    }

    // ── metadata ──────────────────────────────────────────────────────────

    public function test_metadata_collects_the_identifiers_that_are_present(): void
    {
        $metadata = $this->extractor->extractMetadata([
            'internalTransactionId' => 'int-1',
            'entryReference' => 'ref-1',
            'bankTransactionCode' => 'PMNT-RCDT-ESCT',
            'proprietaryBankTransactionCode' => 'SEPA',
            'merchantCategoryCode' => '5411',
            'endToEndId' => 'e2e-1',
        ]);

        $this->assertSame([
            'internalTransactionId' => 'int-1',
            'entryReference' => 'ref-1',
            'bankTransactionCode' => 'PMNT-RCDT-ESCT',
            'proprietaryBankTransactionCode' => 'SEPA',
            'mcc' => '5411',
            'end_to_end_id' => 'e2e-1',
        ], $metadata);
    }

    public function test_metadata_omits_absent_and_empty_identifiers(): void
    {
        $this->assertSame([], $this->extractor->extractMetadata([
            'internalTransactionId' => '',
            'entryReference' => null,
        ]));
    }

    /**
     * Nested lookups must not fatal when the payload shape is not what the dotted path expects.
     */
    public function test_malformed_nested_payload_does_not_fatal(): void
    {
        $this->assertNull($this->extractor->extractPartner(['transactionAmount' => 'not-an-array']));
        $this->assertSame('Transaction', $this->extractor->extractDescription(['remittanceInformationUnstructuredArray' => 'not-an-array']));
    }
}
