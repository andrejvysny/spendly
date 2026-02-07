<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Contracts\Repositories\AccountRepositoryInterface;
use App\Exceptions\AccountAlreadyExistsException;
use App\Http\Controllers\Controller;
use App\Jobs\RecurringDetectionJob;
use App\Models\Account;
use App\Models\RecurringDetectionSetting;
use App\Models\User;
use App\Services\AccountBalanceService;
use App\Services\GoCardless\GoCardlessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class BankDataController extends Controller
{
    private User $user;

    private GoCardlessService $gocardlessService;

    private AccountBalanceService $balanceService;

    private AccountRepositoryInterface $accountRepository;

    /**
     * Initializes the controller with the authenticated user and GoCardless service.
     * User is loaded for the settings form (edit/update). API actions use the client via the service (factory: mock or production).
     */
    public function __construct(
        GoCardlessService $gocardlessService,
        AccountBalanceService $balanceService,
        AccountRepositoryInterface $accountRepository
    ) {
        $this->gocardlessService = $gocardlessService;
        $this->balanceService = $balanceService;
        $this->accountRepository = $accountRepository;

        $id = auth()->id();
        $found = $id ? User::select('id', 'gocardless_secret_id', 'gocardless_secret_key')->find($id) : null;
        $this->user = $found ?? new User;
    }

    /**
     * Displays the GoCardless bank data settings page for the authenticated user.
     *
     * Passes the user's GoCardless secret ID and key to the view for editing.
     *
     * @return Response Inertia response rendering the bank data settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/bank_data', [
            'gocardless_secret_id' => $this->user->gocardless_secret_id,
            'gocardless_secret_key' => $this->user->gocardless_secret_key,
            'gocardless_use_mock' => config('services.gocardless.use_mock'),
        ]);
    }

    /**
     * Updates the authenticated user's GoCardless secret ID and key.
     *
     * Validates and saves the provided GoCardless credentials for the current user. Redirects to the bank data edit page after updating.
     *
     * @param  Request  $request  The HTTP request containing optional GoCardless credentials.
     * @return RedirectResponse Redirects to the bank data edit view.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'gocardless_secret_id' => ['nullable', 'string'],
            'gocardless_secret_key' => ['nullable', 'string'],
        ]);

        // TODO store encrypted credentials in the database with APP_KEY

        $user = $request->user();
        if (! $user instanceof User) {
            return to_route('bank_data.edit');
        }
        $user->fill([
            'gocardless_secret_id' => $validated['gocardless_secret_id'] ?? null,
            'gocardless_secret_key' => $validated['gocardless_secret_key'] ?? null,
        ]);
        $user->save();

        return to_route('bank_data.edit');
    }

    /**
     * Removes all stored GoCardless credentials and tokens from the authenticated user.
     *
     * Redirects to the bank data settings page with a success message after purging the credentials.
     */
    public function purgeGoCardlessCredentials(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return to_route('bank_data.edit');
        }
        $user->gocardless_secret_id = null;
        $user->gocardless_secret_key = null;
        $user->gocardless_access_token = null;
        $user->gocardless_refresh_token = null;
        $user->gocardless_refresh_token_expires_at = null;
        $user->gocardless_access_token_expires_at = null;
        $user->save();

        return to_route('bank_data.edit')->with('success', 'GoCardless credentials purged successfully.');
    }

    /**
     * Retrieves a list of financial institutions available in the specified country from the GoCardless API.
     *
     * @param  Request  $request  The request containing a required two-character country code.
     * @return JsonResponse JSON response with the list of institutions.
     */
    public function getInstitutions(Request $request): JsonResponse
    {
        $request->validate([
            'country' => 'required|string|size:2',
        ]);

        try {
            $user = $request->user();
            if (! $user instanceof User) {
                return response()->json(['error' => 'Unauthenticated'], 401);
            }
            $country = $request->input('country');
            $country = is_string($country) ? $country : '';
            Log::info('Fetching institutions from GoCardless API', ['country' => $country]);
            $institutions = $this->gocardlessService->getInstitutions($country, $user);

            return response()->json($institutions);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            Log::error('Failed to fetch institutions', [
                'country' => $request->country,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Failed to fetch institutions: '.$e->getMessage()], 500);
        }
    }

    /**
     * Retrieves the user's existing GoCardless requisitions with enriched account details and returns them as a JSON response.
     *
     * @return JsonResponse List of requisitions with accounts enriched (name, iban, currency, status, etc.).
     */
    public function getRequisitions(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            if (! $user instanceof User) {
                return response()->json(['error' => 'Unauthenticated'], 401);
            }
            $existingRequisitions = $this->gocardlessService->getRequisitionsList($user);

            foreach ($existingRequisitions['results'] ?? [] as $i => $req) {
                $accountIds = $req['accounts'] ?? [];
                if ($accountIds !== []) {
                    $existingRequisitions['results'][$i]['accounts'] = $this->gocardlessService->getEnrichedAccountsForRequisition(
                        $accountIds,
                        $user
                    );
                }
            }

            return response()->json($existingRequisitions);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            Log::error('Failed to fetch requisitions', [
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Failed to fetch requisitions: '.$e->getMessage()], 500);
        }
    }

    /**
     * Deletes the authenticated user's account after validating the current password.
     *
     * Logs out the user, deletes their account, invalidates the session, and redirects to the homepage.
     *
     * @return RedirectResponse Redirects to the homepage after account deletion.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();
        if (! $user instanceof User) {
            return redirect('/');
        }

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    /**
     * Deletes a GoCardless requisition by its ID.
     *
     * Optionally deletes all local accounts that were imported from this requisition (and their transactions and related data).
     * When delete_imported_accounts=1, finds accounts by requisition's linked GoCardless account IDs, deletes their transactions then the accounts, then deletes the requisition from GoCardless.
     *
     * @param  string  $id  The ID of the requisition to delete.
     * @return \Illuminate\Http\JsonResponse JSON response with a success message or error details.
     */
    public function deleteRequisition(Request $request, string $id): JsonResponse
    {
        Log::info('Deleting GoCardless requisition', ['id' => $id]);
        try {
            $user = $request->user();
            if (! $user instanceof User) {
                return response()->json(['error' => 'Unauthenticated'], 401);
            }

            $deleteImportedAccounts = $request->boolean('delete_imported_accounts', false);

            if ($deleteImportedAccounts) {
                $goCardlessAccountIds = $this->gocardlessService->getAccounts($id, $user);
                if ($goCardlessAccountIds !== []) {
                    $accounts = Account::where('user_id', $user->id)
                        ->whereIn('gocardless_account_id', $goCardlessAccountIds)
                        ->get();

                    foreach ($accounts as $account) {
                        $account->transactions()->delete();
                        $this->accountRepository->delete($account);
                    }
                    Log::info('Deleted imported accounts for requisition', [
                        'requisition_id' => $id,
                        'account_count' => $accounts->count(),
                    ]);
                }
            }

            $this->gocardlessService->deleteRequisition($id, $user);
            Log::info('Requisition deleted successfully', ['id' => $id]);

            return response()->json(['message' => 'Requisition deleted successfully']);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            Log::error('Failed to delete requisition', [
                'id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['error' => 'Failed to delete requisition: '.$e->getMessage()], 500);
        }
    }

    /**
     * Creates a new GoCardless requisition for the specified institution and returns an authentication link.
     *
     * Validates the institution ID from the request, initiates a requisition with GoCardless, stores the requisition ID in the session, and returns the authentication link as JSON. Returns a JSON error response with status 500 if the operation fails.
     *
     * @return \Illuminate\Http\JsonResponse JSON response containing the authentication link or an error message.
     */
    public function createRequisition(Request $request): JsonResponse
    {
        $request->validate([
            'institution_id' => 'required|string',
            'return_to' => 'nullable|string|in:accounts,bank_data',
        ]);
        Log::info('Importing account from GoCardless', ['institution_id' => $request->institution_id]);

        try {
            $user = $request->user();
            if (! $user instanceof User) {
                return response()->json(['error' => 'Unauthenticated'], 401);
            }
            $baseUrl = config('app.url');
            $redirectUrl = (is_string($baseUrl) ? $baseUrl : '').'/api/bank-data/gocardless/requisition/callback';
            $institutionId = $request->input('institution_id');
            $institutionId = is_string($institutionId) ? $institutionId : '';
            $requisition = $this->gocardlessService->createRequisition(
                $institutionId,
                $redirectUrl,
                $user
            );

            Log::info('Requisition created', ['requisition' => $requisition]);
            session(['gocardless_requisition_id' => $requisition['id']]);
            $returnTo = $request->input('return_to');
            if ($returnTo === 'accounts') {
                session(['gocardless_return_to' => 'accounts']);
            } else {
                session()->forget('gocardless_return_to');
            }

            return response()->json(['link' => $requisition['link']]);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            Log::error('GoCardless import error', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            return response()->json(['error' => 'Failed to import account: '.$e->getMessage()], 500);
        }
    }

    /**
     * Handles the callback from GoCardless after user authentication.
     *
     * Processes callback errors such as consent link reuse or user cancellation, retrieves associated account IDs for a completed requisition, and redirects with appropriate success or error messages.
     */
    public function handleRequisitionCallback(Request $request): RedirectResponse
    {
        $redirectToAccounts = session('gocardless_return_to') === 'accounts';

        if ($request->get('error') === 'ConsentLinkReused') {
            Log::error('GoCardless callback error', [
                'error' => $request->get('error'),
                'error_description' => $request->get('details'),
            ]);

            $detail = $request->get('error_description');
            session()->forget('gocardless_return_to');
            $route = $redirectToAccounts ? route('accounts.index') : route('bank_data.edit');

            return redirect($route)->with('error', 'Error during authentication: '.(is_string($detail) ? $detail : ''));
        }

        if ($request->get('error') === 'UserCancelledSession') {
            Log::info('User cancelled the session');
            session()->forget('gocardless_requisition_id');
            session()->forget('gocardless_return_to');
            $route = $redirectToAccounts ? route('accounts.index') : route('bank_data.edit');

            return redirect($route)->with('error', 'User cancelled the session');
        }

        Log::info('Handling GoCardless callback');
        $requisitionId = session('gocardless_requisition_id');

        if (! $requisitionId) {
            session()->forget('gocardless_return_to');

            return redirect()->route($redirectToAccounts ? 'accounts.index' : 'bank_data.edit')->with('error', 'Invalid session');
        }

        $user = auth()->user();
        if (! $user instanceof User) {
            session()->forget('gocardless_return_to');

            return redirect()->route($redirectToAccounts ? 'accounts.index' : 'bank_data.edit')->with('error', 'Session expired. Please sign in again.');
        }

        $requisitionIdStr = is_string($requisitionId) ? $requisitionId : '';
        Log::info('Callback Requisition ID', ['id' => $requisitionIdStr]);
        try {
            $accountIds = $this->gocardlessService->getAccounts($requisitionIdStr, $user);

            Log::info('Retrieved accounts', ['account_ids' => $accountIds]);
            session()->forget('gocardless_requisition_id');
            session()->forget('gocardless_return_to');

            // Do not auto-import: accounts are imported only when the user clicks "Import" on each account in the requisition list.
            $count = is_array($accountIds) ? count($accountIds) : 0;
            $route = $redirectToAccounts ? route('accounts.index') : route('bank_data.edit');
            $message = $count > 0
                ? "Bank connected. {$count} account(s) are available—import them from the list below."
                : 'Bank connected. Import accounts from the list below when ready.';

            if ($redirectToAccounts) {
                return redirect($route)->with('success', $message)->with('open_go_cardless_modal', true);
            }

            return redirect($route)->with('success', $message);
        } catch (\RuntimeException $e) {
            session()->forget('gocardless_return_to');

            return redirect()->route($redirectToAccounts ? 'accounts.index' : 'bank_data.edit')->with('error', $e->getMessage());
        } catch (\Exception $e) {
            Log::error('GoCardless callback error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            session()->forget('gocardless_return_to');

            return redirect()->route($redirectToAccounts ? 'accounts.index' : 'bank_data.edit')->with('error', 'Failed to get accounts: '.$e->getMessage());
        }
    }

    /**
     * Imports a GoCardless account for the authenticated user by account ID.
     *
     * Validates the provided account ID, checks for duplicates, retrieves account details from GoCardless, and creates a new local account record if it does not already exist. Returns a JSON response indicating success or if the account already exists.
     */
    public function importAccount(Request $request): JsonResponse
    {
        $validated = $request->validate(['account_id' => 'required|string']);
        $accountId = (string) $validated['account_id'];
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        Log::info('Importing account with requisition', ['account_id' => $accountId]);

        try {
            $account = $this->gocardlessService->importAccount($accountId, $user);

            return response()->json([
                'message' => 'Account imported successfully',
                'account_id' => $account->gocardless_account_id,
            ]);
        } catch (AccountAlreadyExistsException) {
            return response()->json(['message' => 'Account already exists'], 400);
        } catch (\Exception $e) {
            Log::error('Failed to process account', [
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to import account',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Checks if a GoCardless account ID already exists for the authenticated user.
     *
     * Returns a JSON response indicating whether the specified GoCardless account ID is associated with the current user.
     *
     * @return JsonResponse JSON object with an 'exists' boolean field.
     */
    public function getExistingGocardlessAccountIDs(Request $request): JsonResponse
    {
        $accountId = $request->account_id;

        Log::info('Checking if account exists', ['account_id' => $accountId]);

        $exists = Account::where('user_id', auth()->id())
            ->where('gocardless_account_id', $accountId)
            ->exists();

        return response()->json(['exists' => $exists]);
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
