<?php

namespace App\Services\TransactionImport;

use App\Contracts\Import\BatchResultInterface;
use App\Models\Import\Import;
use App\Models\Import\ImportRowEdit;
use App\Services\Csv\CsvProcessor;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates the transaction import process.
 * Coordinates between CSV processing and transaction domain.
 */
readonly class TransactionImportService
{
    public function __construct(
        private CsvProcessor $csvProcessor,
        private TransactionRowProcessor $rowProcessor,
        private TransactionPersister $persister,
        private ImportFailurePersister $failurePersister,
    ) {}

    /**
     * Process a transaction import.
     *
     * @param  Import  $import  The import model
     * @param  int  $accountId  The account to import transactions into
     * @return array Import results
     */
    public function processImport(Import $import, int $accountId): array
    {
        Log::info('Starting transaction import', [
            'import_id' => $import->id,
            'account_id' => $accountId,
        ]);

        // Update import status
        $import->update(['status' => Import::STATUS_PROCESSING]);

        try {
            // Load all row edits for processing
            $overrides = $import->rowEdits()
                ->pluck('data', 'row_number')
                ->map(fn ($data) => is_string($data) ? json_decode($data, true) : $data)
                ->toArray();

            // Prepare configuration for processing
            $configuration = $this->prepareConfiguration($import, $accountId, $overrides);
            $this->rowProcessor->configure($configuration);

            $batch = $this->csvProcessor->processRows(
                "imports/{$import->filename}",
                $import->metadata['delimiter'] ?? ';',
                $import->metadata['quote_char'] ?? '"',
                $this->rowProcessor,
            );

            Log::info('CSV processing finished', [
                'import_id' => $import->id,
                'rows' => $batch->getTotalProcessed(),
                'success_count' => $batch->getSuccessCount(),
                'failed_count' => $batch->getFailedCount(),
                'skipped_count' => $batch->getSkippedCount(),
            ]);

            // Persist successful transactions
            $persistenceResult = $this->persister->persistBatch($batch);

            // Persist failures and skipped transactions for manual review
            $failureStats = $this->failurePersister->persistFailures($batch, $import);

            // Process SQL failures from transaction persistence

            $sqlFailureStats = $this->failurePersister->persistSqlFailures($persistenceResult, $import);

            Log::info('Import failure persistence completed', [
                'import_id' => $import->id,
                'failure_stats' => $failureStats,
                'sql_failure_stats' => $sqlFailureStats,
            ]);

            $this->updateImportStatus($import, $batch, $persistenceResult);

        } catch (\Exception $e) {
            Log::error('Transaction import failed', [
                'import_id' => $import->id,
                'error' => $e->getMessage(),
            ]);

            $import->update(['status' => Import::STATUS_FAILED]);

            throw $e;
        }

        return $this->formatResults($batch);
    }

    /**
     * Get a preview of the import data.
     */
    public function getPreview(Import $import, int $previewSize = 10): array
    {
        $configuration = $this->prepareConfiguration($import, null);
        $configuration['preview_mode'] = true;
        $configuration['max_rows'] = $previewSize + 1; // +1 for header

        $this->rowProcessor->configure($configuration);

        $result = $this->csvProcessor->processRows(
            "imports/{$import->filename}",
            $import->metadata['delimiter'] ?? ';',
            $import->metadata['quote_char'] ?? '"',
            $this->rowProcessor,
            true, // skip_header
            $previewSize + 1 // num_rows (including potential header processing)
        );

        $previewData = [];
        foreach ($result->getResults() as $processResult) {
            if ($processResult->isSuccess() && $processResult->getData()) {
                $previewData[] = $processResult->getData();
            }
        }

        return array_slice($previewData, 0, $previewSize);
    }

    /**
     * Get paginated rows with applied edits.
     */
    public function getRows(Import $import, int $limit, int $offset): array
    {
        // Load edits for the requested page
        // Note: Row numbers in CsvProcessor are 1-based (ignoring header if skipped)
        // Offset 0 = Row 1. Offset 10 = Row 11.
        $startRow = $offset + 1;
        $endRow = $offset + $limit;

        $edits = $import->rowEdits()
            ->whereBetween('row_number', [$startRow, $endRow])
            ->pluck('data', 'row_number')
            ->map(fn ($data) => is_string($data) ? json_decode($data, true) : $data)
            ->toArray();

        $configuration = $this->prepareConfiguration($import, null, $edits);
        $configuration['preview_mode'] = true;

        $this->rowProcessor->configure($configuration);

        $result = $this->csvProcessor->processRows(
            "imports/{$import->filename}",
            $import->metadata['delimiter'] ?? ';',
            $import->metadata['quote_char'] ?? '"',
            $this->rowProcessor,
            true, // skip_header
            $limit, // num_rows
            $offset // offset
        );

        $rows = [];
        foreach ($result->getResults() as $processResult) {
            if ($processResult->getData() instanceof \App\Services\TransactionImport\TransactionDto) {
                 $rows[] = array_merge(
                     $processResult->getData()->toArray(),
                     ['_row_number' => $processResult->getMetadata()['row_number'] ?? null]
                 );
            }
        }

        return $rows;
    }

    /**
     * Get unique values and their counts for a specific column.
     * Use raw CSV data for performance, as edits are sparse.
     */
    public function getColumnValues(Import $import, string $column): array
    {
        // We find the index of the column from the mapping
        // Note: The mapping stores 'column_name' => index
        $mapping = $import->column_mapping ?? [];
        $columnIndex = $mapping[$column] ?? null;

        if ($columnIndex === null) {
            return [];
        }

        $distribution = [];

        // We process all rows to aggregate values
         $this->csvProcessor->processRows(
            "imports/{$import->filename}",
            $import->metadata['delimiter'] ?? ';',
            $import->metadata['quote_char'] ?? '"',
            function ($row) use (&$distribution, $columnIndex) {
                $value = $row[$columnIndex] ?? null;
                if ($value !== null && trim($value) !== '') {
                    $value = trim($value);
                    if (!isset($distribution[$value])) {
                        $distribution[$value] = 0;
                    }
                    $distribution[$value]++;
                }
                // Return generic success to keep processing
                return \App\Services\Csv\CsvProcessResult::success('', []);
            },
            true // skip_header
        );

         // Sort by count descending
         arsort($distribution);

         // Format for frontend
         $result = [];
         foreach ($distribution as $value => $count) {
             $result[] = ['value' => $value, 'count' => $count];
         }

         return $result;
    }

    /**
     * Save an edit for a specific row.
     */
    public function saveRowEdit(Import $import, int $rowNumber, array $data): void
    {
        ImportRowEdit::updateOrCreate(
            [
                'import_id' => $import->id,
                'row_number' => $rowNumber,
            ],
            [
                'data' => $data,
            ]
        );
    }

    /**
     * Prepare configuration for processing.
     */
    private function prepareConfiguration(Import $import, ?int $accountId, array $overrides = []): array
    {
        return [
            'account_id' => $accountId ?? $import->metadata['account_id'] ?? null,
            'column_mapping' => $import->column_mapping ?? [],
            'date_format' => $import->date_format ?? 'd.m.Y',
            'amount_format' => $import->amount_format ?? '1,234.56',
            'amount_type_strategy' => $import->amount_type_strategy ?? 'signed_amount',
            'currency' => $import->currency ?? 'EUR',
            'import_id' => $import->id,
            'delimiter' => $import->metadata['delimiter'] ?? ',',
            'quote_char' => $import->metadata['quote_char'] ?? '"',
            'skip_header' => true,
            'headers' => $import->metadata['headers'] ?? [],
            'overrides' => $overrides,
        ];
    }

    /**
     * Update import status based on results.
     */
    private function updateImportStatus(Import $import, BatchResultInterface $batch, ?TransactionPersistenceResult $persistenceResult = null): void
    {
        $processed = $batch->getSuccessCount();
        $failed = $batch->getFailedCount();
        $skipped = $batch->getSkippedCount();
        $total = $batch->getTotalProcessed();

        // Add SQL failures to the failed count
        $sqlFailures = $persistenceResult ? $persistenceResult->getSqlFailureCount() : 0;
        $failed += $sqlFailures;

        // Determine status with improved logic
        if ($processed === 0 && $total > 0) {
            // All transactions failed
            $status = Import::STATUS_FAILED;
        } elseif ($failed > 0 || ($skipped > 0 && $skipped > ($processed * 0.1))) {
            // Some failures occurred, or more than 10% were skipped
            $status = Import::STATUS_PARTIALLY_FAILED;
        } elseif ($skipped > 0) {
            // Only duplicates/minor skips
            $status = Import::STATUS_COMPLETED_SKIPPED_DUPLICATES;
        } else {
            // All transactions processed successfully
            $status = Import::STATUS_COMPLETED;
        }

        $import->update([
            'status' => $status,
            'processed_rows' => $processed,
            'failed_rows' => $failed,
            'processed_at' => now(),
            'metadata' => array_merge($import->metadata ?? [], [
                'skipped_rows' => $skipped,
                'failed_rows' => $failed,
                'sql_failures' => $sqlFailures,
                'processed_rows' => $processed,
                'total_rows' => $total,
            ]),
        ]);

        Log::info('Import completed', [
            'import_id' => $import->id,
            'status' => $status,
            'processed' => $processed,
            'failed' => $failed,
            'sql_failures' => $sqlFailures,
            'skipped' => $skipped,
        ]);
    }

    /**
     * Format results for response.
     */
    private function formatResults(BatchResultInterface $result): array
    {
        return [
            'processed' => $result->getSuccessCount(),
            'failed' => $result->getFailedCount(),
            'skipped' => $result->getSkippedCount(),
            'total_rows' => $result->getTotalProcessed(),
            'success' => $result->isCompleteSuccess(),
        ];
    }
}
