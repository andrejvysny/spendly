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
     * Parses a raw CSV row into a structured transaction array according to the provided import configuration.
     *
     * Maps and converts fields such as dates and amounts, applies validation for required fields, sets defaults, and preserves the original import data. Throws an exception if required fields are missing or invalid.
     *
     * @param array $row The raw CSV row data.
     * @param array $configuration The import configuration specifying column mappings, headers, and parsing options.
     * @return array The parsed and normalized transaction data.
     * @throws \Exception If required fields are missing or invalid.
     */
    public function parse(array $row, array $configuration): array
    {
        $mapping = $configuration['column_mapping'] ?? [];
        $headers = $configuration['headers'] ?? [];

        // Initialize parsed data
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
     * Parses a field value from a transaction row according to its type and configuration.
     *
     * For date fields, parses the value using the configured date format. For amount fields, parses the value using the configured amount format and strategy. All other fields are trimmed of whitespace.
     *
     * @param string $field The name of the field to parse (e.g., 'booked_date', 'amount').
     * @param string $value The raw value from the CSV row.
     * @param array $configuration The import configuration specifying formats and strategies.
     * @return mixed The parsed value, with type depending on the field (string, float, or null).
     */
    private function parseField(string $field, string $value, array $configuration)
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
     * Parses a date string into a standardized `Y-m-d H:i:s` format using the specified format or common alternatives.
     *
     * Cleans the input string and attempts to parse it with the provided format. If parsing fails, tries several alternative date formats. Returns null if parsing is unsuccessful or the input is empty.
     *
     * @param string $dateString The raw date string to parse.
     * @param string $format The expected date format.
     * @return string|null The parsed date in `Y-m-d H:i:s` format, or null if parsing fails.
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
     * Parses a monetary amount string into a float value according to the specified format and sign strategy.
     *
     * Cleans the input string, normalizes it based on locale-specific formatting, and applies the sign convention as defined by the strategy.
     *
     * @param string $amountString The raw amount string to parse.
     * @param string $format The expected number format (e.g., '1,234.56', '1.234,56', or '1234,56').
     * @param string $strategy The sign strategy, such as 'expense_positive' to invert positive values.
     * @return float|null The parsed amount as a float, or null if the input is empty or invalid.
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
     * Determines whether the specified field is required for transaction import.
     *
     * @param string $field The field name to check.
     * @return bool True if the field is required; otherwise, false.
     */
    private function isRequiredField(string $field): bool
    {
        return in_array($field, ['booked_date', 'amount', 'partner']);
    }

    /**
     * Validates presence of required transaction fields and sets default values for optional fields.
     *
     * Ensures that 'booked_date', 'amount', and 'partner' are present and not null, throwing an exception if any are missing. Sets defaults for 'processed_date', 'description', 'type', and generates a unique 'transaction_id' if not provided.
     *
     * @param array $data Reference to the transaction data array to validate and update.
     * @throws \Exception If any required field is missing or null.
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
     * Constructs an associative array mapping headers to their corresponding row values.
     *
     * If a header is missing for a given index, a default key in the format "col_{index}" is used.
     *
     * @param array $row The array of raw row values.
     * @param array $headers The array of header names corresponding to each column index.
     * @return array Associative array of header (or default column key) to value.
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
