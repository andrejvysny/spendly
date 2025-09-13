<?php

namespace App\Http\Controllers\Import;

use App\Http\Controllers\Controller;
use App\Http\Requests\Import\ImportMappingRequest;
use App\Models\Import\ImportMapping;
use App\Services\TransactionImport\ImportMappingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ImportMappingsController extends Controller
{
    public function __construct(
        private readonly ImportMappingService $mappingService
    ) {}

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

        // Convert index-based mapping to header-based if needed
        $columnMapping = $request->getColumnMapping();
        $headers = $request->input('headers', []);

        // If headers are provided and mapping is index-based, convert to header-based
        if (! empty($headers) && $this->mappingService->isIndexBasedMapping($columnMapping)) {
            $columnMapping = $this->mappingService->convertIndexMappingToHeaders($columnMapping, $headers);
            Log::debug('Converted index-based mapping to header-based', [
                'original' => $request->getColumnMapping(),
                'converted' => $columnMapping,
            ]);
        }

        $mapping = ImportMapping::create([
            'user_id' => Auth::id(),
            'name' => $request->getName(),
            'bank_name' => $request->getBankName(),
            'column_mapping' => $columnMapping,
            'date_format' => $request->getDateFormat(),
            'amount_format' => $request->getAmountFormat(),
            'amount_type_strategy' => $request->getAmountTypeStrategy(),
            'currency' => $request->getCurrency(),
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

    /**
     * Get compatible mappings for given headers
     */
    public function getCompatible(Request $request): JsonResponse
    {
        $headers = $request->input('headers', []);

        if (empty($headers)) {
            return $this->index();
        }

        $mappings = ImportMapping::where('user_id', Auth::id())
            ->orderByDesc('last_used_at')
            ->get();

        $compatibleMappings = [];

        foreach ($mappings as $mapping) {
            // Try to apply the mapping to current headers
            $appliedMapping = $this->mappingService->applySavedMapping(
                $mapping->column_mapping,
                $headers
            );

            // Validate the applied mapping
            $validation = $this->mappingService->validateMapping($appliedMapping, $headers);

            // Add compatibility score
            $compatibility = $this->calculateCompatibility($mapping->column_mapping, $headers);

            $mappingArray = $mapping->toArray();
            $mappingArray['compatibility_score'] = $compatibility;
            $mappingArray['is_valid'] = $validation['valid'];
            $mappingArray['warnings'] = $validation['warnings'];
            $mappingArray['applied_mapping'] = $appliedMapping;

            $compatibleMappings[] = $mappingArray;
        }

        // Sort by compatibility score (descending)
        usort($compatibleMappings, function ($a, $b) {
            return $b['compatibility_score'] <=> $a['compatibility_score'];
        });

        return response()->json([
            'mappings' => $compatibleMappings,
            'headers' => $headers,
        ]);
    }

    /**
     * Apply a saved mapping to current headers
     */
    public function applyMapping(Request $request): JsonResponse
    {
        $savedMapping = $request->input('saved_mapping', []);
        $currentHeaders = $request->input('current_headers', []);

        if (empty($savedMapping) || empty($currentHeaders)) {
            return response()->json([
                'message' => 'Missing required parameters',
            ], 422);
        }

        try {
            // Normalize mapping values: convert numeric strings to ints, false/empty to null
            foreach ($savedMapping as $field => $value) {
                if ($value === false || $value === '') {
                    $savedMapping[$field] = null;
                    continue;
                }

                if (is_string($value) && is_numeric($value)) {
                    // Only cast whole numbers to int (avoid casting floats)
                    if ((string) intval($value) === (string) $value) {
                        $savedMapping[$field] = intval($value);
                    }
                }
            }

            // Apply the mapping
            $appliedMapping = $this->mappingService->applySavedMapping($savedMapping, $currentHeaders);

            // Validate the result
            $validation = $this->mappingService->validateMapping($appliedMapping, $currentHeaders);

            Log::info('Applied saved mapping', [
                'original_mapping' => $savedMapping,
                'applied_mapping' => $appliedMapping,
                'validation' => $validation,
            ]);

            return response()->json([
                'applied_mapping' => $appliedMapping,
                'validation' => $validation,
                'warnings' => $validation['warnings'],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to apply mapping', [
                'error' => $e->getMessage(),
                'saved_mapping' => $savedMapping,
                'current_headers' => $currentHeaders,
            ]);

            return response()->json([
                'message' => 'Failed to apply mapping: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Auto-detect column mapping for given headers
     */
    public function autoDetect(Request $request): JsonResponse
    {
        $headers = $request->input('headers', []);

        if (empty($headers)) {
            return response()->json([
                'message' => 'Headers are required',
            ], 422);
        }

        try {
            $mapping = $this->mappingService->autoDetectMapping($headers);

            Log::debug('Auto-detected mapping', [
                'headers' => $headers,
                'mapping' => $mapping,
            ]);

            return response()->json([
                'mapping' => $mapping,
                'headers' => $headers,
            ]);

        } catch (\Exception $e) {
            Log::error('Auto-detection failed', [
                'error' => $e->getMessage(),
                'headers' => $headers,
            ]);

            return response()->json([
                'message' => 'Auto-detection failed: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Calculate compatibility score between saved mapping and current headers
     */
    private function calculateCompatibility(array $savedMapping, array $currentHeaders): float
    {
        if (empty($savedMapping) || empty($currentHeaders)) {
            return 0.0;
        }

        $totalFields = count(array_filter($savedMapping, fn ($value) => $value !== null));
        if ($totalFields === 0) {
            return 0.0;
        }

        $matchedFields = 0;

        foreach ($savedMapping as $field => $value) {
            if ($value === null) {
                continue;
            }

            // Check if this is an index-based or header-based mapping
            if (is_int($value)) {
                // Index-based: check if index is valid
                if (isset($currentHeaders[$value])) {
                    $matchedFields++;
                }
            } else {
                // Header-based: check if header exists
                if (in_array($value, $currentHeaders, true)) {
                    $matchedFields++;
                } else {
                    // Try fuzzy matching
                    $bestMatch = $this->mappingService->findBestHeaderMatch($value, $currentHeaders);
                    if ($bestMatch !== null) {
                        $matchedFields += 0.7; // Partial score for fuzzy match
                    }
                }
            }
        }

        return $totalFields > 0 ? round($matchedFields / $totalFields, 2) : 0.0;
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
