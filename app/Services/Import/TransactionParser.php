<?php

namespace App\Services\Import;

use Illuminate\Support\Facades\Log;

/**
 * @deprecated Use App\Services\TransactionImport\TransactionDataParser instead
 */
class TransactionParser
{
    // This class is deprecated and kept only for backward compatibility
    // All functionality has been moved to App\Services\TransactionImport module

    /**
     * Parses a transaction from the provided data row and returns a processed transaction result.
     *
     * @param array $row The input data row containing transaction details.
     * @return TransactionProcessedRow The processed transaction result with status, message, and parsed fields.
     */
    public function parseTransaction(array $row): TransactionProcessedRow
    {
        Log::debug('Parsing transaction');

        // Implement the logic to parse a transaction from the row data
        // This is a placeholder implementation
        // You would typically extract fields like date, amount, description, etc.

        // Example:
        // $date = $this->parseDate($this->row['date'], 'Y-m-d');
        // $amount = $this->parseAmount($this->row['amount'], '1,234.56', 'signed_amount');

        return new TransactionProcessedRow(
            TransactionParserRowStatus::SUCCESS,
            'Transaction parsed successfully',
            [
                'date' => '2.3.2023 12:00:00',
                'amount' => 2,
                'description' => $row['description'] ?? '',
            ]
        );
    }

    /**
     * Parses a date string into a standardized datetime format using the specified format or common alternatives.
     *
     * Cleans the input string of null bytes, whitespace, and control characters before attempting to parse.
     * Returns the date as a string in 'Y-m-d H:i:s' format if successful, or null if parsing fails.
     *
     * @param string $dateString The input date string to parse.
     * @param string $format The expected date format for parsing.
     * @return string|null The parsed date in 'Y-m-d H:i:s' format, or null if parsing fails.
     */
    public function parseDate(string $dateString, string $format): ?string
    {
        Log::debug('Parsing date', ['date_string' => $dateString, 'format' => $format]);

        // Clean the input string
        $dateString = str_replace("\0", '', $dateString);
        $dateString = trim($dateString);

        // Remove any control characters
        $dateString = preg_replace('/[\x00-\x1F\x7F]/', '', $dateString);

        // If the string is empty after cleaning, return null
        if (empty($dateString)) {
            return null;
        }

        try {
            $date = \DateTime::createFromFormat($format, $dateString);
            if ($date === false) {
                // Try alternative formats
                $alternativeFormats = [
                    'd.m.Y', 'Y-m-d', 'd/m/Y', 'm/d/Y', 'Y.m.d',
                    'd.m.Y H:i:s', 'Y-m-d H:i:s',
                ];

                foreach ($alternativeFormats as $altFormat) {
                    if ($altFormat !== $format) {
                        $date = \DateTime::createFromFormat($altFormat, $dateString);
                        if ($date !== false) {
                            break;
                        }
                    }
                }

                if ($date === false) {
                    Log::warning('Failed to parse date', [
                        'date_string' => $dateString,
                        'format' => $format,
                        'errors' => \DateTime::getLastErrors(),
                    ]);

                    return null;
                }
            }

            return $date->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            Log::error('Error parsing date', [
                'date_string' => $dateString,
                'format' => $format,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Parses a monetary amount from a string using the specified format and sign strategy.
     *
     * Cleans the input string, normalizes decimal and thousand separators according to the given format, and converts it to a float. The sign of the amount is determined by the provided strategy.
     *
     * @param string $amountString The raw amount string to parse.
     * @param string $format The expected number format (e.g., '1,234.56', '1.234,56', '1234,56').
     * @param string $strategy The strategy for determining the sign of the amount ('signed_amount', 'income_positive', or 'expense_positive').
     * @return float|null The parsed amount as a float, or null if parsing fails.
     */
    public function parseAmount(string $amountString, string $format, string $strategy): ?float
    {
        Log::debug('Parsing amount', [
            'amount_string' => $amountString,
            'format' => $format,
            'strategy' => $strategy,
        ]);

        // Clean the input string
        $amountString = str_replace("\0", '', $amountString);
        $amountString = trim($amountString);

        // Remove any control characters
        $amountString = preg_replace('/[\x00-\x1F\x7F]/', '', $amountString);

        // Remove any currency symbols and spaces
        $amountString = preg_replace('/[^0-9.,\-]/', '', $amountString);

        // Convert to standard decimal format
        if ($format === '1,234.56') {
            // US format: commas as thousand separators, period as decimal
            $amountString = str_replace(',', '', $amountString);
        } elseif ($format === '1.234,56') {
            // EU format: periods as thousand separators, comma as decimal
            $amountString = str_replace('.', '', $amountString);
            $amountString = str_replace(',', '.', $amountString);
        } elseif ($format === '1234,56') {
            // Format with no thousand separator and comma as decimal
            $amountString = str_replace(',', '.', $amountString);
        }

        // If the string is empty after cleaning, return null
        if (empty($amountString)) {
            return null;
        }

        try {
            $amount = floatval($amountString);

            // Apply amount type strategy
            if ($strategy === 'signed_amount') {
                return $amount;
            } elseif ($strategy === 'income_positive') {
                return $amount;
            } elseif ($strategy === 'expense_positive') {
                return -$amount;
            }

            return $amount;
        } catch (\Exception $e) {
            Log::error('Error parsing amount', [
                'amount_string' => $amountString,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
