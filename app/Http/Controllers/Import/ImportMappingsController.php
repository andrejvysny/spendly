<?php

namespace App\Http\Controllers\Import;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportMappingRequest;
use App\Models\ImportMapping;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ImportMappingsController extends Controller
{
    public function index(): JsonResponse
    {
        Log::debug('Fetching saved import mappings for user', ['user_id' => Auth::id()]);
        $mappings = ImportMapping::where('user_id', Auth::id())
            ->orderByDesc('last_used_at')
            ->get();
        Log::debug('Found import mappings', ['count' => $mappings->count()]);

        return response()->json([
            'mappings' => $mappings,
        ]);
    }

    public function store(ImportMappingRequest $request): JsonResponse
    {
        $request->validated();
        Log::debug('Saving new import mapping', ['user_id' => Auth::id()]);
        $mapping = ImportMapping::create([
            'user_id' => Auth::id(),
            'name' => $request->name,
            'bank_name' => $request->bank_name,
            'column_mapping' => $request->column_mapping,
            'date_format' => $request->date_format,
            'amount_format' => $request->amount_format,
            'amount_type_strategy' => $request->amount_type_strategy,
            'currency' => $request->currency,
            'last_used_at' => now(),
        ]);
        Log::debug('Import mapping saved successfully', ['mapping_id' => $mapping->id]);

        return response()->json([
            'message' => 'Import mapping saved successfully',
            'mapping' => $mapping,
        ]);
    }

    public function updateLastUsed(ImportMapping $mapping): JsonResponse
    {
        $this->authorize($mapping);
        $mapping->update([
            'last_used_at' => now(),
        ]);
        $mapping->save();
        Log::debug('Updated mapping last used timestamp', ['mapping_id' => $mapping->id]);

        return response()->json([
            'message' => 'Mapping usage updated',
            'mapping' => $mapping,
        ]);
    }

    public function delete(ImportMapping $mapping): JsonResponse
    {
        $this->authorize($mapping);
        $mapping->delete();
        Log::debug('Deleted import mapping', ['mapping_id' => $mapping->id]);

        return response()->json([
            'message' => 'Import mapping deleted successfully',
        ]);
    }

    private function authorize(ImportMapping $mapping): void
    {
        if ($mapping->user_id !== Auth::id()) {
            Log::warning('Unauthorized access attempt to import mapping', [
                'mapping_id' => $mapping->id,
                'user_id' => Auth::id(),
            ]);
            abort(403, 'Unauthorized action');
        }
    }
}
