<?php

declare(strict_types=1);

namespace App\Services\Csv;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CsvProcessor
{
    /**
     * Get rows from a CSV file
     *
     * @param  string  $path  Filepath
     * @param  string  $delimiter  CSV delimiter
     * @param  string  $quoteChar  CSV quote character
     * @param  int|null  $rows  Number of rows to return (null = all rows)
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
        if ($headers === false) {
            fclose($file);
            throw new \RuntimeException('Unable to read CSV headers');
        }

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

    public function preprocessCSV(UploadedFile $file, string $delimiter, string $quoteChar): false|string
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
        $encoding = $this->detectEncoding($content, 10000);
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

                // Handle offset
                if ($batch->getSkippedCount() + $batch->getSuccessCount() + $batch->getFailedCount() < $offset) {
                    $batch->addResult(CsvProcessResult::skipped('Offset skip', $row));
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
     * Detect the most likely CSV delimiter from the first lines of a file.
     * Uses storage path (e.g. imports/xxx.csv). Candidates: comma, semicolon, tab, pipe.
     */
    public function detectDelimiter(string $path, int $sampleLines = 10): string
    {
        $content = $this->readSampleLines($path, $sampleLines);
        $candidates = [',', ';', "\t", '|'];
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $lines = array_slice(array_filter($lines), 0, $sampleLines);

        if ($lines === []) {
            return ',';
        }

        $scores = [];
        foreach ($candidates as $delimiter) {
            $counts = [];
            foreach ($lines as $line) {
                $count = substr_count($line, $delimiter);
                $counts[] = $count;
            }
            $consistent = count($counts) > 0 && min($counts) === max($counts) && $counts[0] > 0;
            $total = array_sum($counts);
            $scores[$delimiter] = $consistent ? $total : 0;
        }

        arsort($scores, SORT_NUMERIC);
        $winner = array_key_first($scores);

        return $scores[$winner] > 0 ? $winner : ',';
    }

    /**
     * Read the first N lines from a stored file (used for delimiter/encoding detection).
     */
    public function readSampleLines(string $path, int $maxLines = 10): string
    {
        if (! Storage::exists($path)) {
            return '';
        }
        $fullPath = Storage::path($path);
        $handle = fopen($fullPath, 'r');
        if (! $handle) {
            return '';
        }
        $lines = [];
        $count = 0;
        while ($count < $maxLines && ($line = fgets($handle)) !== false) {
            $lines[] = $line;
            $count++;
        }
        fclose($handle);

        return implode('', $lines);
    }

    /**
     * Detect file encoding from content. Prefers BOM, then mb_detect_encoding with strict mode.
     *
     * @param  string  $content  Raw file content (or sample)
     * @param  int  $sampleSize  If content is large, only first N bytes are used for detection
     */
    public function detectEncoding(string $content, int $sampleSize = 8192): string
    {
        $sample = strlen($content) > $sampleSize ? substr($content, 0, $sampleSize) : $content;

        if (str_starts_with($sample, "\xEF\xBB\xBF")) {
            return 'UTF-8';
        }
        if (str_starts_with($sample, "\xFF\xFE")) {
            return 'UTF-16LE';
        }
        if (str_starts_with($sample, "\xFE\xFF")) {
            return 'UTF-16BE';
        }

        $detected = mb_detect_encoding($sample, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);

        return $detected ?: 'UTF-8';
    }

    /**
     * Parse CSV with row-level error isolation. Each line is parsed independently;
     * failures are collected and returned without aborting.
     */
    public function parseWithErrorIsolation(string $path, string $delimiter, string $quoteChar = '"'): CsvParseResult
    {
        $rows = [];
        $errors = [];
        $headers = [];

        if (! Storage::exists($path)) {
            throw new \RuntimeException('CSV file not found: ' . $path);
        }

        $fullPath = Storage::path($path);
        $handle = fopen($fullPath, 'r');
        if (! $handle) {
            throw new \RuntimeException('Unable to open CSV file: ' . $path);
        }

        $lineNumber = 0;
        $headerRead = false;

        while (($rawLine = fgets($handle)) !== false) {
            $lineNumber++;
            $rawLine = rtrim($rawLine, "\r\n");

            try {
                $parsed = str_getcsv($rawLine, $delimiter, $quoteChar);
                if (! $headerRead) {
                    $headers = $parsed;
                    $headerRead = true;
                    continue;
                }
                $rows[] = ['line' => $lineNumber, 'data' => $parsed];
            } catch (\Throwable $e) {
                $errors[] = [
                    'line' => $lineNumber,
                    'error' => $e->getMessage(),
                    'raw' => $rawLine,
                ];
            }
        }

        fclose($handle);

        return new CsvParseResult($rows, $errors, $headers);
    }

    /**
     * Safely get a CSV line with error handling
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
