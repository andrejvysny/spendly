<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Transaction;
use Carbon\Carbon;

class GocardlessMapper
{
    public function __construct() {}

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
            'gocardless_account_data' => json_encode($data),
        ];
    }

    /**
     * Safely get value from array using dot notation
     *
     * @param  mixed  $default
     * @return mixed
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
