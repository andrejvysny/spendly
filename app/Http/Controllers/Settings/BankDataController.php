<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\Account;
use App\Services\GoCardlessBankData;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Http;

class BankDataController extends Controller
{
    private GoCardlessBankData $client;
    public function __construct()
    {

        $this->client = new GoCardlessBankData(
            getenv("GOCARDLESS_SECRET_ID"),
            getenv("GOCARDLESS_SECRET_KEY"),
            false
        );
    }

    public function edit(Request $request): Response
    {
        return Inertia::render('settings/bank_data', []);
    }

    public function getInstitutions(Request $request): JsonResponse
    {
        $request->validate([
            'country' => 'required|string|size:2',
        ]);
        Log::info('Fetching institutions from GoCardless API', ['country' => $request->country]);
        $institutions = $this->client->getInstitutions($request->country);
        return response()->json($institutions);
    }


    public function getRequisitions(): JsonResponse
    {
        $existingRequisitions = $this->client->getRequisitions();
        return response()->json($existingRequisitions);
    }

    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        dd($request);
        $request->user()->fill($request->validated());
        $request->user()->save();

        return to_route('bank_data.edit');
    }

    /**
     * Delete the user's account.
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
            return response()->json(['error' => 'Failed to delete requisition: ' . $e->getMessage()], 500);
        }
    }




    public function createRequisition(Request $request)
    {
        $request->validate(['institution_id' => 'required|string']);
        Log::info('Importing account from GoCardless', ['institution_id' => $request->institution_id]);

        try {
            // Step 1: Create end user agreement
            //$agreement = $this->client->createEndUserAgreement(
            //     $request->institution_id,
            //     [] // Optional user data
            //);

            //Log::info('Agreement created', ['agreement_id' => $agreement['id']]);

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
            return response()->json(['link' => $requisition['link'],]);
        } catch (\Exception $e) {
            Log::error('GoCardless import error', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString(),]);
            return response()->json(['error' => 'Failed to import account: ' . $e->getMessage()], 500);
        }
    }


    public function handleRequisitionCallback(Request $request)
    {
        if ($request->get('error') === 'ConsentLinkReused') {
            Log::error('GoCardless callback error', [
                'error' => $request->get('error'),
                'error_description' => $request->get('details'),
            ]);
            return redirect()->route('bank_data.edit')->with('error', 'Error during authentication: ' . $request->get('error_description'));
        }

        if ($request->get('error') === 'UserCancelledSession') {
            Log::info('User cancelled the session');
            session()->forget('gocardless_requisition_id');
            return redirect()->route('bank_data.edit')->with('error', 'User cancelled the session');
        }

        Log::info('Handling GoCardless callback');
        $requisitionId = session('gocardless_requisition_id');

        if (!$requisitionId) {
            return redirect()->route('bank_data.edit')->with('error', 'Invalid session');
        }

        Log::info('Callback Requisition ID', ['id' => $requisitionId]);
        try {
            // Get the accounts associated with the requisition using the wrapper
            $accountIds = $this->client->getAccounts($requisitionId);

            Log::info('Retrieved accounts', ['account_ids' => $accountIds]);
            session()->forget('gocardless_requisition_id');

            return redirect()->route('bank_data.edit')->with('success', 'Requsition completed successfully. Accounts imported: ' . count($accountIds));

        } catch (\Exception $e) {
            Log::error('GoCardless callback error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()->route('bank_data.edit')->with('error', 'Failed to import accounts: ' . $e->getMessage());
        }
    }


    public function importAccount(Request $request)
    {
        $request->validate(['account_id' => 'required|string']);

        $accountId = $request->account_id;

        Log::info('Importing account with requisition', ['account_id' => $request->account_id]);

        if(Account::where('user_id', auth()->id())
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
                'error' => $e->getMessage()
            ]);
        }

        return response()->json([
            'message' => 'Account imported successfully',
            'account_id' => $accountId,
        ]);
    }


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
