<?php

namespace App\Services\Csv;

use ArrayAccess;
use Countable;
use InvalidArgumentException;
use Iterator;

/**
 * Represents CSV file data with headers and rows.
 * Provides methods for data manipulation and access.
 */
class CsvData implements ArrayAccess, Countable, Iterator
{
    private array $headers;

    private array $rows;

    private int $position = 0;

    /**
     * Initializes a new CsvData instance with optional headers and rows.
     *
     * @param array $headers The CSV headers.
     * @param array $rows The CSV rows.
     */
    public function __construct(array $headers = [], array $rows = [])
    {
        $this->headers = $headers;
        $this->rows = $rows;
    }

    /****
     * Creates a CsvData instance from an array containing 'headers' and 'rows' keys.
     *
     * @param array $data An array with 'headers' and 'rows' keys representing CSV data.
     * @return self A new CsvData instance initialized with the provided headers and rows.
     */
    public static function fromArray(array $data): self
    {
        $headers = $data['headers'] ?? [];
        $rows = $data['rows'] ?? [];

        return new self($headers, $rows);
    }

    /**
     * Returns all CSV headers.
     *
     * @return array The array of header names.
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Returns the header at the specified index, or null if the index does not exist.
     *
     * @param int $index The zero-based index of the header.
     * @return string|null The header name at the given index, or null if not found.
     */
    public function getHeader(int $index): ?string
    {
        return $this->headers[$index] ?? null;
    }

    /**
     * Returns the index of the specified header name, or null if the header does not exist.
     *
     * @param string $headerName The name of the header to search for.
     * @return int|null The index of the header, or null if not found.
     */
    public function getHeaderIndex(string $headerName): ?int
    {
        $index = array_search($headerName, $this->headers, true);

        return $index !== false ? $index : null;
    }

    /**
     * Determines whether a header with the specified name exists in the CSV data.
     *
     * @param string $headerName The name of the header to check.
     * @return bool True if the header exists; otherwise, false.
     */
    public function hasHeader(string $headerName): bool
    {
        return in_array($headerName, $this->headers, true);
    }

    /**
     * Returns all rows in the CSV data as an array.
     *
     * @return array The array of rows, each represented as an indexed array.
     */
    public function getRows(): array
    {
        return $this->rows;
    }

    /**
     * Returns the row at the specified index, or null if the index does not exist.
     *
     * @param int $index The zero-based index of the row to retrieve.
     * @return array|null The row as an array, or null if not found.
     */
    public function getRow(int $index): ?array
    {
        return $this->rows[$index] ?? null;
    }

    /**
     * Returns the row at the specified index as an associative array keyed by headers.
     *
     * @param int $index The index of the row to retrieve.
     * @return array|null The row as an associative array, or null if the row does not exist.
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
     * Returns all rows as associative arrays keyed by their corresponding headers.
     *
     * @return array An array of associative arrays, each representing a row with header names as keys.
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
     * Returns the values of a column identified by the given header name.
     *
     * @param string $headerName The name of the header whose column values to retrieve.
     * @return array The values of the specified column, with null for missing values in rows.
     * @throws InvalidArgumentException If the header name does not exist.
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
     * Returns the values of a column by its index.
     *
     * @param int $index The zero-based index of the column to retrieve.
     * @return array An array containing the values of the specified column for each row.
     * @throws InvalidArgumentException If the column index is out of bounds.
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
     * Returns a new CsvData instance containing only the rows that satisfy the given callback condition.
     *
     * @param callable $callback A function that determines whether a row should be included.
     * @return self A new CsvData instance with filtered rows.
     */
    public function filter(callable $callback): self
    {
        $filteredRows = array_filter($this->rows, $callback);

        return new self($this->headers, array_values($filteredRows));
    }

    /**
     * Returns a new CsvData instance with rows transformed by the given callback.
     *
     * The callback is applied to each row, and the resulting rows are used to create a new CsvData object with the same headers.
     *
     * @param callable $callback A function to apply to each row.
     * @return self A new CsvData instance with mapped rows.
     */
    public function map(callable $callback): self
    {
        $mappedRows = array_map($callback, $this->rows);

        return new self($this->headers, $mappedRows);
    }

    /**
     * Returns a new CsvData instance containing a subset of rows starting at the specified offset.
     *
     * @param int $offset The starting index of the subset.
     * @param int|null $length The number of rows to include, or null to include all remaining rows.
     * @return self A new CsvData instance with the selected rows.
     */
    public function slice(int $offset, ?int $length = null): self
    {
        $slicedRows = array_slice($this->rows, $offset, $length);

        return new self($this->headers, $slicedRows);
    }

