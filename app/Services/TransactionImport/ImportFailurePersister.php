<?php

namespace App\Services\TransactionImport;

use App\Contracts\Import\BatchResultInterface;
use App\Contracts\Repositories\ImportFailureRepositoryInterface;
use App\Models\Import\Import;
use App\Models\Import\ImportFailure;
use App\Services\Csv\CsvProcessResult;
use Illuminate\Support\Facades\Log;

/**
 * Handles persisting import failures to the database.
 * Stores skipped and failed transactions for manual review.
 */
class ImportFailurePersister
{
    private array $failureBatch = [];

    private int $batchSize = 100;

    public function __construct(private readonly ImportFailureRepositoryInterface $failures) {}

    /**
     * Persist all failed and skipped results from a batch.
     */
    public function persistFailures(BatchResultInterface $batch, Import $import): array
    {
        $stats = [
            'failures_stored' => 0,
            'skipped_stored' => 0,
            'total_stored' => 0,
        ];

        // Process failed results
        foreach ($batch->getFailedResults() as $result) {
            assert($result instanceof CsvProcessResult, 'Expected CsvProcessResult in batch results');
            $this->addFailureToBatch($result, $import, ImportFailure::ERROR_TYPE_VALIDATION_FAILED);
            $stats['failures_stored']++;
        }

        // Process skipped results
        foreach ($batch->getSkippedResults() as $result) {
            assert($result instanceof CsvProcessResult, 'Expected CsvProcessResult in batch results');
            $this->addFailureToBatch($result, $import, $this->determineSkippedErrorType($result));
            $stats['skipped_stored']++;
        }

        // Process any remaining failures in the batch
        $this->processBatch();

        $stats['total_stored'] = $stats['failures_stored'] + $stats['skipped_stored'];

        Log::info('Import failures persisted', [
            'import_id' => $import->id,
            'stats' => $stats,
        ]);

        return $stats;
    }

