<?php

namespace App\Services\TransactionImport;

use App\Contracts\Import\BatchResultInterface;
use App\Contracts\Repositories\TransactionRepositoryInterface;
use App\Contracts\RuleEngine\RuleEngineInterface;
use App\Events\TransactionCreated;
use App\Models\RuleEngine\Rule;
use App\Models\Transaction;
use App\Services\Csv\CsvProcessResult;
use Illuminate\Support\Facades\Log;

/**
 * Handles persisting transactions to the database.
 */
class TransactionPersister
{
    private array $batchQueue = [];

    private int $batchSize = 500;

    private TransactionPersistenceResult $persistenceResult;

    public function __construct(
        private readonly RuleEngineInterface $ruleEngine,
        private readonly TransactionRepositoryInterface $transactions
    ) {
        $this->persistenceResult = new TransactionPersistenceResult();
    }

    public function persistBatch(BatchResultInterface $transactions): TransactionPersistenceResult
    {
        // Reset persistence result for this batch
        $this->persistenceResult = new TransactionPersistenceResult();

        foreach ($transactions->getSuccessResults() as $transaction) {
            assert($transaction instanceof CsvProcessResult, 'Expected CsvProcessResult in batch results');

            $transaction->isSuccess() or throw new \InvalidArgumentException('Transaction must be successful to persist');

            $dto = $transaction->getData();
            assert($dto instanceof TransactionDto, 'Expected TransactionDto in persistBatch');

            // Ensure metadata is always an array before merging
            $metadata = $dto->get('metadata', []);
            if (! is_array($metadata)) {
                $metadata = [];
            }

            $dto->set('metadata', array_merge(
                $metadata,
                [
                    'processing_metadata' => $transaction->getMetadata(),
                    'processing_message' => $transaction->getMessage(),
                ]
            ));
            $this->addToBatch($dto);

            if (count($this->batchQueue) >= $this->batchSize) {
                // Process the batch if it exceeds the batch size
                $this->processBatch();
            }
        }

        // Process any remaining in the batch
        if (! empty($this->batchQueue)) {
            $this->processBatch();
        }

        return $this->persistenceResult;
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
            $this->transactions->transaction(function () use ($batch) {
                // Use insert for better performance with large batches
                $chunks = array_chunk($batch, 100); // Process in smaller chunks to avoid memory issues

                foreach ($chunks as $chunk) {
                    $insertData = [];
                    $idPairs = [];
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

                        // Track identifiers for fetching models after insert (composite)
                        if (isset($prepared['transaction_id']) && isset($prepared['account_id'])) {
                            $idPairs[] = [$prepared['account_id'], $prepared['transaction_id']];
                        }

                        $insertData[] = $prepared;
                    }

                    // Bulk insert via repository
                    $this->transactions->createBatch($insertData);

                    // Load inserted transactions and apply rules synchronously before commit
                    if (! empty($idPairs)) {
                        $insertedTransactions = $this->transactions->findByAccountAndTransactionIdPairs($idPairs);

                        if ($insertedTransactions->isNotEmpty()) {
                            // Use the user from the first transaction's account; all are same account in an import
                            $user = $insertedTransactions->first()->account->user;
                            $this->ruleEngine
                                ->setUser($user)
                                ->processTransactions($insertedTransactions, Rule::TRIGGER_TRANSACTION_CREATED);

                            // Fire created events for other subscribers without re-running rules
                            foreach ($insertedTransactions as $t) {
                                event(new TransactionCreated($t, false));
                            }
                        }
                    }
                }

                Log::info('Batch processed successfully', [
                    'batch_size' => count($batch),
                ]);

                // Update success count for successful batch
                $this->persistenceResult->setSuccessCount(
                    $this->persistenceResult->getSuccessCount() + count($batch)
                );
            });
        } catch (\Exception $e) {
            Log::error('Batch processing failed', [
                'error' => $e->getMessage(),
                'batch_size' => count($batch),
            ]);

            // Fall back to individual inserts but still apply rules synchronously, all within a transaction
            $this->transactions->transaction(function () use ($batch) {
                $created = collect();
                foreach ($batch as $data) {
                    assert($data instanceof TransactionDto, 'Expected TransactionDto in batch results');
                    try {
                        $transaction = $this->transactions->createOne(
                            $this->prepareForInsert($data->toArray())
                        );
                        // Eager load needed relations for rule processing
                        $transaction->load(['account.user', 'tags', 'category', 'merchant']);
                        $created->push($transaction);
                    } catch (\Exception $individualError) {
                        Log::error('Individual transaction insert failed', [
                            'error' => $individualError->getMessage(),
                            'transaction_id' => $data->get('transaction_id', 'unknown'),
                        ]);

                        // Collect SQL failure for later processing
                        $this->persistenceResult->addSqlFailure($data, $individualError, [
                            'transaction_id' => $data->get('transaction_id', 'unknown'),
                            'account_id' => $data->get('account_id', 'unknown'),
                            'fingerprint' => $data->get('fingerprint', 'unknown'),
                        ]);
                    }
                }

                // Update success count
                $this->persistenceResult->setSuccessCount(
                    $this->persistenceResult->getSuccessCount() + $created->count()
                );

                if ($created->isNotEmpty()) {
                    $user = $created->first()->account->user;
                    $this->ruleEngine
                        ->setUser($user)
                        ->processTransactions($created, Rule::TRIGGER_TRANSACTION_CREATED);

                    foreach ($created as $t) {
                        event(new TransactionCreated($t, false));
                    }
                }
            });
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
