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
    public function __construct(
        private readonly CsvProcessor $csvProcessor,
        private readonly TransactionImportService $importService
    ) {}

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
        $totalRows = count(file(Storage::path($path))) - 1;

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
