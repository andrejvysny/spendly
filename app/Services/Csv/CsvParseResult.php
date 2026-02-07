<?php

declare(strict_types=1);

namespace App\Services\Csv;

/**
 * Result of parsing a CSV file with row-level error isolation.
 * Successful rows and per-line errors are captured separately.
 */
final class CsvParseResult
{
    /**
     * @param  array<int, array{line: int, data: array<int, string|null>}>  $rows
     * @param  array<int, array{line: int, error: string, raw: string}>  $errors
     */
    public function __construct(
        private readonly array $rows,
        private readonly array $errors,
        private readonly array $headers,
    ) {}

    /** @return array<int, array{line: int, data: array<int, string|null>}> */
    public function getRows(): array
    {
        return $this->rows;
    }

    /** @return array<int, array{line: int, error: string, raw: string}> */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function getRowCount(): int
    {
        return count($this->rows);
    }

    public function getErrorCount(): int
    {
        return count($this->errors);
    }
}
