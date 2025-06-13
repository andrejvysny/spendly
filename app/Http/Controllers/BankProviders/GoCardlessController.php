<?php

namespace App\Http\Controllers\BankProviders;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Transaction;
use App\Services\GoCardlessBankData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoCardlessController extends Controller
{
    private GoCardlessBankData $client;
    public function __construct()
    {

        $this->client = new GoCardlessBankData(
            getenv("GOCARDLESS_SECRET_ID"),
            getenv("GOCARDLESS_SECRET_KEY"),
        );
    }

    /**
     * The base URL for the GoCardless API.
     */
    private string $baseUrl = 'https://bankaccountdata.gocardless.com/api/v2';

    /**
     * Get a list of institutions for a given country.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getInstitutions(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'country' => 'required|string|size:2',
        ]);

        Log::info('Fetching institutions from GoCardless API', [
            'country' => $request->country,
        ]);

        $institutions = $this->client->getInstitutions($request->country);
        Log::info('Institutions', ['data' => $institutions]);

        return response()->json($institutions);
    }

    public function syncTransactions(int $account): \Illuminate\Http\JsonResponse
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
                    [
                        'account_id' => $account->id,
                        'amount' => $transaction['transactionAmount']['amount'],
                        'currency' => $transaction['transactionAmount']['currency'],
                        'booked_date' => $transaction['bookingDate'],
                        'processed_date' => $transaction['valueDate'] ?? $transaction['bookingDate'],
                        'partner' => $transaction['remittanceInformationUnstructuredArray'][0] ?? null,
                        'description' => implode(" ",$transaction['remittanceInformationUnstructuredArray']),
                        'type' => $transaction['proprietaryBankTransactionCode'],
                        'balance_after_transaction' => $transaction['balanceAfterTransaction']['balanceAmount']['amount'] ?? 0,
                        'metadata' => json_encode($transaction['additionalDataStructured'] ?? []),
                        'import_data' => json_encode($transaction),
                    ]
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

    /**
     * Import an account from GoCardless.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function importAccount(Request $request)
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
        // Handle the callback from GoCardless after the user has authenticated
        if ($request->get('error') === 'ConsentLinkReused') {
            Log::error('GoCardless callback error', [
                'error' => $request->get('error'),
                'error_description' => $request->get('details'),
            ]);
            return redirect()->route('bank_data.edit')->with('error', 'Error during authentication: ' . $request->get('error_description'));
        }

        Log::info('Handling callback');
        $requisitionId = session('gocardless_requisition_id');

        if ($request->get('error') === 'UserCancelledSession') {
            Log::info('User cancelled the session');
            session()->forget('gocardless_requisition_id');
            return redirect()->route('bank_data.edit')->with('error', 'User cancelled the session');
        }


        if (! $requisitionId) {
            return redirect()->route('bank_data.edit')->with('error', 'Invalid session');
        }

        Log::info('Requisition ID', ['id' => $requisitionId]);

        try {
            // Get the accounts associated with the requisition using the wrapper
            $accountIds = $this->client->getAccounts($requisitionId);

            Log::info('Retrieved accounts', ['account_ids' => $accountIds]);
dd($accountIds);
            // For each account, fetch details and create in our system
            foreach ($accountIds as $accountId) {

            }

            // Clear the session data
            session()->forget('gocardless_requisition_id');

            return redirect()->route('accounts.index')->with('success', 'Accounts imported successfully');

        } catch (\Exception $e) {
            Log::error('GoCardless callback error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()->route('accounts.index')->with('error', 'Failed to import accounts: ' . $e->getMessage());
        }
    }

    /**
     * Refresh the access token.
     *
     * @return void
     */
    public function refreshAccessToken()
    {
        $tokenResponse = Http::post("{$this->baseUrl}/token/refresh/", [
            'refresh_token' => config('services.gocardless.refresh_token'),
        ]);
    }

    /**
     * Refresh the refresh token.
     *
     * @return void
     */
    public function refreshRefreshToken()
    {
        $tokenResponse = Http::post("{$this->baseUrl}/token/refresh/", [
            'refresh_token' => config('services.gocardless.refresh_token'),
        ]);
    }

    /**
     * Import transactions for a given date range and optionally for a specific account.
     *
     * @param  string  $dateFrom  Start date in Y-m-d format
     * @param  string  $dateTo  End date in Y-m-d format
     * @param  int|null  $accountId  Optional account ID to import transactions for
     * @return \Illuminate\Http\RedirectResponse
     */
    public function importTransactions(string $dateFrom, string $dateTo, int $accountId)
    {
        try {
            // Get single account
            $account = Account::where('user_id', auth()->id())
                ->where('id', $accountId)
                ->first();

            if (! $account) {
                return redirect()->route('accounts.index')->with('error', 'Account not found');
            }

            $transactionResponse = Http::withHeaders([
                'Authorization' => 'Bearer '.config('services.gocardless.access_token'),
                'Accept' => 'application/json',
            ])->get("{$this->baseUrl}/accounts/{$account->account_id}/transactions/", [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ]);

            Log::info('Transactions Response', ['success' => $transactionResponse->successful()]);

            if ($transactionResponse->successful()) {
                $transactions = $transactionResponse->json()['transactions']['booked'];

                foreach ($transactions as $transaction) {
                    // Create or update transaction
                    Transaction::updateOrCreate(
                        ['transaction_id' => $transaction['transactionId']],
                        [
                            'account_id' => $account->id,
                            'amount' => $transaction['transactionAmount']['amount'],
                            'currency' => $transaction['transactionAmount']['currency'],
                            'booked_date' => $transaction['bookingDate'],
                            'processed_date' => $transaction['valueDate'] ?? $transaction['bookingDate'],
                            'description' => implode(' ', $transaction['remittanceInformationUnstructuredArray']) ?? '',
                            'target_iban' => $transaction['creditorAccount']['iban'] ?? null,
                            'source_iban' => $transaction['debtorAccount']['iban'] ?? null,
                            'partner' => $transaction['creditorName'] ?? $transaction['debtorName'] ?? '',
                            'type' => $this->determineTransactionType($transaction),
                            'metadata' => json_encode($transaction['currencyExchange']),
                            'balance_after_transaction' => $transaction['balanceAfterTransaction'] ?? 0,
                        ]
                    );
                }
            } else {
                Log::error('Failed to fetch transactions', ['response' => $transactionResponse->json()]);

                return redirect()->route('accounts.index')->with('error', 'Failed to import transactions');
            }

            return redirect()->route('accounts.index')->with('success', 'Transactions imported successfully');

        } catch (\Exception $e) {
            Log::error('Transaction import error', [
                'message' => $e->getMessage(),
            ]);

            return redirect()->route('accounts.index')->with('error', 'Failed to import transactions');
        }
    }

    /**
     * Determine transaction type based on transaction data.
     */
    private function determineTransactionType(array $transaction): string
    {
        if (isset($transaction['proprietaryBankTransactionCode'])) {
            return match ($transaction['proprietaryBankTransactionCode']) {
                'TRANSFER' => Transaction::TYPE_TRANSFER,
                'CARD_PAYMENT' => Transaction::TYPE_CARD_PAYMENT,
                'EXCHANGE' => Transaction::TYPE_EXCHANGE,
                'TOPUP' => Transaction::TYPE_DEPOSIT,
                default => Transaction::TYPE_PAYMENT,
            };
        }

        return Transaction::TYPE_PAYMENT;
    }

}
