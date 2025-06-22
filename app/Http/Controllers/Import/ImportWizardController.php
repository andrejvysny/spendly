<?php

declare(strict_types=1);

namespace App\Http\Controllers\Import;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportConfigureRequest;
use App\Http\Requests\ImportUploadRequest;
use App\Models\Account;
use App\Models\Category;
use App\Models\Import;
use App\Services\Csv\CsvProcessor;
use App\Services\ImportMappingService;
use App\Services\TransactionImport\TransactionImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImportWizardController extends Controller
{
    public function __construct(
        private readonly CsvProcessor $csvProcessor,
        private readonly TransactionImportService $importService,
        private readonly ImportMappingService $mappingService
    ) {}

    public function upload(ImportUploadRequest $request): JsonResponse
    {
        Log::debug('Starting file upload');
        $request->validated();

        $file = $request->getFile();

        if (! $file->isValid()) {
            Log::error('File upload failed', ['error' => $file->getErrorMessage()]);

            return response()->json(['message' => 'File upload failed: '.$file->getErrorMessage()], 400);
        }

        $originalFilename = $file->getClientOriginalName();
        $filename = 'import_'.Str::random(40).'.csv';
        Storage::makeDirectory('imports');
        // Preprocess the CSV file
        $preprocessedPath = $this->csvProcessor->preprocessCSV(
            $file,
            $request->getDelimiter() ?? ',',
            $request->getQuoteChar() ?? '"',
        );

        if ($preprocessedPath === false) {
            Log::error('Failed to preprocess CSV file', ['filename' => $originalFilename]);

            return response()->json(['message' => 'Failed to preprocess CSV file'], 500);
        }

        // Store a preprocessed file
        $path = Storage::putFileAs('imports', $preprocessedPath, $filename);
        if (! $path) {
            Log::error('Failed to store file', ['filename' => $filename]);

            return response()->json(['message' => 'Failed to store file'], 500);
        }
        Log::debug('File stored', ['path' => $path]);

        // Clean up a temporary file
        unlink($preprocessedPath);

        // Get sample data
        $sampleData = $this->csvProcessor->getRows(
            $path,
            $request->getDelimiter(),
            $request->getQuoteChar(),
            $request->getSampleRowsCount()
        );

        // Count total rows
        // Count total rows efficiently
        $totalRows = 0;
        $handle = fopen(Storage::path($path), 'r');
        if ($handle) {
            while (! feof($handle)) {
                fgets($handle);
                $totalRows++;
            }
            fclose($handle);
            $totalRows--; // Subtract 1 for header
        }

        // Create import record
        $import = Import::create([
            'user_id' => Auth::id(),
            'filename' => $filename,
            'original_filename' => $originalFilename,
            'status' => Import::STATUS_PENDING,
            'total_rows' => $totalRows,
            'metadata' => [
                'headers' => $sampleData->getHeaders(),
                'sample_rows' => $sampleData->getRows(),
                'account_id' => $request->getAccountId(),
                'delimiter' => $request->getDelimiter(),
                'quote_char' => $request->getQuoteChar(),
            ],
        ]);

        Log::debug('Import record created', ['import_id' => $import->id]);

        return response()->json([
            'import_id' => $import->id,
            'headers' => $sampleData->getHeaders(),
            'sample_rows' => $sampleData->getRows(),
            'total_rows' => $totalRows,
        ]);
    }

    /**
     * Configure import settings and get preview.
     */
    public function configure(ImportConfigureRequest $request, Import $import): JsonResponse
    {
        Log::debug('Configuring import', ['import_id' => $import->getKey()]);

        $columnMapping = $request->getColumnMapping();
        $headers = $import->metadata['headers'] ?? [];

        // Validate the mapping
        $validation = $this->mappingService->validateMapping($columnMapping, $headers);

        if (! $validation['valid']) {
            Log::warning('Invalid column mapping', [
                'import_id' => $import->id,
                'errors' => $validation['errors'],
            ]);

            return response()->json([
                'message' => 'Invalid column mapping: '.implode(', ', $validation['errors']),
                'errors' => $validation['errors'],
            ], 422);
        }

        // Log warnings if any
        if (! empty($validation['warnings'])) {
            Log::info('Mapping warnings', [
                'import_id' => $import->id,
                'warnings' => $validation['warnings'],
            ]);
        }

        // Convert index-based mapping to header-based for storage
        $headerMapping = $this->mappingService->convertIndexMappingToHeaders($columnMapping, $headers);

        // Update import configuration
        $import->update([
            'column_mapping' => $columnMapping, // Keep index-based for processing
            'date_format' => $request->getDateFormat(),
            'amount_format' => $request->getAmountFormat(),
            'amount_type_strategy' => $request->getAmountTypeStrategy(),
            'currency' => $request->getCurrency(),
            'metadata' => array_merge($import->metadata ?? [], [
                'header_mapping' => $headerMapping, // Store header-based for future use
                'validation_warnings' => $validation['warnings'],
            ]),
        ]);

        // Get preview data
        try {
            $previewData = $this->importService->getPreview($import, 5);
        } catch (\Exception $e) {
            Log::error('Failed to generate preview', [
                'import_id' => $import->id,
                'error' => $e->getMessage(),
            ]);
            $previewData = [];
        }

        return response()->json([
            'import' => $import,
            'preview_data' => $previewData,
            'validation_warnings' => $validation['warnings'],
        ]);
    }

    public function clean(): JsonResponse
    {
        // TODO: implement functionality to clean data before importing

        return response()->json([
            'message' => 'Old imports cleaned',
        ]);
    }

    public function map(): JsonResponse
    {
        // TODO: implement functionality to validate map columns before importing

        return response()->json([
            'message' => 'Columns mapped',
        ]);
    }

    /**
     * Process the import.
     */
    public function process(Account $account, Import $import): JsonResponse
    {
        Log::debug('Processing import', ['import_id' => $import->id, 'account_id' => $account->id]);

        // Verify ownership
        if ($import->user_id !== Auth::id() || $account->user_id !== Auth::id()) {
            abort(403, 'Unauthorized access to import');
        }

        // Check if already processed
        if (! $import->isPending()) {
            return response()->json([
                'message' => 'Import already processed',
                'import' => $import,
            ]);
        }

        try {
            // Process the import
            $results = $this->importService->processImport($import, $account->getKey());

            // Determine response message based on import status
            $message = match ($import->fresh()->status) {
                Import::STATUS_COMPLETED => 'Import processed successfully',
                Import::STATUS_COMPLETED_SKIPPED_DUPLICATES => 'Import completed with some duplicate transactions skipped',
                Import::STATUS_PARTIALLY_FAILED => 'Import completed but with a high number of failed rows',
                Import::STATUS_FAILED => 'Import failed - no transactions could be processed',
                default => 'Import processing completed'
            };

            return response()->json([
                'message' => $message,
                'import' => $import->fresh(),
                'stats' => $results,
            ]);
        } catch (\Exception $e) {
            Log::error('Import processing failed', [
                'import_id' => $import->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Update import status to failed
            $import->update([
                'status' => Import::STATUS_FAILED,
                'processed_at' => now(),
                'metadata' => array_merge($import->metadata ?? [], [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]),
            ]);

            return response()->json([
                'message' => 'Import failed: '.$e->getMessage(),
                'import' => $import->fresh(),
            ], 500);
        }
    }

    /**
     * Get categories for the authenticated user.
     */
    public function getCategories(): JsonResponse
    {
        $categories = Category::where('user_id', Auth::id())->get();

        return response()->json([
            'categories' => $categories,
        ]);
    }
}
