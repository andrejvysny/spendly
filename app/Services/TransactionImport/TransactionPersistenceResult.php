<?php

namespace App\Services\TransactionImport;

/**
 * Captures the result of transaction persistence including SQL failures.
 */
class TransactionPersistenceResult
{
    public function __construct(
        private array $sqlFailures = [],
        private int $successCount = 0,
        private array $createdTransactionIds = [],
    ) {}

    /**
     * Add a SQL failure.
     */
    public function addSqlFailure(TransactionDto $transactionDto, \Exception $exception, array $metadata = []): void
    {
        $this->sqlFailures[] = [
            'transaction_dto' => $transactionDto,
            'exception' => $exception,
            'metadata' => $metadata,
        ];
    }

    /**
     * Get SQL failures.
     */
    public function getSqlFailures(): array
    {
        return $this->sqlFailures;
    }

    /**
     * Check if there are SQL failures.
     */
    public function hasSqlFailures(): bool
    {
        return ! empty($this->sqlFailures);
    }

    /**
     * Get the count of SQL failures.
     */
    public function getSqlFailureCount(): int
    {
        return count($this->sqlFailures);
    }

    /**
     * Set success count.
     */
    public function setSuccessCount(int $count): void
    {
        $this->successCount = $count;
    }

    /**
     * Get success count.
     */
    public function getSuccessCount(): int
    {
        return $this->successCount;
    }

    /**
     * @param  array<int>  $transactionIds
     */
    public function addCreatedTransactionIds(array $transactionIds): void
    {
        $normalized = array_values(array_unique(array_filter(array_map('intval', $transactionIds), static fn (int $id): bool => $id > 0)));
        if ($normalized === []) {
            return;
        }

        $this->createdTransactionIds = array_values(array_unique(array_merge($this->createdTransactionIds, $normalized)));
    }

    /**
     * @return array<int>
     */
    public function getCreatedTransactionIds(): array
    {
        return $this->createdTransactionIds;
    }

    /**
     * Check if this is a fingerprint constraint violation.
     */
    public static function isFingerprintConstraintViolation(\Exception $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'UNIQUE constraint failed: transactions.fingerprint') ||
               str_contains($message, 'Duplicate entry') && str_contains($message, 'fingerprint') ||
               str_contains($message, 'violates unique constraint') && str_contains($message, 'fingerprint');
    }

    /**
     * Determine error type from exception.
     */
    public static function determineErrorType(\Exception $exception): string
    {
        if (self::isFingerprintConstraintViolation($exception)) {
            return 'duplicate';
        }

        // Check for other constraint violations
        $message = $exception->getMessage();
        if (str_contains($message, 'UNIQUE constraint') ||
            str_contains($message, 'Duplicate entry') ||
            str_contains($message, 'violates unique constraint')) {
            return 'duplicate';
        }

        // Default to processing error
        return 'processing_error';
    }
}
