<?php

namespace App\Services\TransactionImport;

/**
 * Captures the result of transaction persistence including SQL failures.
 */
class TransactionPersistenceResult
{
    public function __construct(
        private array $sqlFailures = [],
        private int $successCount = 0
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
