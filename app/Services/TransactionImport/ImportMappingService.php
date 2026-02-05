<?php

namespace App\Services\TransactionImport;

use Illuminate\Support\Facades\Log;

/**
 * Service for handling intelligent import mapping conversions and validations.
 * Converts between header-based and index-based mappings with fallback logic.
 */
class ImportMappingService
{
    /**
     * Convert index-based mapping to header-based mapping
     */
    public function convertIndexMappingToHeaders(array $columnMapping, array $headers): array
    {
        $headerMapping = [];

        foreach ($columnMapping as $field => $index) {
            if ($index !== null && isset($headers[$index])) {
                $headerMapping[$field] = $headers[$index];
            } else {
                $headerMapping[$field] = null;
            }
        }

        return $headerMapping;
    }

    /**
     * Convert header-based mapping to index-based mapping for current CSV
     */
    public function convertHeaderMappingToIndices(array $headerMapping, array $currentHeaders): array
    {
        $indexMapping = [];

        foreach ($headerMapping as $field => $headerName) {
            if ($headerName === null) {
                $indexMapping[$field] = null;

                continue;
            }

            // Find exact match first
            $index = array_search($headerName, $currentHeaders, true);

            if ($index !== false) {
                $indexMapping[$field] = $index;
            } else {
                // Try fuzzy matching if exact match fails
                $index = $this->findBestHeaderMatch($headerName, $currentHeaders);
                $indexMapping[$field] = $index;

                if ($index !== null) {
                    Log::info('Mapping fallback used', [
                        'field' => $field,
                        'original_header' => $headerName,
                        'matched_header' => $currentHeaders[$index],
                        'index' => $index,
                    ]);
                }
            }
        }

        return $indexMapping;
    }

    /**
     * Apply saved mapping to current CSV headers with validation
     */
    public function applySavedMapping(array $savedMapping, array $currentHeaders): array
    {
        // Check if mapping is already index-based (legacy format)
        if ($this->isIndexBasedMapping($savedMapping)) {
            return $this->applyIndexBasedMapping($savedMapping, $currentHeaders);
        }

        // Apply header-based mapping
        return $this->convertHeaderMappingToIndices($savedMapping, $currentHeaders);
    }

    /**
     * Enhanced auto-detection with better fuzzy matching
     */
    public function autoDetectMapping(array $headers): array
    {
        $mapping = [];

        // Initialize all fields as null
        $transactionFields = [
            'transaction_id', 'booked_date', 'amount', 'description', 'partner',
            'type', 'target_iban', 'source_iban', 'category', 'tags', 'notes',
            'balance_after_transaction',
        ];

        foreach ($transactionFields as $field) {
            $mapping[$field] = null;
        }

        // Enhanced mapping rules with better pattern matching
        $mappingRules = [
            'booked_date' => [
                'patterns' => ['date', 'time', 'datum', 'fecha', 'data'],
                'exact_matches' => ['transaction date', 'posting date', 'value date'],
            ],
            'amount' => [
                'patterns' => ['amount', 'sum', 'value', 'suma', 'betrag', 'monto'],
                'exact_matches' => ['transaction amount', 'debit amount', 'credit amount'],
            ],
            'description' => [
                'patterns' => ['description', 'details', 'note', 'text', 'popis', 'memo', 'reference'],
                'exact_matches' => ['transaction description', 'payment details'],
            ],
            'partner' => [
                'patterns' => ['partner', 'payee', 'recipient', 'merchant', 'counterparty'],
                'exact_matches' => ['transaction partner', 'beneficiary name'],
            ],
            'category' => [
                'patterns' => ['category', 'type', 'kategorie'],
                'exact_matches' => ['transaction category', 'expense category'],
            ],
            'transaction_id' => [
                'patterns' => ['id', 'reference', 'ref', 'transaction_id'],
                'exact_matches' => ['transaction id', 'reference number'],
            ],
            'target_iban' => [
                'patterns' => ['iban', 'account'],
                'conditions' => ['target', 'to', 'destination', 'recipient'],
            ],
            'source_iban' => [
                'patterns' => ['iban', 'account'],
                'conditions' => ['source', 'from', 'sender'],
            ],
            'tags' => [
                'patterns' => ['tag', 'label', 'tags'],
                'exact_matches' => ['transaction tags'],
            ],
            'balance_after_transaction' => [
                'patterns' => ['balance', 'saldo', 'kontostand', 'zostatok', 'running'],
                'exact_matches' => ['account balance', 'running balance', 'new balance', 'ending balance', 'closing balance'],
            ],
        ];

        foreach ($headers as $index => $header) {
            $headerLower = strtolower(trim($header));

            foreach ($mappingRules as $field => $rules) {
                // Skip if field already mapped
                if ($mapping[$field] !== null) {
                    continue;
                }

                // Check exact matches first
                if (isset($rules['exact_matches']) && in_array($headerLower, $rules['exact_matches'])) {
                    $mapping[$field] = $index;
                    break;
                }

                // Check pattern matches
                foreach ($rules['patterns'] as $pattern) {
                    if (str_contains($headerLower, $pattern)) {
                        // Check conditions for IBAN fields
                        if (isset($rules['conditions'])) {
                            $hasCondition = false;
                            foreach ($rules['conditions'] as $condition) {
                                if (str_contains($headerLower, $condition)) {
                                    $hasCondition = true;
                                    break;
                                }
                            }
                            if ($hasCondition) {
                                $mapping[$field] = $index;
                                break 2;
                            }
                        } else {
                            $mapping[$field] = $index;
                            break 2;
                        }
                    }
                }
            }
        }

        return $mapping;
    }

