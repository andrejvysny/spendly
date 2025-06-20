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
     * Initializes the TransactionRowProcessor with services for parsing, validating, and detecting duplicate transactions.
     */
    public function __construct(
        private readonly TransactionDataParser $parser,
        private readonly TransactionValidator $validator,
        private readonly DuplicateTransactionService $duplicateService,
    ) {}

    /**
     * Sets and validates the configuration for the transaction row processor.
     *
     * @param array $configuration Configuration settings required for processing transaction rows.
     * @throws \InvalidArgumentException If the configuration is missing required keys.
     */
    public function configure(array $configuration): void
    {
        // Configuration logic if needed
        Log::debug('Configuring TransactionRowProcessor', ['configuration' => $configuration]);
        $this->configuration = $configuration;

        if (! $this->canProcess($configuration)) {
            throw new \InvalidArgumentException('Invalid configuration for TransactionRowProcessor');
        }
    }

    /**
     * Processes a single transaction row with optional metadata and returns the result.
     *
     * @param array $row The transaction data row to process.
     * @param array $metadata Optional metadata associated with the row.
     * @return CsvProcessResult The result of processing the transaction row.
     */
    public function __invoke(array $row, array $metadata = []): CsvProcessResult
    {
        return $this->processRow($row, $metadata);
    }

    /**
     * Processes a single transaction row, performing parsing, validation, and duplicate detection.
     *
     * Skips empty rows, returns validation errors if present, and supports preview mode. If the transaction is a duplicate, it is skipped. On success, returns the imported transaction data.
     *
     * @param array $row The transaction row data to process.
     * @param array $metadata Optional metadata associated with the row, such as row number.
     * @return ProcessResultInterface The result of processing, indicating success, failure, or skip status.
     */
    public function processRow(array $row, array $metadata = []): ProcessResultInterface
    {
        $rowNumber = $metadata['row_number'] ?? 0;
        Log::debug('Processing transaction row '.$rowNumber);

        try {
            if ($this->isEmptyRow($row)) {
                return CsvProcessResult::skipped('Empty row '.$rowNumber, data: $row, metadata: $metadata);
            }

            $parsedData = $this->parser->parse($row, $this->configuration);

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

            if ($this->duplicateService->isDuplicate($parsedData, Auth::id())) {
                Log::info('Duplicate transaction found', [
                    'row_number' => $rowNumber,
                    'transaction_id' => $parsedData['transaction_id'],
                ]);

                return CsvProcessResult::skipped('Duplicate transaction', data: $row, metadata: [
                    'metadata' => $metadata,
                    'parsed_data' => $parsedData,
                ]);
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
     * Determines if the provided configuration contains all required keys for processing.
     *
     * @param array $configuration The configuration array to check.
     * @return bool True if all required configuration keys are present, false otherwise.
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
     * Determines whether the given row contains only null or whitespace values.
     *
     * @param array $row The row data to check.
     * @return bool True if the row is empty; otherwise, false.
     */
    private function isEmptyRow(array $row): bool
    {
        return empty(array_filter($row, function ($value) {
            return ! is_null($value) && trim((string) $value) !== '';
        }));
    }
}
