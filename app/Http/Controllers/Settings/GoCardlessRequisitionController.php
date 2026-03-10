<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Contracts\Repositories\AccountRepositoryInterface;
use App\Exceptions\AccountAlreadyExistsException;
use App\Exceptions\GoCardlessRateLimitException;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\User;
use App\Services\GoCardless\GoCardlessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GoCardlessRequisitionController extends Controller
{
    private GoCardlessService $gocardlessService;

    private AccountRepositoryInterface $accountRepository;

    public function __construct(
        GoCardlessService $gocardlessService,
        AccountRepositoryInterface $accountRepository
    ) {
        $this->gocardlessService = $gocardlessService;
        $this->accountRepository = $accountRepository;
    }

    /**
     * Retrieves a list of financial institutions available in the specified country from the GoCardless API.
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
     * Retrieves the user's existing GoCardless requisitions with enriched account details.
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
        } catch (GoCardlessRateLimitException $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'retry_after' => $e->retryAfterSeconds,
            ], 429);
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
     * Creates a new GoCardless requisition for the specified institution and returns an authentication link.
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

            $ref = Str::uuid()->toString();
            $sig = hash_hmac('sha256', $ref, (string) config('app.key'));
            $baseUrl = config('app.url');
            $redirectUrl = (is_string($baseUrl) ? $baseUrl : '').'/api/bank-data/gocardless/requisition/callback?ref='.$ref.'&sig='.$sig;
            $institutionId = $request->input('institution_id');
            $institutionId = is_string($institutionId) ? $institutionId : '';
            $returnTo = $request->input('return_to');

            $requisition = $this->gocardlessService->createRequisition(
                $institutionId,
                $redirectUrl,
                $user
            );

            Log::info('Requisition created', ['requisition_id' => $requisition['id'], 'ref' => $ref]);

            // Store ref -> context in cache (1 hour TTL) as primary lookup
            Cache::put("gocardless_ref:{$ref}", [
                'user_id' => $user->id,
                'requisition_id' => $requisition['id'],
                'return_to' => $returnTo === 'accounts' ? 'accounts' : null,
            ], 3600);

            // Session as secondary fallback
            session(['gocardless_requisition_id' => $requisition['id']]);
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
     */
    public function handleRequisitionCallback(Request $request): RedirectResponse
    {
        // Verify HMAC signature before processing
        $ref = $request->query('ref');
        $ref = is_string($ref) ? $ref : null;
        $sig = $request->query('sig');
        $sig = is_string($sig) ? $sig : '';

        if ($ref === null || ! hash_equals(hash_hmac('sha256', $ref, (string) config('app.key')), $sig)) {
            return redirect()->route('bank_data.edit')->with('error', 'Invalid callback signature');
        }

        // Resolve context: cache ref is primary, session is fallback
        /** @var array{user_id: int, requisition_id: string, return_to: string|null}|null $cacheContext */
        $cacheContext = $ref ? Cache::pull("gocardless_ref:{$ref}") : null;
        $cacheContext = is_array($cacheContext) ? $cacheContext : null;

        $requisitionId = $cacheContext['requisition_id'] ?? session('gocardless_requisition_id');
        $userId = $cacheContext['user_id'] ?? null;
        $returnTo = $cacheContext['return_to'] ?? session('gocardless_return_to');
        $redirectToAccounts = $returnTo === 'accounts';

        if ($request->get('error') === 'ConsentLinkReused') {
            Log::error('GoCardless callback error', [
                'error' => $request->get('error'),
                'error_description' => $request->get('details'),
            ]);

            $detail = $request->get('error_description');
            session()->forget('gocardless_requisition_id');
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

        Log::info('Handling GoCardless callback', ['ref' => $ref, 'has_cache_context' => $cacheContext !== null]);

        if (! $requisitionId) {
            session()->forget('gocardless_return_to');

            return redirect()->route($redirectToAccounts ? 'accounts.index' : 'bank_data.edit')->with('error', 'Invalid session');
        }

        // Resolve user: from cache context (cross-domain safe) or auth session (fallback)
        $user = $userId ? User::find($userId) : auth()->user();
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

            $count = count($accountIds);
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
     * Deletes a GoCardless requisition by its ID.
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
     * Imports a GoCardless account for the authenticated user by account ID.
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
        } catch (GoCardlessRateLimitException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'retry_after' => $e->retryAfterSeconds,
            ], 429);
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
}
