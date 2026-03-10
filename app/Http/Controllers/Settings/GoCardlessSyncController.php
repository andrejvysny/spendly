<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Contracts\Repositories\AccountRepositoryInterface;
use App\Exceptions\GoCardlessRateLimitException;
use App\Http\Controllers\Controller;
use App\Jobs\RecurringDetectionJob;
use App\Models\RecurringDetectionSetting;
use App\Models\User;
use App\Services\AccountBalanceService;
use App\Services\GoCardless\GoCardlessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GoCardlessSyncController extends Controller
{
    private GoCardlessService $gocardlessService;

    private AccountBalanceService $balanceService;

    private AccountRepositoryInterface $accountRepository;

    public function __construct(
        GoCardlessService $gocardlessService,
        AccountBalanceService $balanceService,
        AccountRepositoryInterface $accountRepository
    ) {
        $this->gocardlessService = $gocardlessService;
        $this->balanceService = $balanceService;
        $this->accountRepository = $accountRepository;
    }

    /**
     * Sync transactions for a specific account.
     */
    public function syncAccountTransactions(Request $request, int $account): JsonResponse
    {
        try {
            $user = $request->user();
            if (! $user instanceof User) {
                return response()->json([
                    'success' => false,
                    'error' => 'User not authenticated or invalid user type',
                ], 401);
            }

            $updateExisting = $request->boolean('update_existing', true);
            $forceMaxDateRange = $request->boolean('force_max_date_range', false);

            $result = $this->gocardlessService->syncAccountTransactions($account, $user, $updateExisting, $forceMaxDateRange);

            $settings = RecurringDetectionSetting::forUser($user->id);
            if ($settings->run_after_import) {
                RecurringDetectionJob::dispatch($user->id, $account);
            }

            return response()->json([
                'success' => true,
                'message' => 'Transactions synced successfully',
                'data' => $result,
            ]);

        } catch (GoCardlessRateLimitException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'retry_after' => $e->retryAfterSeconds,
            ], 429);
        } catch (\Exception $e) {
            $user = $request->user();
            $userId = $user ? $user->getKey() : 'unknown';

            Log::error('Transaction sync error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'account_id' => $account,
                'user_id' => $userId,
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to sync transactions: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Sync all GoCardless accounts for the authenticated user.
     */
    public function syncAllAccounts(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            if (! $user instanceof User) {
                return response()->json([
                    'success' => false,
                    'error' => 'User not authenticated or invalid user type',
                ], 401);
            }

            $updateExisting = $request->boolean('update_existing', true);
            $forceMaxDateRange = $request->boolean('force_max_date_range', false);

            $results = $this->gocardlessService->syncAllAccounts($user, $updateExisting, $forceMaxDateRange);

            $settings = RecurringDetectionSetting::forUser($user->id);
            if ($settings->run_after_import) {
                foreach ($results as $result) {
                    $accountId = $result['account_id'] ?? null;
                    if (($result['status'] ?? '') !== 'success' || $accountId === null) {
                        continue;
                    }
                    RecurringDetectionJob::dispatch($user->id, (int) $accountId);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'All accounts synced',
                'data' => $results,
            ]);

        } catch (GoCardlessRateLimitException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'retry_after' => $e->retryAfterSeconds,
            ], 429);
        } catch (\Exception $e) {
            $user = $request->user();
            $userId = $user ? $user->getKey() : 'unknown';

            Log::error('Sync all accounts error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $userId,
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to sync accounts: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Refresh account balance from GoCardless API without syncing transactions.
     */
    public function refreshAccountBalance(Request $request, int $accountId): JsonResponse
    {
        try {
            $user = $request->user();
            if (! $user instanceof User) {
                return response()->json([
                    'success' => false,
                    'error' => 'User not authenticated',
                ], 401);
            }

            $account = $this->accountRepository->findByIdForUser($accountId, $user->id);

            if (! $account) {
                return response()->json([
                    'success' => false,
                    'error' => 'Account not found',
                ], 404);
            }

            if (! $account->is_gocardless_synced) {
                // For non-GoCardless accounts, recalculate from transactions
                $success = $this->balanceService->recalculateForAccount($account);
                $source = 'transactions';
            } else {
                // For GoCardless accounts, refresh from API
                $success = $this->gocardlessService->refreshAccountBalance($account);
                $source = 'gocardless_api';
            }

            if ($success) {
                $account->refresh();

                return response()->json([
                    'success' => true,
                    'message' => 'Balance refreshed successfully',
                    'data' => [
                        'account_id' => $account->id,
                        'balance' => (float) $account->balance,
                        'currency' => $account->currency,
                        'source' => $source,
                    ],
                ]);
            }

            return response()->json([
                'success' => false,
                'error' => 'Could not refresh balance',
            ], 500);
        } catch (\Exception $e) {
            Log::error('Failed to refresh account balance', [
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to refresh balance: '.$e->getMessage(),
            ], 500);
        }
    }
}
