<?php

namespace App\Services\Csv;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CsvProcessor
{
    /**
     * Reads rows from a CSV file and returns the headers and data rows.
     *
     * Validates the file's existence, opens it, reads the header row, and then reads up to the specified number of data rows (or all rows if not specified). Returns a CsvData object containing the headers and data rows.
     *
     * @param string $path The path to the CSV file.
     * @param string $delimiter The delimiter character used in the CSV file.
     * @param string $quoteChar The quote character used in the CSV file.
     * @param int|null $rows The maximum number of data rows to read, or null to read all rows.
     * @return CsvData The headers and data rows from the CSV file.
     * @throws \RuntimeException If the file does not exist or cannot be opened.
     */
    public function getRows(string $path, string $delimiter, string $quoteChar, ?int $rows = null): CsvData
    {
        Log::debug('Reading CSV data', [
            'path' => $path,
            'rows' => $rows ?? 'all',
            'delimiter' => $delimiter,
            'quote_char' => $quoteChar,
        ]);

        if (! Storage::exists($path)) {
            Log::error('CSV file not found', ['path' => $path]);
            throw new \RuntimeException('CSV file not found: '.$path);
        }
        $file = fopen(Storage::path($path), 'r');
        if (! $file) {
            throw new \RuntimeException('Unable to open CSV file');
        }

        $dataRows = [];

        // Read headers first if we need them
        $headers = $this->safelyGetCSVLine($file, $delimiter, $quoteChar);
        Log::debug('Read headers', ['count' => count($headers)]);

        // Read data rows
        $rowCount = 0;
        while (($rows === null || $rowCount < $rows) && ! feof($file)) {
            $row = $this->safelyGetCSVLine($file, $delimiter, $quoteChar);
            if ($row === false) {
                break;
            }

            $dataRows[] = $row;
            $rowCount++;

            // Log progress for large files
            if ($rowCount % 1000 === 0) {
                Log::debug('Reading progress', ['rows_read' => $rowCount]);
            }
        }

        fclose($file);

        Log::debug('Read rows', ['count' => count($dataRows)]);

        return new CsvData($headers, $dataRows);
    }

    /**
     * Preprocesses an uploaded CSV file by normalizing its encoding and content.
     *
     * Detects the file's encoding (including UTF-16 variants), converts it to UTF-8 if necessary, removes null bytes and any UTF-8 BOM, and writes the cleaned content to a temporary file.
     *
     * @param UploadedFile|null $file The uploaded CSV file to preprocess.
     * @param string $delimiter The delimiter character used in the CSV.
     * @param string $quoteChar The quote character used in the CSV.
     * @return false|string The path to the preprocessed temporary file, or false on failure.
     */
    public function preprocessCSV(?UploadedFile $file, string $delimiter, string $quoteChar): false|string
    {
        Log::debug('Starting CSV preprocessing', [
            'delimiter' => $delimiter,
            'quote_char' => $quoteChar,
        ]);

        // Create a temporary file for the preprocessed content
        $tempFile = tempnam(sys_get_temp_dir(), 'csv_');
        $handle = fopen($tempFile, 'w');

        // Read the first few bytes to detect BOM
        $content = file_get_contents($file->getPathname(), false, null, 0, 10000);

        // Check for BOM and detect encoding
        $encoding = $this->detectEncoding($content);
        Log::debug('Detected file encoding', ['encoding' => $encoding]);

        // Convert file content to UTF-8 based on detected encoding
        $fullContent = file_get_contents($file->getPathname());

        if ($encoding != 'UTF-8') {
            $fullContent = mb_convert_encoding($fullContent, 'UTF-8', $encoding);
            Log::debug('Converted file from detected encoding to UTF-8');
        }

        // Remove NULL bytes
        $fullContent = str_replace("\0", '', $fullContent);
        Log::debug('Removed null bytes from content');

        // Remove BOM if present
        $fullContent = preg_replace('/^\xEF\xBB\xBF/', '', $fullContent);

        // Write the processed content to the temp file
        fwrite($handle, $fullContent);
        fclose($handle);

        Log::debug('CSV preprocessing completed', ['temp_file' => $tempFile]);

        return $tempFile;
    }

