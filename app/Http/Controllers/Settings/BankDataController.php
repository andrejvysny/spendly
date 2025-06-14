<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\User;
use App\Services\GoCardlessBankData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class BankDataController extends Controller
{
    private GoCardlessBankData $client;

    private User $user;

    /**
     * Initializes the controller with the authenticated user's GoCardless credentials and sets up the GoCardless API client.
     *
     * Retrieves the current user's GoCardless credentials from the database. If credentials are present, initializes the GoCardlessBankData client; otherwise, logs a warning.
     */
    public function __construct()
    {
        $this->user = User::select(
            'gocardless_secret_id',
            'gocardless_secret_key',
            'gocardless_access_token',
            'gocardless_refresh_token',
            'gocardless_refresh_token_expires_at',
            'gocardless_access_token_expires_at',
        )
            ->where('id', auth()->id())
            ->first();

        // TODO do correct safa check if the user has gocardless credentials set
        // TODO decrypt the credentials using the APP_KEY
        if (! $this->user->gocardless_secret_id || ! $this->user->gocardless_secret_key) {
            Log::warning('GoCardless credentials not set for user', ['user_id' => $this->user->id]);
        } else {

            $this->client = new GoCardlessBankData(
                $this->user->gocardless_secret_id ?? getenv('GOCARDLESS_SECRET_ID'),
                $this->user->gocardless_secret_key ?? getenv('GOCARDLESS_SECRET_KEY'),
                $this->user->gocardless_access_token ?? null,
                $this->user->gocardless_refresh_token ?? null,
                $this->user->gocardless_refresh_token_expires_at ?? null,
                $this->user->gocardless_access_token_expires_at ?? null,
                false
            );
        }
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

        // Save the GoCardless credentials
        $user = $request->user();
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
        Log::info('Fetching institutions from GoCardless API', ['country' => $request->country]);
        $institutions = $this->client->getInstitutions($request->country);

        return response()->json($institutions);
    }

    /**
     * Retrieves the user's existing GoCardless requisitions and returns them as a JSON response.
     *
     * @return JsonResponse List of requisitions associated with the authenticated user.
     */
    public function getRequisitions(): JsonResponse
    {
        $existingRequisitions = $this->client->getRequisitions();

        return response()->json($existingRequisitions);
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

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    /**
     * Deletes a GoCardless requisition by its ID.
     *
     * Attempts to remove the specified requisition using the GoCardless client. Returns a JSON response indicating success or failure.
     *
     * @param  string  $id  The ID of the requisition to delete.
     * @return \Illuminate\Http\JsonResponse JSON response with a success message or error details.
     */
    public function deleteRequisition(string $id)
    {
        Log::info('Deleting GoCardless requisition', ['id' => $id]);
        try {
            $this->client->deleteRequisition($id);
            Log::info('Requisition deleted successfully', ['id' => $id]);

            return response()->json(['message' => 'Requisition deleted successfully']);
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
    public function createRequisition(Request $request)
    {
        $request->validate(['institution_id' => 'required|string']);
        Log::info('Importing account from GoCardless', ['institution_id' => $request->institution_id]);

        try {
            // Step 1: Create end user agreement
            // $agreement = $this->client->createEndUserAgreement(
            //     $request->institution_id,
            //     [] // Optional user data
            // );

            // Log::info('Agreement created', ['agreement_id' => $agreement['id']]);

            // Step 2: Create requisition
            $redirectUrl = config('app.url').'/api/bank-data/gocardless/requisition/callback';
            $requisition = $this->client->createRequisition(
                $request->institution_id,
                $redirectUrl,
                //  $agreement['id']
            );

            Log::info('Requisition created', ['requisition' => $requisition]);
            // Store the requisition ID in the session for later use
            session(['gocardless_requisition_id' => $requisition['id']]);

            // Return the link for the user to authenticate
            return response()->json(['link' => $requisition['link']]);
        } catch (\Exception $e) {
            Log::error('GoCardless import error', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            return response()->json(['error' => 'Failed to import account: '.$e->getMessage()], 500);
        }
    }

    /**
     * Handles the callback from GoCardless after user authentication.
     *
     * Processes callback errors such as consent link reuse or user cancellation, retrieves associated account IDs for a completed requisition, and redirects with appropriate success or error messages.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function handleRequisitionCallback(Request $request)
    {
        if ($request->get('error') === 'ConsentLinkReused') {
            Log::error('GoCardless callback error', [
                'error' => $request->get('error'),
                'error_description' => $request->get('details'),
            ]);

            return redirect()->route('bank_data.edit')->with('error', 'Error during authentication: '.$request->get('error_description'));
        }

        if ($request->get('error') === 'UserCancelledSession') {
            Log::info('User cancelled the session');
            session()->forget('gocardless_requisition_id');

            return redirect()->route('bank_data.edit')->with('error', 'User cancelled the session');
        }

        Log::info('Handling GoCardless callback');
        $requisitionId = session('gocardless_requisition_id');

        if (! $requisitionId) {
            return redirect()->route('bank_data.edit')->with('error', 'Invalid session');
        }

        Log::info('Callback Requisition ID', ['id' => $requisitionId]);
        try {
            // Get the accounts associated with the requisition using the wrapper
            $accountIds = $this->client->getAccounts($requisitionId);

            Log::info('Retrieved accounts', ['account_ids' => $accountIds]);
            session()->forget('gocardless_requisition_id');

            return redirect()->route('bank_data.edit')->with('success', 'Requsition completed successfully. Accounts imported: '.count($accountIds));

        } catch (\Exception $e) {
            Log::error('GoCardless callback error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()->route('bank_data.edit')->with('error', 'Failed to import accounts: '.$e->getMessage());
        }
    }

    /**
     * Imports a GoCardless account for the authenticated user by account ID.
     *
     * Validates the provided account ID, checks for duplicates, retrieves account details from GoCardless, and creates a new local account record if it does not already exist. Returns a JSON response indicating success or if the account already exists.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function importAccount(Request $request)
    {
        $request->validate(['account_id' => 'required|string']);

        $accountId = $request->account_id;

        Log::info('Importing account with requisition', ['account_id' => $request->account_id]);

        if (Account::where('user_id', auth()->id())
            ->where('gocardless_account_id', $accountId)
            ->first()) {
            Log::info('Account already exists', ['account_id' => $accountId]);

            return response()->json([
                'message' => 'Account already exists',
            ], 400);
        }

        try {
            $accountDetails = $this->client->getAccountDetails($accountId);
            Log::info('Account details retrieved', ['account_id' => $accountId]);

            $accountData = $accountDetails['account'];

            Account::create([
                'user_id' => auth()->id(),
                'name' => 'Imported Account '.($accountData['name'] ?? '').' (GoCardless)',
                'gocardless_account_id' => $accountId,
                'bank_name' => $accountData['institution_id'] ?? null,
                'iban' => $accountData['iban'] ?? '',
                'type' => 'checking',
                'currency' => $accountData['currency'] ?? 'EUR',
                'balance' => 0,
                'is_gocardless_synced' => true,
                'gocardless_last_synced_at' => now(),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to process account', [
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Account imported successfully',
            'account_id' => $accountId,
        ]);
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
}
