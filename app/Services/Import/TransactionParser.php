<?php

namespace App\Services\Import;

/**
 * @deprecated Use App\Services\TransactionImport\TransactionDataParser instead
 */
class TransactionParser
{
    // This class is deprecated and kept only for backward compatibility
    // All functionality has been moved to App\Services\TransactionImport module

    /**
     * Parse a transaction from a row of data
     *
     * @param  array  $row  The row data to parse
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
                'description' => $this->row['description'] ?? '',
            ]
        );
    }

    /**
     * Parse date from string based on format
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
     * Parse amount from string based on format
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