    /**
     * Processes rows from a CSV file by applying a callback to each row.
     *
     * Iterates through the CSV file at the given path, optionally skipping the header row, and invokes the provided callback for each data row. Collects the results in a CsvBatchResult object, tracking successes, failures, and skipped rows. Processing stops after the specified number of rows if provided.
     *
     * @param string $path The path to the CSV file.
     * @param string $delimiter The delimiter character used in the CSV file.
     * @param string $quoteChar The quote character used in the CSV file.
     * @param CsvRowProcessor $callback The callback function to process each row.
     * @param bool $skip_header Whether to skip the first row as headers (default: true).
     * @param int|null $num_rows The maximum number of rows to process, or all if null.
     * @param int $offset The number of rows to skip before processing (default: 0).
     * @return CsvBatchResult|null The batch result containing processing outcomes, or null if no rows were processed.
     * @throws \RuntimeException If the CSV file does not exist.
     */
    public function processRows(string $path, string $delimiter, string $quoteChar, CsvRowProcessor $callback, bool $skip_header = true, ?int $num_rows = null, int $offset = 0): ?CsvBatchResult
    {

        Log::debug('Processing rows from CSV', [
            'path' => $path,
            'delimiter' => $delimiter,
            'quote_char' => $quoteChar,
            'num_rows' => $num_rows === null ? 'all' : $num_rows,
        ]);

        // Validate the file path
        if (! Storage::exists($path)) {
            Log::error('CSV file not found', ['path' => $path]);
            throw new \RuntimeException('CSV file not found: '.$path);
        }
        $file = fopen(Storage::path($path), 'r');

        if ($skip_header) {
            $headers = $this->safelyGetCSVLine($file, $delimiter, $quoteChar);
            Log::debug('Read headers', ['count' => count($headers)]);
        }

        $totalRows = 0;
        $batch = new CsvBatchResult;

        while (($row = $this->safelyGetCSVLine($file, $delimiter, $quoteChar)) !== false) {
            $totalRows++;
            try {
                // Skip null lines or empty arrays
                if ($row === null || (is_array($row) && count($row) === 0)) {
                    Log::warning('Skipping empty line', ['row_number' => $skip_header ? $totalRows : $totalRows + 1]);

                    continue;
                }

                $result = $callback($row, [
                    'row_number' => $skip_header ? $totalRows : $totalRows + 1,
                    'headers' => $skip_header ? $headers : null,
                    'delimiter' => $delimiter,
                    'quote_char' => $quoteChar,
                    'file_path' => $path,
                ]);

                $batch->addResult($result);

                if (($batch->getTotalProcessed()) % 100 === 0) {
                    Log::info('Processing progress', [
                        'processed_rows' => $batch->getSuccessCount(),
                        'failed_rows' => $batch->getFailedCount(),
                        'skipped_rows' => $batch->getSkippedCount(),
                    ]);
                }
            } catch (\Exception $e) {
                $batch->addResult(
                    CsvProcessResult::failure(
                        $e->getMessage(),
                        $row,
                    )
                );
                Log::error('Failed to process row', [
                    'error' => $e->getMessage(),
                    'row_number' => $skip_header ? $totalRows : $totalRows + 1,
                    'message' => 'Added to manual review',
                ]);
            }

            if ($totalRows === $num_rows) {
                Log::debug('Reached specified number of rows', ['num_rows' => $num_rows]);
                break;
            }
        }
        fclose($file);

        Log::debug('Processed rows', [
            'total_rows' => $batch->getTotalProcessed(),
            'success_count' => $batch->getSuccessCount(),
            'failed_count' => $batch->getFailedCount(),
            'skipped_count' => $batch->getSkippedCount(),
        ]);

        return $batch;
    }

