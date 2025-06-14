<?php

namespace App\Http\Controllers\BankProviders;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Transaction;
use App\Services\GoCardlessBankData;
use App\Services\GocardlessMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GoCardlessController extends Controller
{
    private GoCardlessBankData $client;
    private GocardlessMapper $mapper;
    public function __construct()
    {
        $this->client = new GoCardlessBankData(
            getenv("GOCARDLESS_SECRET_ID"),
            getenv("GOCARDLESS_SECRET_KEY"),
        );
        $this->mapper = new GocardlessMapper();
    }


    public function getInstitutions(Request $request): JsonResponse
    {
        $request->validate([
            'country' => 'required|string|size:2',
        ]);

        Log::info('Fetching institutions from GoCardless API', [
            'country' => $request->country,
        ]);

        $institutions = $this->client->getInstitutions($request->country);
        return response()->json($institutions);
    }

    public function syncTransactions(int $account): JsonResponse
    {
        Log::info('Syncing transactions for account', [
            'account_id' => $account,
        ]);

        try {
            $account = Account::findOrFail($account);

            if (!$account->is_gocardless_synced) {
                return response()->json(['error' => 'Account is not synced with GoCardless'], 400);
            }

            $transactions = $this->client->getTransactions($account->gocardless_account_id);

            $bookedTransactions = $transactions['transactions']['booked'] ?? [];

            $existing = [];
            foreach ($bookedTransactions as $transaction) {
                $existing[] = Transaction::firstOrCreate(
                    ['transaction_id' => $transaction['transactionId']],
                    $this->mapper->mapTransactionData($transaction, $account)
                );
            }

            Log::info('Skipped transactions due to existing ID', ['count' => count($existing)]);
            Log::info('New transactions created', ['count' => count($bookedTransactions) - count($existing)]);

            // Update last synced timestamp
            $account->update(['gocardless_last_synced_at' => now()]);

            return response()->json(['message' => 'Transactions synced successfully', 'count'=>count($bookedTransactions),'count_existing' => count($existing)]);
        } catch (\Exception $e) {
            Log::error('Transaction sync error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['error' => 'Failed to sync transactions: '.$e->getMessage()], 500);
        }
    }

    public function requisition(Request $request)
    {
        $request->validate([
            'institution_id' => 'required|string',
        ]);

        Log::info('Importing account from GoCardless', [
            'institution_id' => $request->institution_id,
        ]);

        try {
            // Step 1: Create end user agreement
            //$agreement = $this->client->createEndUserAgreement(
           //     $request->institution_id,
           //     [] // Optional user data
            //);

            //Log::info('Agreement created', ['agreement_id' => $agreement['id']]);

            // Step 2: Create requisition
            $redirectUrl = config('app.url').'/api/gocardless/callback';
            $requisition = $this->client->createRequisition(
                $request->institution_id,
                $redirectUrl,
              //  $agreement['id']
            );

            Log::info('Requisition created', ['requisition' => $requisition]);

            // Store the requisition ID in the session for later use
            session(['gocardless_requisition_id' => $requisition['id']]);

            // Return the link for the user to authenticate
            return response()->json([
                'link' => $requisition['link'],
            ]);

        } catch (\Exception $e) {
            Log::error('GoCardless import error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['error' => 'Failed to import account: ' . $e->getMessage()], 500);
        }
    }

    public function importAccountWithAccountId(Request $request)
    {
        $request->validate([
            'account_id' => 'required|string',
        ]);

        $accountId = $request->account_id;

        Log::info('Importing account with requisition', [
            'account_id' => $request->account_id,
        ]);

        if(Account::where('user_id', auth()->id())
            ->where('gocardless_account_id', $accountId)
            ->first()) {
            Log::info('Account already exists', [
                'account_id' => $accountId,
            ]);

            return response()->json([
                'message' => 'Account already exists',
            ], 200);
        }

        try {
            $accountDetails = $this->client->getAccountDetails($accountId);
            Log::info('Account details retrieved', ['account_id' => $accountId]);

            $accountData = $accountDetails['account'];

            // Create account in our system


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
        ], 200);
    }

    public function handleCallback(Request $request)
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

        Log::info('Requisition ID', ['id' => $requisitionId]);
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

}
