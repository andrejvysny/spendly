<?php

declare(strict_types=1);

namespace App\Services\GoCardless\FieldExtractors;

use App\Models\Transaction;

class RevolutFieldExtractor implements BankFieldExtractorInterface
{
    private const DIRECTIONAL_PREFIXES = [
        'To ', 'From ', 'Payment from ', 'Exchanged to ', 'Transfer to ', 'Transfer from ',
    ];

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
        $arr = self::get($transaction, 'remittanceInformationUnstructuredArray');
        if (is_array($arr) && $arr !== []) {
            $joined = implode(' | ', array_map('strval', $arr));
            if ($joined !== '') {
                return $joined;
            }
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
        $amount = self::get($transaction, 'transactionAmount.amount', 0);
        $amount = is_numeric($amount) ? (float) $amount : 0.0;
        $direction = $amount >= 0 ? 'In' : 'Out';
        return trim((string) $code . ' ' . $direction);
    }

    public function extractPartner(array $transaction): ?string
    {
        $creditor = self::get($transaction, 'creditorName');
        if ($creditor !== null && (string) $creditor !== '') {
            return (string) $creditor;
        }
        $debtor = self::get($transaction, 'debtorName');
        if ($debtor !== null && (string) $debtor !== '') {
            return (string) $debtor;
        }

        $arr = self::get($transaction, 'remittanceInformationUnstructuredArray');
        if (! is_array($arr) || $arr === []) {
            return null;
        }
        $code = (string) self::get($transaction, 'proprietaryBankTransactionCode');
        return $this->extractPartnerFromArray($arr, $code);
    }

    private function extractPartnerFromArray(array $arr, string $code): ?string
    {
        $first = $arr[0] ?? '';
        $first = trim((string) $first);
        if ($first === '') {
            return null;
        }
        foreach (self::DIRECTIONAL_PREFIXES as $prefix) {
            if (str_starts_with($first, $prefix)) {
                return null;
            }
        }
        if (str_starts_with($code, 'CARD_PAYMENT') && count($arr) === 1) {
            return $first;
        }
        if (count($arr) >= 2) {
            return $first;
        }
        return null;
    }

    public function extractMerchantCategoryCode(array $transaction): ?string
    {
        $mcc = self::get($transaction, 'merchantCategoryCode');
        if ($mcc !== null && (string) $mcc !== '') {
            return (string) $mcc;
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

        return match (true) {
            $code === 'CARD_PAYMENT' => Transaction::TYPE_CARD_PAYMENT,
            $code === 'TRANSFER' => Transaction::TYPE_TRANSFER,
            $code === 'TOPUP' => Transaction::TYPE_DEPOSIT,
            $code === 'EXCHANGE' => Transaction::TYPE_EXCHANGE,
            $amount > 0 => Transaction::TYPE_DEPOSIT,
            default => Transaction::TYPE_PAYMENT,
        };
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
        $endToEnd = self::get($transaction, 'endToEndId');
        if ($endToEnd !== null && (string) $endToEnd !== '') {
            $metadata['end_to_end_id'] = (string) $endToEnd;
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
        $additional = self::get($transaction, 'additionalDataStructured');
        if (is_array($additional) && $additional !== []) {
            $metadata['additional_data'] = $additional;
        }
        return $metadata;
    }
}
