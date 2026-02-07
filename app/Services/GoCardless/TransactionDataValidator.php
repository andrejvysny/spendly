<?php

declare(strict_types=1);

namespace App\Services\GoCardless;

use App\Services\GoCardless\DTOs\ValidationResult;
use Carbon\Carbon;
use Illuminate\Support\Str;

class TransactionDataValidator
{
    private const MAX_AMOUNT = 10_000_000;

    private const MIN_REASONABLE_DATE = '2000-01-01';

    private const MAX_DESCRIPTION_LENGTH = 1000;

    private const MAX_PARTNER_LENGTH = 255;

    private const VALID_CURRENCIES = ['EUR', 'USD', 'GBP', 'CHF', 'CZK', 'PLN', 'HUF', 'SEK', 'NOK', 'DKK'];

    public function validate(array $mappedData, Carbon $syncDate): ValidationResult
    {
        $errors = [];
        $warnings = [];
        $reviewReasons = [];

        $data = $mappedData;

        $transactionId = $data['transaction_id'] ?? null;
        if ($transactionId === null || (is_string($transactionId) && trim($transactionId) === '')) {
            $data['transaction_id'] = $this->generateFallbackId($data);
            $warnings[] = 'Generated fallback transaction ID';
            $reviewReasons[] = 'generated_transaction_id';
        }

        $amount = $data['amount'] ?? null;
        if ($amount === null || $amount === '') {
            $errors[] = 'Amount is required';
        } else {
            $amountFloat = is_numeric($amount) ? (float) $amount : 0.0;
            $data['amount'] = $amountFloat;
            if (abs($amountFloat) > self::MAX_AMOUNT) {
                $warnings[] = 'Unusually large amount';
                $reviewReasons[] = 'large_amount';
            }
            if ($amountFloat === 0.0) {
                $warnings[] = 'Zero amount';
                $reviewReasons[] = 'zero_amount';
            }
            if (abs($amountFloat) > 0 && abs($amountFloat) < 0.01) {
                $warnings[] = 'Near-zero amount';
                $reviewReasons[] = 'near_zero_amount';
            }
        }

        $bookedDate = $data['booked_date'] ?? null;
        if (empty($bookedDate)) {
            $data['booked_date'] = $syncDate;
            $warnings[] = 'Missing booked_date, using sync date';
            $reviewReasons[] = 'missing_booked_date';
        } elseif ($bookedDate instanceof Carbon) {
            if ($bookedDate->isFuture()) {
                $warnings[] = 'Future booked_date';
                $reviewReasons[] = 'future_date';
            }
            if ($bookedDate->isBefore(Carbon::parse(self::MIN_REASONABLE_DATE))) {
                $warnings[] = 'Very old booked_date';
                $reviewReasons[] = 'old_date';
            }
        }

        $processedDate = $data['processed_date'] ?? null;
        if (empty($processedDate)) {
            $data['processed_date'] = $data['booked_date'] ?? $syncDate;
        }

        $currency = $data['currency'] ?? null;
        if (empty($currency) || ! in_array(strtoupper((string) $currency), self::VALID_CURRENCIES, true)) {
            $data['currency'] = 'EUR';
            if (! empty($currency)) {
                $warnings[] = 'Invalid currency, defaulting to EUR';
                $reviewReasons[] = 'invalid_currency';
            }
        } else {
            $data['currency'] = strtoupper((string) $currency);
        }

        $description = $data['description'] ?? '';
        if (is_string($description) && strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
            $data['description'] = Str::limit($description, self::MAX_DESCRIPTION_LENGTH);
            $warnings[] = 'Description truncated';
        }
        if (empty(trim((string) ($data['description'] ?? '')))) {
            $data['description'] = 'Transaction ' . ($data['transaction_id'] ?? 'unknown');
        }

        $partner = $data['partner'] ?? null;
        if (is_string($partner) && strlen($partner) > self::MAX_PARTNER_LENGTH) {
            $data['partner'] = Str::limit($partner, self::MAX_PARTNER_LENGTH);
            $warnings[] = 'Partner truncated';
        }

        $needsReview = $reviewReasons !== [];

        return new ValidationResult(
            data: $data,
            errors: $errors,
            warnings: $warnings,
            needsReview: $needsReview,
            reviewReasons: array_values(array_unique($reviewReasons)),
        );
    }

    private function generateFallbackId(array $data): string
    {
        $parts = [
            $data['internal_transaction_id'] ?? '',
            $data['booked_date'] ?? '',
            $data['amount'] ?? '',
            $data['description'] ?? '',
        ];
        return 'fallback_' . md5(implode('|', $parts));
    }
}
