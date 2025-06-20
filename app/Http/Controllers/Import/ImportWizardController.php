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
use App\Services\TransactionImport\TransactionImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImportWizardController extends Controller
{
    /**
     * Initializes the controller with services for CSV processing and transaction import handling.
     *
     * @param CsvProcessor $csvProcessor Service for handling CSV file operations.
     * @param TransactionImportService $importService Service for processing transaction imports.
     */
    public function __construct(
        private readonly CsvProcessor $csvProcessor,
        private readonly TransactionImportService $importService
    ) {}

    /**
     * Handles CSV file upload, preprocessing, and import record creation.
     *
     * Processes the uploaded CSV file by applying the specified delimiter and quote character, stores the preprocessed file, extracts sample data and headers, counts total data rows, and creates a new import record associated with the authenticated user.
     *
     * @return JsonResponse JSON response containing the import ID, headers, sample rows, and total row count.
     */
    public function upload(ImportUploadRequest $request): JsonResponse
    {
        Log::debug('Starting file upload');

        $file = $request->getFile();
        $originalFilename = $file->getClientOriginalName();
        $filename = Str::random(40).'.csv';
        Storage::makeDirectory('imports');
        // Preprocess the CSV file
        $preprocessedPath = $this->csvProcessor->preprocessCSV(
            $file,
            $request->getDelimiter(),
            $request->getQuoteChar()
        );

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
     * Updates the configuration settings for a given import and returns the updated import along with preview data.
     *
     * @param ImportConfigureRequest $request The validated request containing import configuration settings.
     * @param Import $import The import record to be configured.
     * @return JsonResponse JSON response with the updated import and preview data.
     */
    public function configure(ImportConfigureRequest $request, Import $import): JsonResponse
    {
        Log::debug('Configuring import', ['import_id' => $import->getKey()]);

        // Update import configuration
        $import->update([
            'column_mapping' => $request->getColumnMapping(),
            'date_format' => $request->getDateFormat(),
            'amount_format' => $request->getAmountFormat(),
            'amount_type_strategy' => $request->getAmountTypeStrategy(),
            'currency' => $request->getCurrency(),
        ]);

        // TODO: Get preview data to clean them befor actual import
        $previewData = [];

        return response()->json([
            'import' => $import,
            'preview_data' => $previewData,
        ]);
    }

    /**
     * Placeholder for cleaning old import data before importing.
     *
     * Returns a JSON response indicating that old imports have been cleaned. Actual cleaning functionality is not yet implemented.
     *
     * @return JsonResponse
     */
    public function clean(): JsonResponse
    {
        // TODO: implement functionality to clean data before importing

        return response()->json([
            'message' => 'Old imports cleaned',
        ]);
    }

    /**
     * Placeholder for validating column mappings before importing.
     *
     * Returns a JSON response indicating that columns have been mapped. Actual validation logic is not yet implemented.
     *
     * @return JsonResponse
     */
    public function map(): JsonResponse
    {
        // TODO: implement functionality to validate map columns before importing

        return response()->json([
            'message' => 'Columns mapped',
        ]);
    }

    /**
     * Processes a pending import for the specified account.
     *
     * Verifies user ownership of both the import and account, then delegates import processing to the transaction import service. Returns a JSON response with the outcome message, refreshed import data, and processing statistics. If the import is not pending, returns a message indicating it was already processed. On failure, updates the import status and returns an error response.
     *
     * @param Account $account The account to associate imported transactions with.
     * @param Import $import The import record to process.
     * @return JsonResponse JSON response containing the result message, import data, and processing statistics.
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
     * Retrieves all categories belonging to the authenticated user.
     *
     * @return JsonResponse JSON response containing the user's categories.
     */
    public function getCategories(): JsonResponse
    {
        $categories = Category::where('user_id', Auth::id())->get();

        return response()->json([
            'categories' => $categories,
        ]);
    }
}