    /**
     * Detects the character encoding of the given content, with special handling for UTF-16LE, UTF-16BE, and UTF-8 BOMs.
     *
     * Examines byte order marks and content patterns to identify UTF-16 variants, and falls back to `mb_detect_encoding` for other encodings. Defaults to UTF-8 if detection is inconclusive.
     *
     * @param string $content The file content to analyze.
     * @return string The detected encoding (e.g., 'UTF-8', 'UTF-16LE', 'UTF-16BE').
     */
    private function detectEncoding($content)
    {
        // Check for UTF-16LE BOM (FF FE)
        if (substr($content, 0, 2) === "\xFF\xFE") {
            return 'UTF-16LE';
        }

        // Check for UTF-16BE BOM (FE FF)
        if (substr($content, 0, 2) === "\xFE\xFF") {
            return 'UTF-16BE';
        }

        // Check for UTF-8 BOM (EF BB BF)
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            return 'UTF-8';
        }

        // No BOM found, try to detect encoding based on content
        // Check for null bytes which might indicate UTF-16
        if (strpos($content, "\0") !== false) {
            // Detect if it's UTF-16LE or UTF-16BE based on pattern
            if (preg_match('/[\x20-\x7E]\x00[\x20-\x7E]\x00/', $content)) {
                return 'UTF-16LE';
            } elseif (preg_match('/\x00[\x20-\x7E]\x00[\x20-\x7E]/', $content)) {
                return 'UTF-16BE';
            }
        }

        // Try to detect encoding using mb_detect_encoding
        $detectedEncoding = mb_detect_encoding($content, [
            'UTF-8', 'UTF-16LE', 'UTF-16BE', 'ASCII', 'ISO-8859-1', 'ISO-8859-15', 'Windows-1252',
        ], true);

        return $detectedEncoding ?: 'UTF-8'; // Default to UTF-8 if detection fails
    }

    /**
     * Reads a single CSV line from a file resource with robust error handling and data cleaning.
     *
     * Attempts to parse a CSV line using the specified delimiter and quote character, removing null bytes and control characters from each field. If parsing fails or the line is malformed, it tries to recover by manually splitting the raw line. Returns false on end of file or if a valid line cannot be obtained.
     *
     * @param resource $file The file handle to read from.
     * @param string $delimiter The field delimiter character.
     * @param string $quoteChar The field enclosure character.
     * @return array|false The parsed and cleaned CSV fields as an array, or false on failure or end of file.
     */
    private function safelyGetCSVLine($file, string $delimiter, string $quoteChar): false|array
    {
        if (feof($file)) {
            return false;
        }

        try {
            // If quoteChar is empty, use a special character that won't appear in the data
            $effectiveQuoteChar = empty($quoteChar) ? chr(0) : $quoteChar;

            // Set the delimiter and quote character
            $line = fgetcsv($file, 0, $delimiter, $effectiveQuoteChar);

            // Handle end of file
            if ($line === false) {
                return false;
            }

            // Handle invalid line (sometimes appears as an array with one empty string)
            if (is_array($line) && count($line) === 1 && $line[0] === null) {
                return false;
            }

            // Clean the values
            if (is_array($line)) {
                return array_map(function ($value) {
                    // Remove null bytes and control characters
                    $value = str_replace("\0", '', $value);
                    $value = preg_replace('/[\x00-\x1F\x7F]/', '', $value);

                    return trim($value);
                }, $line);
            }

            // If we get here with a non-array, something went wrong
            Log::warning('CSV line is not an array', ['line' => $line]);

            return false;
        } catch (\Exception $e) {
            Log::error('Error reading CSV line', [
                'error' => $e->getMessage(),
                'file_position' => ftell($file),
            ]);

            // Try to recover by reading the next line as a plain string
            $rawLine = fgets($file);
            Log::debug('Attempting to recover with raw line read', ['raw_line' => $rawLine]);

            if ($rawLine !== false) {
                // Try to manually split the line
                $manualValues = str_getcsv($rawLine, $delimiter, $quoteChar);
                if (is_array($manualValues) && count($manualValues) > 0) {
                    Log::debug('Recovered with manual parsing', ['values_count' => count($manualValues)]);

                    return array_map('trim', $manualValues);
                }
            }

            return false;
        }
    }
}
