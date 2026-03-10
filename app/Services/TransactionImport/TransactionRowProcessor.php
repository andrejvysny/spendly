<?php

namespace App\Services\TransactionImport;

use App\Contracts\Import\ProcessResultInterface;
use App\Contracts\Import\RowProcessorInterface;
use App\Services\Csv\CsvProcessResult;
use App\Services\Csv\CsvRowProcessor;
use App\Services\DuplicateTransactionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Processes individual rows of transaction data.
 * Adapts between the generic row processing interface and transaction-specific logic.
 */
class TransactionRowProcessor implements CsvRowProcessor, RowProcessorInterface
{
    private array $configuration = [];

    /**
     * @var array<string, int>
     */
    private array $fingerprintOccurrences = [];

    public function __construct(
        private readonly TransactionDataParser $parser,
        private readonly TransactionValidator $validator,
        private readonly DuplicateTransactionService $duplicateService,
    ) {}

    public function configure(array $configuration): void
    {
        // Configuration logic if needed
        Log::debug('Configuring TransactionRowProcessor', ['configuration' => $configuration]);
        $this->configuration = $configuration;
        $this->fingerprintOccurrences = [];

        if (! $this->canProcess($configuration)) {
            throw new \InvalidArgumentException('Invalid configuration for TransactionRowProcessor');
        }
    }

    public function __invoke(array $row, array $metadata = []): CsvProcessResult
    {
        return $this->processRow($row, $metadata);
    }

    public function processRow(array $row, array $metadata = []): ProcessResultInterface
    {
        $rowNumber = $metadata['row_number'] ?? 0;
        Log::debug('Processing transaction row '.$rowNumber);

        try {
            if ($this->isEmptyRow($row)) {
                return CsvProcessResult::skipped('Empty row '.$rowNumber, data: $row, metadata: $metadata);
            }

            $configWithRow = array_merge($this->configuration, ['_row_number' => $rowNumber]);
            $parsedData = $this->parser->parse($row, $configWithRow);

            // Apply overrides if present
            if (isset($this->configuration['overrides'][$rowNumber])) {
                $parsedData = array_merge($parsedData, $this->configuration['overrides'][$rowNumber]);
                Log::debug('Applied overrides for row '.$rowNumber, ['overrides' => $this->configuration['overrides'][$rowNumber]]);
            }

            $transactionDto = new TransactionDto(
                $parsedData,
                $this->validator->validate($parsedData, $this->configuration)
            );

            Log::debug('Validation result', [
                'row_number' => $rowNumber,
                'is_valid' => $transactionDto->isValid(),
                'errors' => $transactionDto->getValidationResult()->getErrors(),
            ]);

            if (! $transactionDto->isValid()) {
                return CsvProcessResult::failure(
                    "Validation failed for row {$rowNumber}",
                    data: $row,
                    metadata: $metadata,
                    errors: $transactionDto->getValidationResult()->getErrors()
                );
            }

            // In preview mode, just return the parsed data
            if ($this->configuration['preview_mode'] ?? false) {
                return CsvProcessResult::success('Preview data', data: $transactionDto, metadata: $metadata);
            }

            $userId = Auth::id();
            if (! is_int($userId)) {
                throw new \RuntimeException('User must be authenticated to process transactions');
            }

            $fingerprint = (string) ($parsedData['fingerprint'] ?? $this->duplicateService->getStableFingerprint($parsedData));
            $occurrence = ($this->fingerprintOccurrences[$fingerprint] ?? 0) + 1;
            $this->fingerprintOccurrences[$fingerprint] = $occurrence;

            $duplicateDecision = $this->duplicateService->classifyImportRow($parsedData, $userId, $occurrence);
            $parsedData['fingerprint'] = $duplicateDecision['fingerprint'];

            if ($duplicateDecision['decision'] === 'skip') {
                Log::info('Duplicate transaction found', [
                    'row_number' => $rowNumber,
                    'transaction_id' => $parsedData['transaction_id'],
                    'fingerprint' => $duplicateDecision['fingerprint'],
                ]);

                return CsvProcessResult::skipped('Duplicate transaction', data: $row, metadata: array_merge(
                    $metadata,
                    [
                        'duplicate' => true,
                        'fingerprint' => $duplicateDecision['fingerprint'],
                        'parsedData' => $parsedData,
                    ]
                ));
            }

            if ($duplicateDecision['decision'] === 'review') {
                $parsedData['needs_manual_review'] = true;
                $parsedData['review_reason'] = $this->mergeReviewReasons(
                    $parsedData['review_reason'] ?? null,
                    'probable_duplicate'
                );

                $metadataArray = is_array($parsedData['metadata'] ?? null) ? $parsedData['metadata'] : [];
                $parsedData['metadata'] = array_merge($metadataArray, [
                    'probable_duplicate_transaction_id' => $duplicateDecision['probable_duplicate']?->id,
                    'probable_duplicate_score' => $duplicateDecision['probable_duplicate_score'],
                ]);

                $transactionDto
                    ->set('needs_manual_review', true)
                    ->set('review_reason', $parsedData['review_reason'])
                    ->set('metadata', $parsedData['metadata'])
                    ->set('fingerprint', $parsedData['fingerprint']);
            }

            return CsvProcessResult::success('Transaction imported', $transactionDto, metadata: $metadata);

        } catch (\Exception $e) {
            Log::error('Error processing transaction row', [
                'row_number' => $rowNumber,
                'error' => $e->getMessage(),
            ]);

            return CsvProcessResult::failure(
                'Processing error: '.$e->getMessage(),
                data: $row,
                metadata: $metadata,
            );
        }
    }

    /**
     * Check if the processor can handle the given configuration.
     */
    public function canProcess(array $configuration): bool
    {
        // Check required configuration
        $required = ['column_mapping', 'date_format', 'amount_format'];

        foreach ($required as $key) {
            if (! isset($configuration[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if a row is empty.
     */
    private function isEmptyRow(array $row): bool
    {
        return empty(array_filter($row, function ($value) {
            return ! is_null($value) && trim((string) $value) !== '';
        }));
    }

    private function mergeReviewReasons(?string $existingReasons, string $newReason): string
    {
        $reasons = $existingReasons !== null && trim($existingReasons) !== ''
            ? explode(',', $existingReasons)
            : [];

        $reasons[] = $newReason;
        $reasons = array_values(array_unique(array_filter(array_map('trim', $reasons))));

        return implode(',', $reasons);
    }
}
