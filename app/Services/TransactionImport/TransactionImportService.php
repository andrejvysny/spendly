<?php

namespace App\Services\TransactionImport;

use App\Contracts\Import\BatchResultInterface;
use App\Models\Import;
use App\Services\Csv\CsvProcessor;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates the transaction import process.
 * Coordinates between CSV processing and transaction domain.
 */
readonly class TransactionImportService
{
    /**
     * Initializes the TransactionImportService with required dependencies for CSV processing, row processing, and transaction persistence.
     */
    public function __construct(
        private CsvProcessor $csvProcessor,
        private TransactionRowProcessor $rowProcessor,
        private TransactionPersister $persister,
    ) {}

    /**
     * Executes the transaction import process for a given import model and account.
     *
     * Initiates CSV row processing, updates import status throughout the workflow, persists results, and returns a summary of the import outcome. If an error occurs, marks the import as failed and rethrows the exception.
     *
     * @param Import $import The import model containing transaction data and metadata.
     * @param int $accountId The ID of the account to import transactions into.
     * @return array Summary of the import results, including counts of processed, successful, failed, and skipped rows.
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
            // Prepare configuration for processing
            $configuration = $this->prepareConfiguration($import, $accountId);
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

            $this->persister->persistBatch($batch);

            $this->updateImportStatus($import, $batch);

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
     * Returns a preview of the import data limited to the specified number of rows.
     *
     * Processes the import file in preview mode and collects successfully processed row data up to the requested preview size.
     *
     * @param Import $import The import model containing file and metadata.
     * @param int $previewSize The maximum number of preview rows to return. Defaults to 10.
     * @return array An array of successfully processed preview rows.
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
            $configuration
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
     * Builds and returns a configuration array for transaction import processing based on the import model and account ID.
     *
     * @param Import $import The import model containing mapping, formatting, and metadata settings.
     * @param int $accountId The ID of the account associated with the import.
     * @return array The configuration array for processing the import.
     */
    private function prepareConfiguration(Import $import, int $accountId): array
    {
        return [
            'account_id' => $accountId,
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
        ];
    }

    /**
     * Updates the import record's status and metadata based on batch processing results.
     *
     * Determines the appropriate import status (failed, partially failed, completed with skipped duplicates, or completed) using counts of processed, failed, and skipped rows, then updates the import model accordingly.
     */
    private function updateImportStatus(Import $import, BatchResultInterface $batch): void
    {
        $processed = $batch->getSuccessCount();
        $failed = $batch->getFailedCount();
        $skipped = $batch->getSkippedCount();
        $total = $batch->getTotalProcessed();

        // Determine status
        if ($processed === 0 && $total > 0) {
            $status = Import::STATUS_FAILED;
        } elseif ($failed > $processed && $total > 10) {
            $status = Import::STATUS_PARTIALLY_FAILED;
        } elseif ($skipped > 0) {
            $status = Import::STATUS_COMPLETED_SKIPPED_DUPLICATES;
        } else {
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
                'processed_rows' => $processed,
                'total_rows' => $total,
            ]),
        ]);

        Log::info('Import completed', [
            'import_id' => $import->id,
            'status' => $status,
            'processed' => $processed,
            'failed' => $failed,
            'skipped' => $skipped,
        ]);
    }

    /**
     * Returns a summary array of batch processing results.
     *
     * The summary includes counts of processed, failed, and skipped rows, the total number of rows processed, and a flag indicating complete success.
     *
     * @param BatchResultInterface $result The batch result to summarize.
     * @return array Summary of the batch processing results.
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
