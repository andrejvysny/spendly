<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class CsvProcessor
{
    public function __construct() {}

    public function preprocessCSV($file, string $delimiter, string $quoteChar): false|string
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
     * Detect file encoding with additional checks for UTF-16 variants
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
}
