<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Str;

class GocardlessMapper
{
    /**
     * Initializes a new instance of the GocardlessMapper class.
     */
    public function __construct() {}

    /**
     * Maps GoCardless account data into a structured array for application use.
     *
     * @param array $data Raw GoCardless account data.
     * @return array Structured account data suitable for internal processing.
     */
    public function mapAccountData(array $data): array
    {
        return [
            'gocardless_account_id' => $data['id'] ?? null,
            'name' => $data['name'] ?? null,
            'bank_name' => $data['bank_name'] ?? null,
            'iban' => $data['iban'] ?? null,
            'type' => $data['type'] ?? null,
            'currency' => $data['currency'] ?? null,
            'balance' => $data['balance'] ?? 0.00,
            'is_gocardless_synced' => true,
            'gocardless_last_synced_at' => now(),
            'import_data' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * Retrieves a value from a nested array using dot notation, returning a default if the key is not found.
     *
     * @param array $array The array to search.
     * @param string $key The dot notation key (e.g., 'foo.bar.baz').
     * @param mixed $default The value to return if the key does not exist.
     * @return mixed The value found at the specified key, or the default value.
     */
    private function get(array $array, string $key, $default = null)
    {
        $keys = explode('.', $key);
        $value = $array;
        foreach ($keys as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Safely parses a date string into a Carbon instance.
     *
     * @param string|null $date The date string to parse.
     * @return Carbon|null The parsed date or null if invalid.
     */
    private function parseDate(?string $date): ?Carbon
    {
        if (empty($date)) {
            return null;
        }

        try {
            return Carbon::parse($date);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Extracts and formats the transaction description.
     *
     * @param array $transaction The transaction data.
     * @return string The formatted description.
     */
    private function formatDescription(array $transaction): string
    {
        $parts = [];

        // Add unstructured remittance information
        $unstructured = $this->get($transaction, 'remittanceInformationUnstructured');
        if ($unstructured) {
            $parts[] = $unstructured;
        }

        // Add structured remittance information
        $structured = $this->get($transaction, 'remittanceInformationStructured');
        if ($structured) {
            $parts[] = $structured;
        }

        // Add additional information
        $additional = $this->get($transaction, 'additionalInformation');
        if ($additional) {
            $parts[] = $additional;
        }

        return implode(' ', array_filter($parts));
    }

    /**
     * Extracts the partner name from the transaction data.
     *
     * @param array $transaction The transaction data.
     * @return string|null The partner name or null if not found.
     */
    private function extractPartnerName(array $transaction): ?string
    {
        // Try creditor name first
        $partner = $this->get($transaction, 'creditorName');
        if ($partner) {
            return $partner;
        }

        // Try debtor name
        $partner = $this->get($transaction, 'debtorName');
        if ($partner) {
            return $partner;
        }

        // Try remittance information
        $partner = $this->get($transaction, 'remittanceInformationUnstructuredArray.0');
        if ($partner) {
            return $partner;
        }

        return null;
    }

    /**
     * Maps a GoCardless transaction array and associated account into a structured array for internal use.
     *
     * @param array $transaction Raw transaction data from GoCardless.
     * @param Account $account The associated account model.
     * @return array Structured transaction data suitable for application processing.
     */
    public function mapTransactionData(array $transaction, Account $account): array
    {
        // Parse dates
        $bookedRaw = $this->get($transaction, 'bookingDateTime', $this->get($transaction, 'bookingDate'));
        $bookedDateTime = $this->parseDate($bookedRaw);

        $valueRaw = $this->get($transaction, 'valueDateTime', $this->get($transaction, 'valueDate', $bookedRaw));
        $valueDateTime = $this->parseDate($valueRaw);

        // Extract amount and currency
        $amount = $this->get($transaction, 'transactionAmount.amount', 0);
        $currency = $this->get($transaction, 'transactionAmount.currency', 'EUR');

        // Extract IBANs
        $sourceIban = $this->get($transaction, 'debtorAccount.iban');
        $targetIban = $this->get($transaction, 'creditorAccount.iban');

        // Extract partner name
        $partner = $this->extractPartnerName($transaction);

        // Format description
        $description = $this->formatDescription($transaction);

        // Determine transaction type
        $type = $this->determineTransactionType($transaction);

        // Extract metadata
        $metadata = $this->extractMetadata($transaction);

        return [
            'transaction_id' => $this->get($transaction, 'transactionId'),
            'account_id' => $account->id,
            'gocardless_account_id' => $account->gocardless_account_id,
            'is_gocardless_synced' => true,
            'gocardless_synced_at' => now(),

            'amount' => $amount,
            'currency' => $currency,
            'booked_date' => $bookedDateTime,
            'processed_date' => $valueDateTime,

            'source_iban' => $sourceIban,
            'target_iban' => $targetIban,
            'partner' => $partner,
            'description' => $description,
            'type' => $type,

            'balance_after_transaction' => $this->get($transaction, 'balanceAfterTransaction.balanceAmount.amount', 0),
            'metadata' => $metadata,
            'import_data' => $transaction,
        ];
    }

    /**
     * Determines the transaction type based on the transaction data.
     *
     * @param array $transaction The transaction data.
     * @return string The determined transaction type.
     */
    private function determineTransactionType(array $transaction): string
    {
        // Try to get the bank's proprietary code
        $bankCode = $this->get($transaction, 'proprietaryBankTransactionCode');
        if ($bankCode) {
            return $bankCode;
        }

        // Try to get the purpose code
        $purposeCode = $this->get($transaction, 'purposeCode');
        if ($purposeCode) {
            return $purposeCode;
        }

        // Try to determine from amount
        $amount = $this->get($transaction, 'transactionAmount.amount', 0);
        if ($amount > 0) {
            return Transaction::TYPE_DEPOSIT;
        }

        return Transaction::TYPE_PAYMENT;
    }

    /**
     * Extracts metadata from the transaction data.
     *
     * @param array $transaction The transaction data.
     * @return array The extracted metadata.
     */
    private function extractMetadata(array $transaction): array
    {
        $metadata = [];

        // Add merchant category code if available
        $mcc = $this->get($transaction, 'merchantCategoryCode');
        if ($mcc) {
            $metadata['merchant_category_code'] = $mcc;
        }

        // Add end-to-end ID if available
        $endToEndId = $this->get($transaction, 'endToEndId');
        if ($endToEndId) {
            $metadata['end_to_end_id'] = $endToEndId;
        }

        // Add mandate ID if available
        $mandateId = $this->get($transaction, 'mandateId');
        if ($mandateId) {
            $metadata['mandate_id'] = $mandateId;
        }

        // Add additional structured data
        $additionalData = $this->get($transaction, 'additionalDataStructured');
        if ($additionalData) {
            $metadata['additional_data'] = $additionalData;
        }

        return $metadata;
    }
}
