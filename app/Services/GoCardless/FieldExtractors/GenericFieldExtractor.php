<?php

declare(strict_types=1);

namespace App\Services\GoCardless\FieldExtractors;

use App\Models\Transaction;

class GenericFieldExtractor implements BankFieldExtractorInterface
{
    private static function get(array $arr, string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $value = $arr;
        foreach ($keys as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public function extractDescription(array $transaction): string
    {
        $remittance = self::get($transaction, 'remittanceInformationUnstructured');
        if ($remittance !== null && (string) $remittance !== '') {
            return (string) $remittance;
        }
        $arr = self::get($transaction, 'remittanceInformationUnstructuredArray');
        if (is_array($arr) && $arr !== []) {
            return implode(' | ', array_map('strval', $arr));
        }
        $creditor = self::get($transaction, 'creditorName');
        if ($creditor !== null && (string) $creditor !== '') {
            return (string) $creditor;
        }
        $debtor = self::get($transaction, 'debtorName');
        if ($debtor !== null && (string) $debtor !== '') {
            return (string) $debtor;
        }
        $code = self::get($transaction, 'proprietaryBankTransactionCode');
        return trim((string) $code) ?: 'Transaction';
    }

    public function extractPartner(array $transaction): ?string
    {
        $amount = self::get($transaction, 'transactionAmount.amount', 0);
        $amount = is_numeric($amount) ? (float) $amount : 0.0;
        if ($amount < 0) {
            $partner = self::get($transaction, 'creditorName');
        } else {
            $partner = self::get($transaction, 'debtorName');
        }
        if ($partner !== null && (string) $partner !== '') {
            return (string) $partner;
        }
        $partner = self::get($transaction, 'creditorName') ?? self::get($transaction, 'debtorName');
        if ($partner !== null && (string) $partner !== '') {
            return (string) $partner;
        }
        return null;
    }

    public function extractMerchantCategoryCode(array $transaction): ?string
    {
        $mcc = self::get($transaction, 'merchantCategoryCode');
        if ($mcc !== null && (string) $mcc !== '') {
            return (string) $mcc;
        }
        $remittance = self::get($transaction, 'remittanceInformationUnstructured');
        if ($remittance !== null && preg_match('/^MCC-(\d{4})$/', (string) $remittance, $m)) {
            return $m[1];
        }
        return null;
    }

    public function extractCurrencyExchange(array $transaction): ?array
    {
        $exchange = self::get($transaction, 'currencyExchange');
        if (! is_array($exchange) || $exchange === []) {
            return null;
        }
        return $exchange;
    }

    public function extractTransactionType(array $transaction, float $amount): string
    {
        $code = (string) self::get($transaction, 'proprietaryBankTransactionCode');
        $code = strtoupper(trim($code));
        // TRANSFER is set only when counterparty is own account (see GocardlessMapper)
        // if ($code !== '' && ($code === 'TRANSFER' || str_starts_with($code, 'TRF'))) {
        //     return Transaction::TYPE_TRANSFER;
        // }
        if ($amount > 0) {
            return Transaction::TYPE_DEPOSIT;
        }
        return Transaction::TYPE_PAYMENT;
    }

    public function extractMetadata(array $transaction): array
    {
        $metadata = [];
        $internalId = self::get($transaction, 'internalTransactionId');
        if ($internalId !== null && (string) $internalId !== '') {
            $metadata['internalTransactionId'] = (string) $internalId;
        }
        $entryRef = self::get($transaction, 'entryReference');
        if ($entryRef !== null && (string) $entryRef !== '') {
            $metadata['entryReference'] = (string) $entryRef;
        }
        $bankCode = self::get($transaction, 'bankTransactionCode');
        if ($bankCode !== null && (string) $bankCode !== '') {
            $metadata['bankTransactionCode'] = (string) $bankCode;
        }
        $propCode = self::get($transaction, 'proprietaryBankTransactionCode');
        if ($propCode !== null && (string) $propCode !== '') {
            $metadata['proprietaryBankTransactionCode'] = (string) $propCode;
        }
        $mcc = $this->extractMerchantCategoryCode($transaction);
        if ($mcc !== null) {
            $metadata['mcc'] = $mcc;
        }
        $endToEnd = self::get($transaction, 'endToEndId');
        if ($endToEnd !== null && (string) $endToEnd !== '') {
            $metadata['end_to_end_id'] = (string) $endToEnd;
        }
        return $metadata;
    }
}
