<?php

namespace App\Services\Csv;

use ArrayAccess;
use Countable;
use Iterator;
use InvalidArgumentException;

/**
 * Represents CSV file data with headers and rows.
 * Provides methods for data manipulation and access.
 */
class CsvData implements ArrayAccess, Countable, Iterator
{
    private array $headers;
    private array $rows;
    private int $position = 0;

    public function __construct(array $headers = [], array $rows = [])
    {
        $this->headers = $headers;
        $this->rows = $rows;
    }

    /**
     * Create CsvData from array structure returned by CsvProcessor::getRows()
     */
    public static function fromArray(array $data): self
    {
        $headers = $data['headers'] ?? [];
        $rows = $data['rows'] ?? [];

        return new self($headers, $rows);
    }

    /**
     * Get all headers
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Get header at specific index
     */
    public function getHeader(int $index): ?string
    {
        return $this->headers[$index] ?? null;
    }

    /**
     * Get header index by name
     */
    public function getHeaderIndex(string $headerName): ?int
    {
        $index = array_search($headerName, $this->headers, true);
        return $index !== false ? $index : null;
    }

    /**
     * Check if header exists
     */
    public function hasHeader(string $headerName): bool
    {
        return in_array($headerName, $this->headers, true);
    }

    /**
     * Get all rows
     */
    public function getRows(): array
    {
        return $this->rows;
    }

    /**
     * Get row at specific index
     */
    public function getRow(int $index): ?array
    {
        return $this->rows[$index] ?? null;
    }

    /**
     * Get row as associative array using headers as keys
     */
    public function getRowAsAssoc(int $index): ?array
    {
        $row = $this->getRow($index);
        if ($row === null) {
            return null;
        }

        $assocRow = [];
        foreach ($this->headers as $headerIndex => $header) {
            $assocRow[$header] = $row[$headerIndex] ?? null;
        }

        return $assocRow;
    }

    /**
     * Get all rows as associative arrays
     */
    public function getRowsAsAssoc(): array
    {
        $assocRows = [];
        foreach ($this->rows as $index => $row) {
            $assocRows[$index] = $this->getRowAsAssoc($index);
        }
        return $assocRows;
    }

    /**
     * Get column by header name
     */
    public function getColumn(string $headerName): array
    {
        $index = $this->getHeaderIndex($headerName);
        if ($index === null) {
            throw new InvalidArgumentException("Header '{$headerName}' not found");
        }

        $column = [];
        foreach ($this->rows as $row) {
            $column[] = $row[$index] ?? null;
        }

        return $column;
    }

    /**
     * Get column by index
     */
    public function getColumnByIndex(int $index): array
    {
        if ($index < 0 || $index >= count($this->headers)) {
            throw new InvalidArgumentException("Column index {$index} out of bounds");
        }

        $column = [];
        foreach ($this->rows as $row) {
            $column[] = $row[$index] ?? null;
        }

        return $column;
    }

    /**
     * Filter rows by condition
     */
    public function filter(callable $callback): self
    {
        $filteredRows = array_filter($this->rows, $callback);
        return new self($this->headers, array_values($filteredRows));
    }

    /**
     * Map rows using callback
     */
    public function map(callable $callback): self
    {
        $mappedRows = array_map($callback, $this->rows);
        return new self($this->headers, $mappedRows);
    }

    /**
     * Get subset of rows
     */
    public function slice(int $offset, ?int $length = null): self
    {
        $slicedRows = array_slice($this->rows, $offset, $length);
        return new self($this->headers, $slicedRows);
    }

    /**
     * Get first N rows
     */
    public function take(int $count): self
    {
        return $this->slice(0, $count);
    }

    /**
     * Skip first N rows
     */
    public function skip(int $count): self
    {
        return $this->slice($count);
    }

    /**
     * Add a new row
     */
    public function addRow(array $row): void
    {
        // Ensure row has same number of columns as headers
        if (count($row) !== count($this->headers)) {
            throw new InvalidArgumentException(
                "Row must have " . count($this->headers) . " columns, got " . count($row)
            );
        }

        $this->rows[] = $row;
    }

    /**
     * Add a new row using associative array
     */
    public function addRowAssoc(array $assocRow): void
    {
        $row = [];
        foreach ($this->headers as $header) {
            $row[] = $assocRow[$header] ?? null;
        }
        $this->addRow($row);
    }

    /**
     * Remove row at index
     */
    public function removeRow(int $index): void
    {
        if (isset($this->rows[$index])) {
            unset($this->rows[$index]);
            $this->rows = array_values($this->rows); // Re-index
        }
    }

    /**
     * Get total number of rows
     */
    public function count(): int
    {
        return count($this->rows);
    }

    /**
     * Check if CSV data is empty
     */
    public function isEmpty(): bool
    {
        return empty($this->rows);
    }

    /**
     * Get CSV data as array (for backward compatibility)
     */
    public function toArray(): array
    {
        $result = ['rows' => $this->rows];
        if (!empty($this->headers)) {
            $result['headers'] = $this->headers;
        }
        return $result;
    }

    /**
     * Get statistics about the CSV data
     */
    public function getStats(): array
    {
        return [
            'total_rows' => count($this->rows),
            'total_columns' => count($this->headers),
            'has_headers' => !empty($this->headers),
            'headers' => $this->headers,
        ];
    }

    // ArrayAccess implementation
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->rows[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->getRow($offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('Value must be an array');
        }

        if ($offset === null) {
            $this->addRow($value);
        } else {
            $this->rows[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->removeRow($offset);
    }

    // Iterator implementation
    public function current(): mixed
    {
        return $this->getRow($this->position);
    }

    public function key(): mixed
    {
        return $this->position;
    }

    public function next(): void
    {
        $this->position++;
    }

    public function rewind(): void
    {
        $this->position = 0;
    }

    public function valid(): bool
    {
        return isset($this->rows[$this->position]);
    }
}
