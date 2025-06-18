<?php

namespace App\Http\Controllers\BankProviders;

use App\Http\Controllers\Controller;
use App\Services\GoCardlessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GoCardlessController extends Controller
{
    public function __construct(
        private GoCardlessService $gocardlessService
    ) {}

    /**
     * Synchronizes transactions for the specified account with GoCardless.
     *
     * @param Request $request
     * @param int $accountId The ID of the account to synchronize.
     * @return JsonResponse JSON response indicating the outcome of the synchronization.
     */
    public function syncTransactions(Request $request, int $accountId): JsonResponse
    {
        try {
            // Get updateExisting parameter from request, default to true
            $updateExisting = $request->boolean('update_existing', true);
            
            // Get forceMaxDateRange parameter from request, default to false
            $forceMaxDateRange = $request->boolean('force_max_date_range', false);
            
            $result = $this->gocardlessService->syncAccountTransactions($accountId, $request->user(), $updateExisting, $forceMaxDateRange);

            return response()->json([
                'success' => true,
                'message' => 'Transactions synced successfully',
                'data' => $result,
            ]);

        } catch (\Exception $e) {
            Log::error('Transaction sync error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'account_id' => $accountId,
                'user_id' => $request->user()->id,
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to sync transactions: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Sync all GoCardless accounts for the authenticated user.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function syncAllAccounts(Request $request): JsonResponse
    {
        try {
            // Get updateExisting parameter from request, default to true
            $updateExisting = $request->boolean('update_existing', true);
            
            // Get forceMaxDateRange parameter from request, default to false
            $forceMaxDateRange = $request->boolean('force_max_date_range', false);
            
            $results = $this->gocardlessService->syncAllAccounts($request->user(), $updateExisting, $forceMaxDateRange);

            return response()->json([
                'success' => true,
                'message' => 'All accounts synced',
                'data' => $results,
            ]);

        } catch (\Exception $e) {
            Log::error('Sync all accounts error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()->id,
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to sync accounts: ' . $e->getMessage()
            ], 500);
        }
    }
}
