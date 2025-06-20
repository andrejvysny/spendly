<?php

namespace App\Services\TransactionImport;

use App\Contracts\Import\BatchResultInterface;
use App\Models\Transaction;
use App\Services\Csv\CsvProcessResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Handles persisting transactions to the database.
 */
class TransactionPersister
{
    private array $batchQueue = [];

    private int $batchSize = 500;

    public function __construct() {}

    public function persistBatch(BatchResultInterface $transactions): void
    {
        foreach ($transactions->getSuccessResults() as $transaction) {
            assert($transaction instanceof CsvProcessResult, 'Expected CsvProcessResult in batch results');

            $transaction->isSuccess() or throw new \InvalidArgumentException('Transaction must be successful to persist');

            $dto = $transaction->getData();
            assert($dto instanceof TransactionDto, 'Expected TransactionDto in persistBatch');
            $dto->set('metadata', array_merge(
                $dto->get('metadata', []),
                [
                    'processing_metadata' => $transaction->getMetadata(),
                    'processing_message' => $transaction->getMessage(),
                ]
            ));
            $this->addToBatch($dto);

            if (count($this->batchQueue) > $this->batchSize) {
                // Process the batch if it exceeds the batch size
                $this->processBatch();
            }
        }

        // Process any remaining in the batch
        if (! empty($this->batchQueue)) {
            $this->processBatch();
        }

    }

    /**
     * Add transaction to batch queue.
     */
    private function addToBatch(TransactionDto $data): void
    {
        $this->batchQueue[] = $data;
    }

    /**
     * Process the current batch of transactions.
     */
    private function processBatch(): void
    {
        if (empty($this->batchQueue)) {
            return;
        }

        $batch = $this->batchQueue;
        $this->batchQueue = []; // Clear the queue

        try {
            DB::transaction(function () use ($batch) {
                // Use insert for better performance with large batches
                $chunks = array_chunk($batch, 100); // Process in smaller chunks to avoid memory issues

                foreach ($chunks as $chunk) {
                    $insertData = [];
                    $firstRowColumns = null;
                    foreach ($chunk as $data) {
                        assert($data instanceof TransactionDto);
                        $prepared = $this->prepareForInsert($data->toArray());

                        // Validate column consistency
                        if ($firstRowColumns === null) {
                            $firstRowColumns = array_keys($prepared);
                        } elseif (array_keys($prepared) !== $firstRowColumns) {
                            Log::critical('Inconsistent columns in batch data', [
                                'expected_columns' => $firstRowColumns,
                                'actual_columns' => array_keys($prepared),
                            ]);
                            throw new \RuntimeException('Inconsistent columns in batch data');
                        }

                        $insertData[] = $prepared;
                    }

                    // Bulk insert
                    Transaction::insert($insertData);
                }

                Log::info('Batch processed successfully', [
                    'batch_size' => count($batch),
                ]);
            });
        } catch (\Exception $e) {
            Log::error('Batch processing failed', [
                'error' => $e->getMessage(),
                'batch_size' => count($batch),
            ]);

            // Fall back to individual inserts
            foreach ($batch as $data) {
                assert($data instanceof TransactionDto, 'Expected TransactionDto in batch results');
                try {
                    Transaction::create(
                        $this->prepareForInsert($data->toArray())
                    );
                } catch (\Exception $individualError) {
                    Log::error('Individual transaction insert failed', [
                        'error' => $individualError->getMessage(),
                        'transaction_id' => $data['transaction_id'] ?? 'unknown',
                    ]);
                }
            }
        }
    }

    /**
     * Prepare transaction data for bulk insert.
     */
    private function prepareForInsert(array $data): array
    {
        // Convert metadata and import_data to JSON strings
        if (isset($data['metadata']) && is_array($data['metadata'])) {
            $data['metadata'] = json_encode($data['metadata']);
        }

        if (isset($data['import_data']) && is_array($data['import_data'])) {
            $data['import_data'] = json_encode($data['import_data']);
        }

        // Add timestamps
        $data['created_at'] = now();
        $data['updated_at'] = now();

        // Remove any non-fillable fields
        $fillable = (new Transaction)->getFillable();

        return array_intersect_key($data, array_flip($fillable));
    }

    /**
     * Force process any remaining transactions in the batch.
     */
    public function flush(): void
    {
        if (! empty($this->batchQueue)) {
            $this->processBatch();
        }
    }
}