    /**
     * Add a failure to the batch queue.
     */
    private function addFailureToBatch(CsvProcessResult $result, Import $import, string $errorType): void
    {
        $metadata = $result->getMetadata() ?? [];
        $rowNumber = $metadata['row_number'] ?? null;

        $failureData = [
            'import_id' => $import->id,
            'row_number' => $rowNumber,
            'raw_data' => $result->getData(),
            'error_type' => $errorType,
            'error_message' => $result->getMessage(),
            'error_details' => $this->formatErrorDetails($result),
            'parsed_data' => $this->extractParsedData($result, $metadata),
            'metadata' => $this->formatMetadata($metadata),
            'status' => ImportFailure::STATUS_PENDING,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $this->failureBatch[] = $failureData;

        // Process batch if it's full
        if (count($this->failureBatch) >= $this->batchSize) {
            $this->processBatch();
        }
    }

    /**
     * Process the current batch of failures.
     */
    private function processBatch(): void
    {
        if (empty($this->failureBatch)) {
            return;
        }

        $batch = $this->failureBatch;
        $this->failureBatch = []; // Clear the queue

        try {
            $this->failures->transaction(function () use ($batch) {
                $inserted = $this->failures->createBatch($batch);

                Log::debug('Failure batch processed successfully', [
                    'batch_size' => $inserted,
                ]);
            });
        } catch (\Exception $e) {
            Log::error('Failure batch processing failed', [
                'error' => $e->getMessage(),
                'batch_size' => count($batch),
            ]);

            // Fall back to individual inserts
            foreach ($batch as $failureData) {
                try {
                    $this->failures->createOne($failureData);
                } catch (\Exception $individualError) {
                    Log::error('Individual failure insert failed', [
                        'error' => $individualError->getMessage(),
                        'import_id' => $failureData['import_id'],
                        'row_number' => $failureData['row_number'],
                    ]);
                }
            }
        }
    }

    /**
     * Determine the error type for skipped results.
     */
    private function determineSkippedErrorType(CsvProcessResult $result): string
    {
        $metadata = $result->getMetadata() ?? [];

        // Check if it's a duplicate
        if (isset($metadata['duplicate']) && $metadata['duplicate'] === true) {
            return ImportFailure::ERROR_TYPE_DUPLICATE;
        }

        // Default to processing error for other skipped items
        return ImportFailure::ERROR_TYPE_PROCESSING_ERROR;
    }

    /**
     * Format error details for storage.
     */
    private function formatErrorDetails(CsvProcessResult $result): array
    {
        $details = [
            'message' => $result->getMessage(),
            'errors' => $result->getErrors(),
        ];

        // Add additional context if available
        $metadata = $result->getMetadata() ?? [];
        if (isset($metadata['validation_errors'])) {
            $details['validation_errors'] = $metadata['validation_errors'];
        }

        return $details;
    }

    /**
     * Extract parsed data if available.
     */
    private function extractParsedData(CsvProcessResult $result, array $metadata): ?array
    {
        // If this was a validation failure, there might be parsed data available
        if (isset($metadata['parsedData'])) {
            return $metadata['parsedData'];
        }

        // For some skipped items, the data might be in the result itself
        $data = $result->getData();
        if ($data instanceof TransactionDto) {
            return $data->toArray();
        }

        return null;
    }

    /**
     * Format metadata for storage.
     */
    private function formatMetadata(array $metadata): array
    {
        // Remove sensitive or redundant data
        $cleanMetadata = $metadata;
        unset($cleanMetadata['parsedData']); // Already stored in parsed_data field

        return $cleanMetadata;
    }

    /**
     * Persist SQL failures from the transaction persistence layer.
     */
    public function persistSqlFailures(TransactionPersistenceResult $persistenceResult, Import $import): array
    {
        $stats = [
            'sql_failures_stored' => 0,
        ];

        if (! $persistenceResult->hasSqlFailures()) {
            return $stats;
        }

        foreach ($persistenceResult->getSqlFailures() as $failure) {
            $transactionDto = $failure['transaction_dto'];
            $exception = $failure['exception'];
            $metadata = $failure['metadata'];

            $metadata['headers'] = array_keys($this->extractRawDataFromDto($transactionDto));
            // Determine error type based on exception
            $errorType = TransactionPersistenceResult::isFingerprintConstraintViolation($exception)
                ? ImportFailure::ERROR_TYPE_DUPLICATE
                : ImportFailure::ERROR_TYPE_PROCESSING_ERROR;

            // Create failure data
            $failureData = [
                'import_id' => $import->id,
                'row_number' => $metadata['row_number'] ?? null,
                'raw_data' => array_values($this->extractRawDataFromDto($transactionDto)),
                'error_type' => $errorType,
                'error_message' => $this->formatSqlErrorMessage($exception, $errorType),
                'error_details' => $this->formatSqlErrorDetails($exception, $metadata),
                'parsed_data' => $transactionDto->toArray(),
                'metadata' => $this->formatSqlFailureMetadata($metadata, $exception),
                'status' => ImportFailure::STATUS_PENDING,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $this->failureBatch[] = $failureData;
            $stats['sql_failures_stored']++;

            // Process batch if it's full
            if (count($this->failureBatch) >= $this->batchSize) {
                $this->processBatch();
            }
        }

        // Process any remaining failures in the batch
        $this->processBatch();

        Log::info('SQL failures persisted', [
            'import_id' => $import->id,
            'stats' => $stats,
        ]);

        return $stats;
    }

    /**
     * Force process any remaining failures in the batch.
     */
    public function flush(): void
    {
        if (! empty($this->failureBatch)) {
            $this->processBatch();
        }
    }

    /**
     * Extract raw data from TransactionDto for storage.
     */
    private function extractRawDataFromDto(TransactionDto $dto): array
    {
        // Try to get original import data if available
        $importData = $dto->get('import_data', []);
        if (is_array($importData) && ! empty($importData)) {

            if (is_string($importData)) {
                // Attempt to decode JSON string if necessary
                $decoded = json_decode($importData, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $decoded;
                }
            }

            return $importData;
        }

        // Fallback to DTO array representation
        return $dto->toArray();
    }

    /**
     * Format SQL error message for user-friendly display.
     */
    private function formatSqlErrorMessage(\Exception $exception, string $errorType): string
    {
        if ($errorType === ImportFailure::ERROR_TYPE_DUPLICATE) {
            return 'Duplicate transaction detected - transaction with same fingerprint already exists';
        }

        return 'Database error occurred while saving transaction: '.$exception->getMessage();
    }

    /**
     * Format SQL error details for storage.
     */
    private function formatSqlErrorDetails(\Exception $exception, array $metadata): array
    {
        $details = [
            'message' => $exception->getMessage(),
            'exception_type' => get_class($exception),
            'sql_error' => true,
        ];

        // Add specific details for fingerprint violations
        if (TransactionPersistenceResult::isFingerprintConstraintViolation($exception)) {
            $details['constraint_violation'] = 'fingerprint';
            $details['duplicate_detection'] = 'Fingerprint collision detected';
            $details['fingerprint'] = $metadata['fingerprint'] ?? 'unknown';
        }

        return $details;
    }

    /**
     * Format metadata for SQL failures.
     */
    private function formatSqlFailureMetadata(array $metadata, \Exception $exception): array
    {
        return array_merge($metadata, [
            'sql_failure' => true,
            'error_type' => TransactionPersistenceResult::determineErrorType($exception),
            'failure_timestamp' => now()->toISOString(),
        ]);
    }
}
