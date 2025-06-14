<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Transaction;
use Carbon\Carbon;

class GocardlessMapper
{
    /****
 * Initializes a new instance of the GocardlessMapper class.
 */
    public function __construct() {}

    /**
     * Maps GoCardless account data into a structured array for application use.
     *
     * Converts the provided account data array into a normalized format with standard keys, default values for missing fields, and includes a JSON-encoded snapshot of the original data.
     *
     * @param  array  $data  Raw GoCardless account data.
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
            'import_data' => json_encode($data),
        ];
    }

    /**
     * Retrieves a value from a nested array using dot notation, returning a default if the key is not found.
     *
     * @param  array  $array  The array to search.
     * @param  string  $key  The dot notation key (e.g., 'foo.bar.baz').
     * @param  mixed  $default  The value to return if the key does not exist.
     * @return mixed The value found at the specified key, or the default value.
     */
    private function get(array $array, string $key, $default = null)
    {
        $keys = explode('.', $key);
        $value = $array;
        foreach ($keys as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Maps a GoCardless transaction array and associated account into a structured array for internal use.
     *
     * Extracts and normalizes transaction details such as account identifiers, IBANs, amounts, currency, booking and processing dates, partner information, description, transaction type, balance after transaction, metadata, and includes the original transaction data.
     *
     * @param  array  $transaction  Raw transaction data from GoCardless.
     * @param  Account  $account  The associated account model.
     * @return array Structured transaction data suitable for application processing.
     */
    public function mapTransactionData(array $transaction, Account $account): array
    {
        $bookedDateTime = Carbon::parse($this->get($transaction, 'bookingDateTime', $this->get($transaction, 'bookingDate')));
        $valueDateTime = Carbon::parse($this->get($transaction, 'valueDateTime', $this->get($transaction, 'valueDate', $this->get($transaction, 'bookingDate'))));

        $mapped = [
            'account_id' => $account->id,

            'gocardless_account_id' => $account->gocardless_account_id,
            'is_gocardless_synced' => true,
            'gocardless_synced_at' => now(),

            'source_iban' => $this->get($transaction, 'debtorAccount.iban'),
            'target_iban' => $this->get($transaction, 'creditorAccount.iban'),

            'amount' => $this->get($transaction, 'transactionAmount.amount', 0),
            'currency' => $this->get($transaction, 'transactionAmount.currency', 'EUR'),
            'booked_date' => $bookedDateTime,
            'processed_date' => $valueDateTime,
            'partner' => $this->get($transaction, 'creditorName') ??
                        $this->get($transaction, 'debtorName') ??
                        $this->get($transaction, 'remittanceInformationUnstructuredArray.0'),
            'description' => implode(' ', $this->get($transaction, 'remittanceInformationUnstructuredArray', [])).
                ($this->get($transaction, 'remittanceInformationUnstructured') ? ', '.$this->get($transaction, 'remittanceInformationUnstructured') : ''),
            'type' => $this->get($transaction, 'proprietaryBankTransactionCode', Transaction::TYPE_PAYMENT),

            'balance_after_transaction' => $this->get($transaction, 'balanceAfterTransaction.balanceAmount.amount', 0),
            'metadata' => $this->get($transaction, 'additionalDataStructured'),
            'import_data' => $transaction,
        ];

        return $mapped;
    }
}