    /**
     * Returns a new CsvData instance containing the first N rows.
     *
     * @param int $count The number of rows to include.
     * @return self A new CsvData instance with up to the first N rows.
     */
    public function take(int $count): self
    {
        return $this->slice(0, $count);
    }

    /**
     * Returns a new CsvData instance with the first N rows skipped.
     *
     * @param int $count The number of rows to skip from the beginning.
     * @return self A new CsvData instance containing the remaining rows.
     */
    public function skip(int $count): self
    {
        return $this->slice($count);
    }

    /**
     * Adds a new row to the CSV data.
     *
     * @param array $row The row to add, as an indexed array. The number of elements must match the number of headers.
     * @throws InvalidArgumentException If the row does not have the same number of columns as the headers.
     */
    public function addRow(array $row): void
    {
        // Ensure row has same number of columns as headers
        if (count($row) !== count($this->headers)) {
            throw new InvalidArgumentException(
                'Row must have '.count($this->headers).' columns, got '.count($row)
            );
        }

        $this->rows[] = $row;
    }

    /**
     * Adds a new row to the CSV data using an associative array keyed by headers.
     *
     * Any missing header keys in the input array will result in null values for those columns.
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
     * Removes the row at the specified index and re-indexes the remaining rows.
     *
     * If the index does not exist, no action is taken.
     *
     * @param int $index The index of the row to remove.
     */
    public function removeRow(int $index): void
    {
        if (isset($this->rows[$index])) {
            unset($this->rows[$index]);
            $this->rows = array_values($this->rows); // Re-index
        }
    }

    /**
     * Returns the total number of rows in the CSV data.
     *
     * @return int The number of rows.
     */
    public function count(): int
    {
        return count($this->rows);
    }

    /**
     * Determines whether the CSV data contains any rows.
     *
     * @return bool True if there are no rows, false otherwise.
     */
    public function isEmpty(): bool
    {
        return empty($this->rows);
    }

    /**
     * Returns the CSV data as an array with 'rows' and, if present, 'headers' keys.
     *
     * @return array The CSV data structured as ['rows' => array, 'headers' => array|null].
     */
    public function toArray(): array
    {
        $result = ['rows' => $this->rows];
        if (! empty($this->headers)) {
            $result['headers'] = $this->headers;
        }

        return $result;
    }

    /**
     * Returns statistics about the CSV data, including total rows, total columns, whether headers are present, and the headers array.
     *
     * @return array An associative array with keys 'total_rows', 'total_columns', 'has_headers', and 'headers'.
     */
    public function getStats(): array
    {
        return [
            'total_rows' => count($this->rows),
            'total_columns' => count($this->headers),
            'has_headers' => ! empty($this->headers),
            'headers' => $this->headers,
        ];
    }

    /**
     * Determines if a row exists at the specified offset.
     *
     * @param mixed $offset The row index to check.
     * @return bool True if a row exists at the given offset, false otherwise.
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->rows[$offset]);
    }

    /**
     * Retrieves the row at the specified offset.
     *
     * @param mixed $offset The index of the row to retrieve.
     * @return array|null The row at the given offset, or null if not found.
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->getRow($offset);
    }

    /**
     * Sets the row at the specified offset or appends a new row if the offset is null.
     *
     * @param mixed $offset The row index or null to append.
     * @param mixed $value The row data as an array.
     * @throws InvalidArgumentException If the value is not an array.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('Value must be an array');
        }

        if ($offset === null) {
            $this->addRow($value);
        } else {
            $this->rows[$offset] = $value;
        }
    }

    /**
     * Removes the row at the specified offset.
     *
     * @param mixed $offset The index of the row to remove.
     */
    public function offsetUnset(mixed $offset): void
    {
        $this->removeRow($offset);
    }

    /**
     * Returns the current row in the iteration.
     *
     * @return array|null The current row as an array, or null if the position is invalid.
     */
    public function current(): mixed
    {
        return $this->getRow($this->position);
    }

    /**
     * Returns the current iterator position.
     *
     * @return int The current position in the rows array.
     */
    public function key(): mixed
    {
        return $this->position;
    }

    /**
     * Advances the iterator to the next row.
     */
    public function next(): void
    {
        $this->position++;
    }

    /**
     * Resets the iterator position to the beginning of the rows.
     */
    public function rewind(): void
    {
        $this->position = 0;
    }

    /**
     * Determines if the current iterator position is valid.
     *
     * @return bool True if the current position points to an existing row, false otherwise.
     */
    public function valid(): bool
    {
        return isset($this->rows[$this->position]);
    }
}
