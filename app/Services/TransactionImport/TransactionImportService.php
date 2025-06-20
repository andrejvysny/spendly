<?php

namespace App\Services\TransactionImport;

use App\Contracts\Import\BatchResultInterface;
use App\Models\Import;
use App\Services\Csv\CsvProcessor;
use App\Services\DuplicateTransactionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates the transaction import process.
 * Coordinates between CSV processing and transaction domain.
 */
readonly class TransactionImportService
{
    public function __construct(
        private CsvProcessor            $csvProcessor,
        private TransactionRowProcessor $rowProcessor,
        private TransactionPersister    $persister,
    ) {}

    /**
     * Process a transaction import.
     *
     * @param Import $import The import model
     * @param int $accountId The account to import transactions into
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
            // Prepare configuration for processing
            $configuration = $this->prepareConfiguration($import, $accountId);
            $this->rowProcessor->configure($configuration);

            $batch = $this->csvProcessor->processRows(
                "imports/{$import->filename}",
                $import->metadata['delimiter'] ?? ";",
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


            $this->persister->persistBatch($batch, $configuration);


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
     * Get a preview of the import data.
     *
     * @param Import $import
     * @param int $previewSize
     * @return array
     */
    public function getPreview(Import $import, int $previewSize = 10): array
    {
        $configuration = $this->prepareConfiguration($import, null);
        $configuration['preview_mode'] = true;
        $configuration['max_rows'] = $previewSize + 1; // +1 for header

        $result = $this->csvProcessor->processRows(
            "imports/{$import->filename}",
            $import->metadata['delimiter'] ?? ";",
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
     * Prepare configuration for processing.
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
     * Update import status based on results.
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
