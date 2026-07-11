<?php

namespace App\Services\TransactionImport;

use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
            // balance_after_transaction will be set from CSV if mapped, otherwise null
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

        $data['fingerprint'] = Transaction::generateFingerprint($data);

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

            case 'balance_after_transaction':
                // Parse balance as an amount but never negate it (balances are always signed as-is)
                return $this->parseAmount(
                    $value,
                    $configuration['amount_format'] ?? '1,234.56',
                    'signed_amount' // Always use signed_amount for balance
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
        $dateString = trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', $dateString));

        if ($dateString === '') {
            return null;
        }

        // Carbon::createFromFormat throws on mismatch, so each format needs its
        // own attempt - the configured format first, then common alternatives.
        $formats = array_values(array_unique(array_merge([$format], [
            'd.m.Y', 'Y-m-d', 'd/m/Y', 'm/d/Y', 'Y.m.d',
            'd.m.Y H:i:s', 'Y-m-d H:i:s',
        ])));

        foreach ($formats as $tryFormat) {
            try {
                $date = Carbon::createFromFormat($tryFormat, $dateString);
            } catch (\Exception) {
                continue;
            }
            if ($date instanceof Carbon) {
                return $date->format('Y-m-d H:i:s');
            }
        }

        Log::warning('Failed to parse date', [
            'date_string' => $dateString,
            'format' => $format,
        ]);

        return null;
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

        // Normalize format aliases (AmountParser returns 'eu'/'us'/'simple', UI uses format strings)
        $normalizedFormat = match ($format) {
            'eu', '1.234,56' => 'eu',
            'us', '1,234.56' => 'us',
            'simple', '1234,56' => 'simple',
            default => $format,
        };

        // Convert to standard decimal format based on format
        if ($normalizedFormat === 'us') {
            // US format: commas as thousand separators, period as decimal
            $amountString = str_replace(',', '', $amountString);
        } elseif ($normalizedFormat === 'eu') {
            // EU format: periods as thousand separators, comma as decimal
            $amountString = str_replace('.', '', $amountString);
            $amountString = str_replace(',', '.', $amountString);
        } elseif ($normalizedFormat === 'simple') {
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
    /**
     * @param  array<string, mixed>  $data
     */
    private function handleRequiredFields(array &$data): void
    {
        // Resolve a direction-agnostic partner IBAN column (e.g. SLSP "IBAN partnera")
        // into the directional field implied by the amount sign.
        $this->resolvePartnerIban($data);

        // Set defaults for optional fields first (so partner can be derived)
        if (! isset($data['processed_date'])) {
            $data['processed_date'] = $data['booked_date'];
        }

        if (empty($data['description'])) {
            $data['description'] = $data['partner'] ?? $data['type'] ?? 'Imported transaction';
        }

        // Partner fallback for CSVs without a partner column (e.g. Revolut: use description)
        if ($this->blankValue($data['partner'] ?? null) && ! empty($data['description'])) {
            $desc = $this->stringValue($data['description']);
            $data['partner'] = strlen($desc) > 255 ? substr($desc, 0, 252).'...' : $desc;
        }
        // If partner still empty (e.g. description was empty string), use type or default
        if ($this->blankValue($data['partner'] ?? null)) {
            $typeValue = $this->stringValue($data['type'] ?? '');
            $data['partner'] = trim($typeValue) !== '' ? $typeValue : 'Imported transaction';
        }

        // Validate required fields
        $requiredFields = ['booked_date', 'amount', 'partner'];
        foreach ($requiredFields as $field) {
            if ($this->blankValue($data[$field] ?? null)) {
                throw new \Exception("Missing required field: {$field}");
            }
        }

        // Ensure type is set
        if (empty($data['type'])) {
            $data['type'] = 'Imported';
        } else {
            $classification = $this->classifyTransferType($this->stringValue($data['type']));
            if ($classification === 'strong') {
                $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
                $metadata['transfer_candidate'] = true;
                if ($this->matchesSingleLegPattern($this->stringValue($data['description'] ?? ''))) {
                    // Pocket/vault moves have no pairable credit leg; flag them
                    // so the detector can mark them as single-leg transfers.
                    $metadata['single_leg_transfer_candidate'] = true;
                }
                $data['metadata'] = $metadata;
                $data['type'] = is_numeric($data['amount']) && (float) $data['amount'] < 0
                    ? Transaction::TYPE_PAYMENT
                    : Transaction::TYPE_DEPOSIT;
            } elseif ($classification === 'weak') {
                // Standing orders / payment orders are often bills, not transfers.
                // A weak hint feeds the heuristic scorer without review-queue noise.
                $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
                $metadata['transfer_type_hint'] = true;
                $data['metadata'] = $metadata;
            }
        }

        // Generate transaction ID if not provided
        if (empty($data['transaction_id'])) {
            $data['transaction_id'] = 'IMP-'.uniqid();
        }

        if (! array_key_exists('balance_after_transaction', $data)) {
            $data['balance_after_transaction'] = null;
        }
    }

    /**
     * Classify a raw CSV type string as transfer evidence.
     *
     * 'strong' - the row explicitly says it is a transfer ("Transfer",
     * "SEPA prevod", ...): becomes a transfer_candidate.
     * 'weak' - transfer-shaped but frequently used for bills (standing orders,
     * payment orders, top-ups): only hints the heuristic scorer.
     * Matching is case- and diacritics-insensitive contains.
     */
    private function classifyTransferType(string $type): ?string
    {
        $normalized = strtolower(Str::ascii(trim($type)));
        if ($normalized === '') {
            return null;
        }

        $strongAliases = ['transfer', 'prevod', 'presun', 'uberweisung', 'virement', 'bonifico'];
        foreach ($strongAliases as $alias) {
            if (str_contains($normalized, $alias)) {
                return 'strong';
            }
        }

        $weakAliases = [
            'trvalym prikazom', 'trvaly platobny prikaz', 'platobny prikaz na uhradu',
            'standing order', 'standingorder', 'topup',
        ];
        foreach ($weakAliases as $alias) {
            if (str_contains($normalized, $alias)) {
                return 'weak';
            }
        }

        return null;
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /** True when the value is missing, non-scalar, or trims to an empty string. */
    private function blankValue(mixed $value): bool
    {
        return ! is_scalar($value) || trim((string) $value) === '';
    }

    private function matchesSingleLegPattern(string $description): bool
    {
        $normalized = strtolower(Str::ascii(trim($description)));
        if ($normalized === '') {
            return false;
        }

        /** @var array<int, string> $patterns */
        $patterns = config('transfers.single_leg.patterns', []);
        foreach ($patterns as $pattern) {
            $needle = strtolower(Str::ascii(trim($pattern)));
            if ($needle !== '' && str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fill target_iban (outgoing) or source_iban (incoming) from a mapped
     * partner_iban column, based on the amount sign. Explicit directional
     * mappings win.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolvePartnerIban(array &$data): void
    {
        if (! array_key_exists('partner_iban', $data)) {
            return;
        }

        $partnerIban = trim((string) $data['partner_iban']);
        unset($data['partner_iban']);

        $amount = $data['amount'] ?? null;
        if ($partnerIban === '' || ! is_numeric($amount)) {
            return;
        }

        if ((float) $amount < 0) {
            if (empty($data['target_iban'])) {
                $data['target_iban'] = $partnerIban;
            }
        } elseif (empty($data['source_iban'])) {
            $data['source_iban'] = $partnerIban;
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
