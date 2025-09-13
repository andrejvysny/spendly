<?php

namespace App\Http\Controllers\Import;

use App\Http\Controllers\Controller;
use App\Models\Import\Import;
use App\Models\Transaction;
use App\Models\TransactionFingerprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class ImportController extends Controller
{
    public function index()
    {
        Log::debug('Fetching imports for user', ['user_id' => Auth::id()]);
        $imports = Import::where('user_id', Auth::id())
            ->orderByDesc('created_at')
            ->get();
        Log::debug('Found imports', ['count' => $imports->count()]);

        return Inertia::render('import/index', [
            'imports' => $imports,
        ]);
    }

    public function revertImport(Import $import): JsonResponse
    {
        $this->authorize($import);
        if ($import->status === Import::STATUS_REVERTED) {
            Log::info('Import already reverted', ['import_id' => $import->id]);

            return response()->json(['message' => 'Import already reverted'], 200);
        }

        // Use DB transaction to ensure data consistency
        DB::transaction(function () use ($import) {
            $transactions = Transaction::where('metadata->import_id', $import->id)->get();
            foreach ($transactions as $transaction) {
                // First detach any many-to-many relationships
                // TransactionFingerprint::where('transaction_id', $transaction->id)->delete();
                // Then delete the transaction
                $transaction->delete();
            }

            $import->status = Import::STATUS_REVERTED;
            $import->save();
        });

        Log::info('Import reverted successfully', ['import_id' => $import->id]);

        return response()->json([
            'message' => 'Import reverted successfully',
            'import' => $import,
        ]);
    }

    public function deleteImport(Import $import): JsonResponse
    {
        $this->authorize($import);

        $path = Storage::path('imports/'.$import->filename);
        Log::debug('File stored', ['path' => $path]);

        if (file_exists($path)) {
            unlink($path);
        }

        $import->delete();

        return response()->json(['message' => 'Import deleted successfully']);
    }

    private function authorize(Import $import): void
    {
        if ($import->user_id !== Auth::id()) {
            Log::warning('Unauthorized attempt to import data', [
                'import_id' => $import->id,
                'user_id' => Auth::id(),
            ]);
            abort(403);
        }
    }
}