    /**
     * Validate mapping against current headers
     */
    public function validateMapping(array $columnMapping, array $headers): array
    {
        $validation = [
            'valid' => true,
            'errors' => [],
            'warnings' => [],
        ];

        // Check required fields
        $requiredFields = ['booked_date', 'amount', 'partner'];
        foreach ($requiredFields as $field) {
            if (! isset($columnMapping[$field]) || $columnMapping[$field] === null) {
                $validation['valid'] = false;
                $validation['errors'][] = "Missing required field mapping: {$field}";
            }
        }

        // Check if indices are valid
        foreach ($columnMapping as $field => $index) {
            if ($index !== null && (! is_int($index) || $index < 0 || $index >= count($headers))) {
                $validation['valid'] = false;
                $validation['errors'][] = "Invalid column index for field {$field}: {$index}";
            }
        }

        // Check for duplicate mappings
        $usedIndices = array_filter($columnMapping);
        if (count($usedIndices) !== count(array_unique($usedIndices))) {
            $validation['warnings'][] = 'Multiple fields mapped to the same column';
        }

        return $validation;
    }

    /**
     * Find the best header match using fuzzy matching
     */
    public function findBestHeaderMatch(string $targetHeader, array $availableHeaders): ?int
    {
        $targetLower = strtolower($targetHeader);
        $bestMatch = null;
        $bestScore = 0;

        foreach ($availableHeaders as $index => $header) {
            $headerLower = strtolower($header);

            // Calculate similarity score
            $score = 0;

            // Exact match
            if ($headerLower === $targetLower) {
                return $index;
            }

            // Contains match (check if any word from target is in header or vice versa)
            if (str_contains($headerLower, $targetLower) || str_contains($targetLower, $headerLower)) {
                $score = 0.8;
            } else {
                // Check for word-by-word matching
                $targetWords = explode(' ', $targetLower);
                $headerWords = explode(' ', $headerLower);

                $wordMatches = 0;
                $totalWords = max(count($targetWords), count($headerWords));

                foreach ($targetWords as $targetWord) {
                    foreach ($headerWords as $headerWord) {
                        if ($targetWord === $headerWord ||
                            str_contains($headerWord, $targetWord) ||
                            str_contains($targetWord, $headerWord)) {
                            $wordMatches++;
                            break;
                        }
                    }
                }

                if ($wordMatches > 0) {
                    $score = max($score, ($wordMatches / $totalWords) * 0.9);
                }
            }

            // Levenshtein distance (for similar words)
            if (strlen($targetLower) <= 50 && strlen($headerLower) <= 50) { // Only for reasonable lengths
                $distance = levenshtein($targetLower, $headerLower);
                $maxLength = max(strlen($targetLower), strlen($headerLower));
                if ($distance <= ($maxLength * 0.3) && $maxLength > 3) { // Allow 30% difference
                    $score = max($score, 1 - ($distance / $maxLength));
                }
            }

            // Similar word patterns
            if ($this->hasSimilarPattern($targetLower, $headerLower)) {
                $score = max($score, 0.7);
            }

            if ($score > $bestScore && $score >= 0.5) { // Lower threshold for more flexibility
                $bestScore = $score;
                $bestMatch = $index;
            }
        }

        return $bestMatch;
    }

    /**
     * Check if mapping is index-based (legacy format)
     */
    public function isIndexBasedMapping(array $mapping): bool
    {
        foreach ($mapping as $field => $value) {
            if ($value !== null && ! is_int($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Apply legacy index-based mapping with validation
     */
    private function applyIndexBasedMapping(array $indexMapping, array $currentHeaders): array
    {
        $validatedMapping = [];

        foreach ($indexMapping as $field => $index) {
            if ($index !== null && isset($currentHeaders[$index])) {
                $validatedMapping[$field] = $index;
            } else {
                $validatedMapping[$field] = null;

                if ($index !== null) {
                    Log::warning('Invalid index in legacy mapping', [
                        'field' => $field,
                        'index' => $index,
                        'available_headers' => count($currentHeaders),
                    ]);
                }
            }
        }

        return $validatedMapping;
    }

    /**
     * Check for similar word patterns
     */
    private function hasSimilarPattern(string $word1, string $word2): bool
    {
        $patterns = [
            ['date', 'datum', 'fecha', 'data', 'time'],
            ['amount', 'suma', 'betrag', 'monto', 'value', 'sum'],
            ['description', 'popis', 'beschreibung', 'details', 'memo', 'note'],
            ['partner', 'payee', 'merchant', 'counterparty', 'recipient'],
            ['balance', 'saldo', 'kontostand', 'zostatok', 'running'],
        ];

        // Extract individual words from the input strings
        $words1 = array_map('trim', explode(' ', strtolower($word1)));
        $words2 = array_map('trim', explode(' ', strtolower($word2)));

        foreach ($patterns as $pattern) {
            $matches1 = array_intersect($words1, $pattern);
            $matches2 = array_intersect($words2, $pattern);

            // If both strings contain words from the same pattern
            if (! empty($matches1) && ! empty($matches2)) {
                return true;
            }
        }

        return false;
    }
}
