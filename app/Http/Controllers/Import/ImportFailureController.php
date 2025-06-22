<?php

namespace App\Http\Controllers\Import;

use App\Http\Controllers\Controller;
use App\Models\Import;
use App\Models\ImportFailure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ImportFailureController extends Controller
{
    /**
     * Show the failure review page for an import.
     */
    public function failuresPage(Import $import, Request $request): Response
    {
        Gate::authorize('view', $import);

        $query = $import->failures();

        // Apply filters
        if ($request->has('error_type') && $request->filled('error_type')) {
            $query->byErrorType($request->input('error_type'));
        }

        if ($request->has('status') && $request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('error_message', 'like', "%{$search}%")
                    ->orWhere('raw_data', 'like', "%{$search}%");
            });
        }

        // Order by most recent first
        $query->orderBy('created_at', 'desc');

        $failures = $query->paginate($request->input('per_page', 15));

        // Get statistics
        $stats = $import->getFailureStats();

        return Inertia::render('import/failures', [
            'import' => $import,
            'failures' => $failures,
            'stats' => $stats,
        ]);
    }

    /**
     * Get failures for a specific import.
     */
    public function index(Import $import, Request $request): JsonResponse
    {
        Gate::authorize('view', $import);

        $query = $import->failures();

        // Filter by error type if specified
        if ($request->has('error_type') && $request->filled('error_type')) {
            $query->byErrorType($request->input('error_type'));
        }

        // Filter by status if specified
        if ($request->has('status') && $request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // Search in error messages
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('error_message', 'like', "%{$search}%")
                    ->orWhere('raw_data', 'like', "%{$search}%");
            });
        }

        // Order by most recent first
        $query->orderBy('created_at', 'desc');

        $failures = $query->paginate($request->input('per_page', 15));

        // Add statistics
        $stats = [
            'total' => $import->failures()->count(),
            'pending' => $import->failures()->pending()->count(),
            'reviewed' => $import->failures()->reviewed()->count(),
            'by_type' => $import->failures()
                ->select('error_type', DB::raw('count(*) as count'))
                ->groupBy('error_type')
                ->pluck('count', 'error_type')
                ->toArray(),
        ];

        return response()->json([
            'failures' => $failures,
            'stats' => $stats,
            'import' => $import->only(['id', 'original_filename', 'status', 'processed_at']),
        ]);
    }

    /**
     * Get a specific failure.
     */
    public function show(Import $import, ImportFailure $failure): JsonResponse
    {
        Gate::authorize('view', $import);

        // Ensure the failure belongs to the import
        if ($failure->import_id !== $import->id) {
            return response()->json(['error' => 'Failure not found in this import'], 404);
        }

        $failure->load('reviewer');

        return response()->json([
            'failure' => $failure,
            'import' => $import->only(['id', 'original_filename', 'status']),
        ]);
    }

    /**
     * Mark a failure as reviewed.
     */
    public function markAsReviewed(Import $import, ImportFailure $failure, Request $request): JsonResponse
    {
        Gate::authorize('update', $import);

        // Ensure the failure belongs to the import
        if ($failure->import_id !== $import->id) {
            return response()->json(['error' => 'Failure not found in this import'], 404);
        }

        $request->validate([
            'notes' => 'nullable|string|max:1000',
        ]);

        $user = Auth::user();
        $success = $failure->markAsReviewed($user, $request->input('notes'));

        if (! $success) {
            return response()->json(['error' => 'Failed to update failure status'], 500);
        }

        Log::info('Import failure marked as reviewed', [
            'failure_id' => $failure->id,
            'import_id' => $import->id,
            'reviewed_by' => $user->id,
        ]);

        return response()->json([
            'message' => 'Failure marked as reviewed',
            'failure' => $failure->fresh()->load('reviewer'),
        ]);
    }

    /**
     * Mark a failure as resolved.
     */
    public function markAsResolved(Import $import, ImportFailure $failure, Request $request): JsonResponse
    {
        Gate::authorize('update', $import);

        // Ensure the failure belongs to the import
        if ($failure->import_id !== $import->id) {
            return response()->json(['error' => 'Failure not found in this import'], 404);
        }

        $request->validate([
            'notes' => 'nullable|string|max:1000',
        ]);

        $user = Auth::user();
        $success = $failure->markAsResolved($user, $request->input('notes'));

        if (! $success) {
            return response()->json(['error' => 'Failed to update failure status'], 500);
        }

        Log::info('Import failure marked as resolved', [
            'failure_id' => $failure->id,
            'import_id' => $import->id,
            'resolved_by' => $user->id,
        ]);

        return response()->json([
            'message' => 'Failure marked as resolved',
            'failure' => $failure->fresh()->load('reviewer'),
        ]);
    }

    /**
     * Mark a failure as ignored.
     */
    public function markAsIgnored(Import $import, ImportFailure $failure, Request $request): JsonResponse
    {
        Gate::authorize('update', $import);

        // Ensure the failure belongs to the import
        if ($failure->import_id !== $import->id) {
            return response()->json(['error' => 'Failure not found in this import'], 404);
        }

        $request->validate([
            'notes' => 'nullable|string|max:1000',
        ]);

        $user = Auth::user();
        $success = $failure->markAsIgnored($user, $request->input('notes'));

        if (! $success) {
            return response()->json(['error' => 'Failed to update failure status'], 500);
        }

        Log::info('Import failure marked as ignored', [
            'failure_id' => $failure->id,
            'import_id' => $import->id,
            'ignored_by' => $user->id,
        ]);

        return response()->json([
            'message' => 'Failure marked as ignored',
            'failure' => $failure->fresh()->load('reviewer'),
        ]);
    }

    /**
     * Bulk update failure statuses.
     */
    public function bulkUpdate(Import $import, Request $request): JsonResponse
    {
        Gate::authorize('update', $import);

        $request->validate([
            'failure_ids' => 'required|array|min:1',
            'failure_ids.*' => 'integer|exists:import_failures,id',
            'action' => ['required', Rule::in(['reviewed', 'resolved', 'ignored'])],
            'notes' => 'nullable|string|max:1000',
        ]);

        $failureIds = $request->input('failure_ids');
        $action = $request->input('action');
        $notes = $request->input('notes');

        // Ensure all failures belong to this import
        $failures = $import->failures()->whereIn('id', $failureIds)->get();

        if ($failures->count() !== count($failureIds)) {
            return response()->json(['error' => 'Some failures not found in this import'], 404);
        }

        $user = Auth::user();
        $updated = 0;

        foreach ($failures as $failure) {
            $success = match ($action) {
                'reviewed' => $failure->markAsReviewed($user, $notes),
                'resolved' => $failure->markAsResolved($user, $notes),
                'ignored' => $failure->markAsIgnored($user, $notes),
            };

            if ($success) {
                $updated++;
            }
        }

        Log::info('Bulk import failure update', [
            'import_id' => $import->id,
            'action' => $action,
            'total_requested' => count($failureIds),
            'updated' => $updated,
            'updated_by' => $user->id,
        ]);

        return response()->json([
            'message' => "Updated {$updated} failures",
            'updated' => $updated,
            'total' => count($failureIds),
        ]);
    }

    /**
     * Get failure statistics for an import.
     */
    public function stats(Import $import): JsonResponse
    {
        Gate::authorize('view', $import);

        $stats = $import->getFailureStats();

        return response()->json([
            'stats' => $stats,
            'import' => $import->only(['id', 'original_filename', 'status', 'processed_at']),
        ]);
    }

    /**
     * Export failures as CSV for manual processing.
     */
    public function export(Import $import, Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        Gate::authorize('view', $import);

        $query = $import->failures();

        // Apply same filters as index method
        if ($request->has('error_type') && $request->filled('error_type')) {
            $query->byErrorType($request->input('error_type'));
        }

        if ($request->has('status') && $request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $filename = "import-{$import->id}-failures-".now()->format('Y-m-d-H-i-s').'.csv';

        return response()->stream(function () use ($query) {
            $file = fopen('php://output', 'w');

            // Write CSV headers
            fputcsv($file, [
                'Row Number',
                'Error Type',
                'Error Message',
                'Raw Data',
                'Status',
                'Created At',
                'Reviewed At',
                'Reviewer',
                'Notes',
            ]);

            // Write data in chunks
            $query->with('reviewer')->chunk(100, function ($failures) use ($file) {
                foreach ($failures as $failure) {
                    fputcsv($file, [
                        $failure->row_number,
                        $failure->error_type,
                        $failure->error_message,
                        is_array($failure->raw_data) ? json_encode($failure->raw_data) : $failure->raw_data,
                        $failure->status,
                        $failure->created_at?->format('Y-m-d H:i:s'),
                        $failure->reviewed_at?->format('Y-m-d H:i:s'),
                        $failure->reviewer?->name,
                        $failure->review_notes,
                    ]);
                }
            });

            fclose($file);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
