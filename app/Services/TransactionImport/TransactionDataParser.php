<?php

namespace App\Services\TransactionImport;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Parses raw data into transaction format.
 * Handles date parsing, amount parsing, and field mapping.
 */
class TransactionDataParser
{
    /**
     * Parse raw row data into transaction format.
     *
     * @param  array  $row  The raw CSV row data
     * @param  array  $configuration  Import configuration
     * @return array Parsed transaction data
     *
     * @throws \Exception If required fields are missing or invalid
     */
    public function parse(array $row, array $configuration): array
    {
        $mapping = $configuration['column_mapping'] ?? [];
        $headers = $configuration['headers'] ?? [];

        // Initialize parsed data with all possible fields to ensure consistent column structure
        $data = [
            'currency' => $configuration['currency'] ?? 'EUR',
            'account_id' => $configuration['account_id'] ?? null,
            'import_id' => $configuration['import_id'] ?? null,
            'type' => 'Imported',
            'metadata' => [
                'import_id' => $configuration['import_id'] ?? null,
                'imported_at' => now()->format('Y-m-d H:i:s'),
            ],
            'balance_after_transaction' => 0, // Placeholder
            // Initialize all possible transaction fields as null to ensure column consistency
            'transaction_id' => null,
            'booked_date' => null,
            'processed_date' => null,
            'amount' => null,
            'description' => null,
            'partner' => null,
            'source_iban' => null,
            'target_iban' => null,
            'import_data' => null,
        ];

        // Map fields based on column mapping
        foreach ($mapping as $field => $columnIndex) {
            if ($columnIndex === null || ! isset($row[$columnIndex])) {
                continue;
            }

            $value = $row[$columnIndex];

            // Skip empty values for optional fields
            if (trim($value) === '' && ! $this->isRequiredField($field)) {
                continue;
            }

            // Parse value based on field type
            $data[$field] = $this->parseField($field, $value, $configuration);
        }

        // Handle required fields and defaults
        $this->handleRequiredFields($data);

        // Store original import data
        $data['import_data'] = $this->buildImportData($row, $headers);

        return $data;
    }

    /**
     * Parse a specific field value.
     */
    private function parseField(string $field, string $value, array $configuration): string|float|null
    {
        switch ($field) {
            case 'booked_date':
            case 'processed_date':
                return $this->parseDate($value, $configuration['date_format'] ?? 'd.m.Y');

            case 'amount':
                return $this->parseAmount(
                    $value,
                    $configuration['amount_format'] ?? '1,234.56',
                    $configuration['amount_type_strategy'] ?? 'signed_amount'
                );

            default:
                return trim($value);
        }
    }

    /**
     * Parse date from string.
     */
    private function parseDate(string $dateString, string $format): ?string
    {
        // Clean the input
        $dateString = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $dateString));

        if (empty($dateString)) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat($format, $dateString);

            if (! $date) {
                // Try alternative formats
                $alternativeFormats = [
                    'd.m.Y', 'Y-m-d', 'd/m/Y', 'm/d/Y', 'Y.m.d',
                    'd.m.Y H:i:s', 'Y-m-d H:i:s',
                ];

                foreach ($alternativeFormats as $altFormat) {
                    try {
                        $date = Carbon::createFromFormat($altFormat, $dateString);
                        if ($date) {
                            break;
                        }
                    } catch (\Exception $e) {
                        continue;
                    }
                }
            }

            return $date ? $date->format('Y-m-d H:i:s') : null;
        } catch (\Exception $e) {
            Log::warning('Failed to parse date', [
                'date_string' => $dateString,
                'format' => $format,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Parse amount from string.
     */
    private function parseAmount(string $amountString, string $format, string $strategy): ?float
    {
        // Clean the input
        $amountString = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $amountString));

        // Remove currency symbols and spaces
        $amountString = preg_replace('/[^0-9.,\-+]/', '', $amountString);

        if (empty($amountString)) {
            return null;
        }

        // Convert to standard decimal format based on format
        if ($format === '1,234.56') {
            // US format: commas as thousand separators, period as decimal
            $amountString = str_replace(',', '', $amountString);
        } elseif ($format === '1.234,56') {
            // EU format: periods as thousand separators, comma as decimal
            $amountString = str_replace('.', '', $amountString);
            $amountString = str_replace(',', '.', $amountString);
        } elseif ($format === '1234,56') {
            // No thousand separator, comma as decimal
            $amountString = str_replace(',', '.', $amountString);
        }

        $amount = (float) $amountString;

        // Apply amount type strategy
        if ($strategy === 'expense_positive' && $amount > 0) {
            $amount = -$amount;
        }

        return $amount;
    }

    /**
     * Check if a field is required.
     */
    private function isRequiredField(string $field): bool
    {
        return in_array($field, ['booked_date', 'amount', 'partner']);
    }

    /**
     * Handle required fields and set defaults.
     */
    private function handleRequiredFields(array &$data): void
    {
        // Validate required fields
        $requiredFields = ['booked_date', 'amount', 'partner'];
        foreach ($requiredFields as $field) {
            if (! isset($data[$field]) || $data[$field] === null) {
                throw new \Exception("Missing required field: {$field}");
            }
        }

        // Set defaults for optional fields
        if (! isset($data['processed_date'])) {
            $data['processed_date'] = $data['booked_date'];
        }

        if (empty($data['description'])) {
            $data['description'] = $data['partner'] ?? $data['type'] ?? 'Imported transaction';
        }

        // Ensure type is set
        if (empty($data['type'])) {
            $data['type'] = 'Imported';
        }

        // Generate transaction ID if not provided
        if (empty($data['transaction_id'])) {
            $data['transaction_id'] = 'IMP-'.uniqid();
        }
    }

    /**
     * Build import data for storage.
     */
    private function buildImportData(array $row, array $headers): array
    {
        $importData = [];

        foreach ($row as $index => $value) {
            $header = $headers[$index] ?? "col_{$index}";
            $importData[$header] = $value;
        }

        return $importData;
    }
}
