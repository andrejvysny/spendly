<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Import\Import;
use App\Models\Import\ImportMapping;
use App\Models\User;
use App\Services\Csv\CsvProcessor;
use App\Services\TransactionImport\FieldDetection\AutoDetectionService;
use App\Services\TransactionImport\ImportMappingService;
use App\Services\TransactionImport\TransactionImportService;
use App\Services\TransferDetectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImportCsvCommand extends Command
{
    protected $signature = 'import:csv
        {file : Path to CSV file (absolute or relative to project root)}
        {--account= : Account ID or account name (required)}
        {--user= : User ID or email (default: first user)}
        {--mapping= : Name of a saved import mapping for this user}
        {--delimiter= : Override delimiter (e.g. ;)}
        {--currency=EUR : Default currency if not from mapping}
        {--date-format= : Override date format (e.g. d.m.Y for DD.MM.YYYY)}';

    protected $description = 'Import a CSV file via CLI (for testing and AI agents). Uses storage and existing import pipeline.';

    public function __construct(
        private readonly CsvProcessor $csvProcessor,
        private readonly TransactionImportService $importService,
        private readonly ImportMappingService $mappingService,
        private readonly AutoDetectionService $autoDetectionService,
        private readonly TransferDetectionService $transferDetectionService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $fileInput = $this->argument('file');
        $accountInput = $this->option('account');
        $userInput = $this->option('user');
        $mappingName = $this->option('mapping');
        $delimiterOverride = $this->option('delimiter');
        $currencyDefault = $this->option('currency');
        $dateFormatOverride = $this->option('date-format');

        if (empty($accountInput)) {
            $this->error('Option --account= is required (account ID or name).');

            return self::FAILURE;
        }

        $absolutePath = $this->resolveFilePath($fileInput);
        if ($absolutePath === null) {
            $this->error("File not found: {$fileInput}");

            return self::FAILURE;
        }

        $user = $this->resolveUser($userInput);
        if ($user === null) {
            $this->error('No user found.');

            return self::FAILURE;
        }

        Auth::loginUsingId($user->id);

        $account = $this->resolveAccount($user->id, $accountInput);
        if ($account === null) {
            $this->error("Account not found: {$accountInput} (check that the account exists and belongs to the selected user).");

            return self::FAILURE;
        }

        $tempPath = $this->csvProcessor->preprocessCSVFromPath($absolutePath, $delimiterOverride ?: ',', '"');
        if ($tempPath === false) {
            $this->error('Failed to preprocess CSV file.');

            return self::FAILURE;
        }

        Storage::makeDirectory('imports');
        $filename = 'import_'.Str::random(40).'.csv';
        $storagePath = 'imports/'.$filename;
        $content = file_get_contents($tempPath);
        @unlink($tempPath);
        if ($content === false) {
            $this->error('Failed to read preprocessed file.');

            return self::FAILURE;
        }
        Storage::put($storagePath, $content);

        $delimiter = $delimiterOverride ?: $this->csvProcessor->detectDelimiter($storagePath, 10);
        $quoteChar = '"';

        $sampleSize = 100;
        $csvData = $this->csvProcessor->getRows($storagePath, $delimiter, $quoteChar, $sampleSize);
        $headers = $csvData->getHeaders();
        $sampleRows = $csvData->getRows();

        $totalRows = $this->countDataRows($storagePath, $delimiter, $quoteChar);
        $fullPath = Storage::path($storagePath);
        $fileSignature = is_file($fullPath) ? hash_file('sha256', $fullPath) : '';

        $columnMapping = [];
        $dateFormat = 'd.m.Y';
        $amountFormat = '1,234.56';
        $amountTypeStrategy = 'signed_amount';
        $currency = $currencyDefault;

        if ($mappingName !== null && $mappingName !== '') {
            $mapping = ImportMapping::where('user_id', $user->id)->where('name', $mappingName)->first();
            if ($mapping === null) {
                $this->error("Saved mapping not found: {$mappingName}");

                return self::FAILURE;
            }
            $columnMapping = $this->mappingService->applySavedMapping($mapping->column_mapping, $headers);
            $dateFormat = $dateFormatOverride ?: ($mapping->date_format ?? $dateFormat);
            $amountFormat = $mapping->amount_format ?? $amountFormat;
            $amountTypeStrategy = $mapping->amount_type_strategy ?? $amountTypeStrategy;
            $currency = $mapping->currency ?? $currencyDefault;
        } else {
            $detected = $this->autoDetectionService->detectMappings($headers, $sampleRows);
            $columnMapping = $this->convertDetectedMappingsToFieldIndex($detected['mappings']);
            $dateFormat = $dateFormatOverride ?: ($detected['detected_date_format'] ?? $dateFormat);
            $amountFormat = $detected['detected_amount_format'] ?? $amountFormat;
            $validation = $this->mappingService->validateMapping($columnMapping, $headers);
            if (! $validation['valid']) {
                $fallback = $this->mappingService->autoDetectMapping($headers);
                foreach (['booked_date', 'amount', 'partner'] as $required) {
                    if (! isset($columnMapping[$required]) || $columnMapping[$required] === null) {
                        $columnMapping[$required] = $fallback[$required] ?? null;
                    }
                }
                // If partner still unmapped but description is mapped, use description column for partner (parser will copy)
                if (($columnMapping['partner'] ?? null) === null && ($columnMapping['description'] ?? null) !== null) {
                    $columnMapping['partner'] = $columnMapping['description'];
                }
                $validation = $this->mappingService->validateMapping($columnMapping, $headers);
            }
            if (! $validation['valid']) {
                $this->error('Auto-detected mapping is invalid: '.implode('; ', $validation['errors']));

                return self::FAILURE;
            }
        }

        $import = Import::create([
            'user_id' => $user->id,
            'filename' => $filename,
            'original_filename' => basename($absolutePath),
            'status' => Import::STATUS_PENDING,
            'total_rows' => $totalRows,
            'metadata' => [
                'headers' => $headers,
                'delimiter' => $delimiter,
                'quote_char' => $quoteChar,
                'account_id' => $account->id,
                'file_signature' => $fileSignature,
            ],
        ]);

        $import->update([
            'column_mapping' => $columnMapping,
            'date_format' => $dateFormat,
            'amount_format' => $amountFormat,
            'amount_type_strategy' => $amountTypeStrategy,
            'currency' => $currency,
        ]);

        try {
            $results = $this->importService->processImport($import, (int) $account->id);
        } catch (\Throwable $e) {
            $this->error('Import failed: '.$e->getMessage());

            return self::FAILURE;
        }

        try {
            $this->transferDetectionService->detectAndMarkTransfersForUser($user->id);
        } catch (\Throwable $e) {
            $this->warn('Transfer detection after import failed: '.$e->getMessage());
        }

        $this->info('Import completed.');
        $this->line("  Processed: {$results['processed']}");
        $this->line("  Failed: {$results['failed']}");
        $this->line("  Skipped: {$results['skipped']}");
        $this->line("  Total rows: {$results['total_rows']}");

        $status = $import->fresh()->status;
        if ($status === Import::STATUS_FAILED) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function resolveFilePath(string $file): ?string
    {
        if (str_starts_with($file, '/') && is_file($file)) {
            return $file;
        }
        $relative = base_path($file);

        return is_file($relative) ? realpath($relative) : null;
    }

    private function resolveUser(?string $userInput): ?User
    {
        if ($userInput === null || $userInput === '') {
            return User::query()->orderBy('id')->first();
        }
        if (is_numeric($userInput)) {
            return User::find((int) $userInput);
        }

        return User::query()->where('email', $userInput)->first();
    }

    private function resolveAccount(int $userId, string $accountInput): ?Account
    {
        if (is_numeric($accountInput)) {
            return Account::where('user_id', $userId)->where('id', (int) $accountInput)->first();
        }

        return Account::where('user_id', $userId)->where('name', $accountInput)->first();
    }

    /**
     * Count data rows (excluding header) in a stored CSV.
     */
    private function countDataRows(string $storagePath, string $delimiter, string $quoteChar): int
    {
        if (! Storage::exists($storagePath)) {
            return 0;
        }
        $fullPath = Storage::path($storagePath);
        $handle = fopen($fullPath, 'r');
        if (! $handle) {
            return 0;
        }
        $total = 0;
        while (! feof($handle)) {
            fgets($handle);
            $total++;
        }
        fclose($handle);

        return max(0, $total - 1);
    }

    /**
     * Convert AutoDetectionService mappings (columnIndex => {field, confidence}) to field => index.
     * On conflict (same field from multiple columns), keep higher confidence.
     *
     * @param  array<int, array{field: string|null, confidence: float, ...}>  $mappings
     * @return array<string, int|null>
     */
    private function convertDetectedMappingsToFieldIndex(array $mappings): array
    {
        $transactionFields = [
            'transaction_id', 'booked_date', 'processed_date', 'amount', 'description', 'partner',
            'type', 'target_iban', 'source_iban', 'category', 'tags', 'notes',
            'balance_after_transaction', 'currency',
        ];
        $columnMapping = array_fill_keys($transactionFields, null);

        foreach ($mappings as $colIndex => $m) {
            $field = $m['field'] ?? null;
            if ($field === null || $field === '') {
                continue;
            }
            // Alias 'date' from pattern matcher to 'booked_date'
            if ($field === 'date') {
                $field = 'booked_date';
            }
            // Skip fields not in transaction fields list
            if (! array_key_exists($field, $columnMapping)) {
                continue;
            }
            $existingIndex = $columnMapping[$field] ?? null;
            if ($existingIndex === null) {
                $columnMapping[$field] = $colIndex;

                continue;
            }
            $existingConfidence = isset($mappings[$existingIndex]['confidence'])
                ? (float) $mappings[$existingIndex]['confidence'] : 0.0;
            $confidence = (float) ($m['confidence'] ?? 0);
            if ($confidence > $existingConfidence) {
                $columnMapping[$field] = $colIndex;
            }
        }

        return $columnMapping;
    }
}
