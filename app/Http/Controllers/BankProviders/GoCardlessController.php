<?php

namespace App\Http\Controllers\BankProviders;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Transaction;
use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoCardlessController extends Controller
{
    /**
     * The base URL for the GoCardless API.
     */
    private string $baseUrl = 'https://bankaccountdata.gocardless.com/api/v2';

    /**
     * Get a list of institutions for a given country.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getInstitutions(Request $request)
    {
        $request->validate([
            'country' => 'required|string|size:2',
        ]);

        Log::info('Importing account', ['country' => $request->country]);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.config('services.gocardless.access_token'),
                'accept' => 'application/json',
            ])->get("{$this->baseUrl}/institutions/", [
                'country' => $request->country,
            ]);

            if (! $response->successful()) {
                Log::error('GoCardless API error', [
                    'status' => $response->status(),
                    'body' => json_encode($response->body()),
                ]);

                return response()->json(['error' => 'Failed to fetch institutions'], 500);
            }

            Log::info('Institutions');

            return response()->json($response->json());
        } catch (\Exception $e) {
            Log::error('GoCardless API exception', [
                'message' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Failed to fetch institutions'], 500);
        }
    }

    /**
     * Import an account from GoCardless.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function importAccount(Request $request)
    {
        $request->validate([
            'institution_id' => 'required|string',
        ]);

        try {
            // Step 1: Get access token
            $tokenResponse = Http::post("{$this->baseUrl}/token/new/", [
                'secret_id' => config('services.gocardless.secret_id'),
                'secret_key' => config('services.gocardless.secret_key'),
            ]);

            if (! $tokenResponse->successful()) {
                return response()->json(['error' => 'Invalid credentials'], 401);
            }

            $accessToken = $tokenResponse->json()['access'];

            // Step 2: Create end user agreement
            $agreementResponse = Http::withHeaders([
                'Authorization' => 'Bearer '.$accessToken,
                'accept' => 'application/json',
            ])->post("{$this->baseUrl}/agreements/enduser/", [
                'institution_id' => $request->institution_id,
                'max_historical_days' => 700,
                'access_valid_for_days' => 90,
                'access_scope' => ['balances', 'details', 'transactions'],
            ]);

            if (! $agreementResponse->successful()) {
                return response()->json(['error' => 'Failed to create agreement'], 500);
            }

            $agreementId = $agreementResponse->json()['id'];

            // Step 3: Create requisition
            $requisitionResponse = Http::withHeaders([
                'Authorization' => 'Bearer '.$accessToken,
                'accept' => 'application/json',
            ])->post("{$this->baseUrl}/requisitions/", [
                'redirect' => config('app.url').'/api/gocardless/callback',
                'institution_id' => $request->institution_id,
                'reference' => uniqid(),
                'agreement' => $agreementId,
                'user_language' => 'EN',
            ]);

            if (! $requisitionResponse->successful()) {
                return response()->json(['error' => 'Failed to create requisition'], 500);
            }

            $requisitionData = $requisitionResponse->json();

            // Store the requisition ID in the session for later use
            session(['gocardless_requisition_id' => $requisitionData['id']]);

            // Return the link for the user to authenticate
            return response()->json([
                'link' => $requisitionData['link'],
            ]);

        } catch (\Exception $e) {
            Log::error('GoCardless import error', [
                'message' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Failed to import account'], 500);
        }
    }

    public function handleCallback(Request $request)
    {
        Log::info('Handling callback');
        $requisitionId = session('gocardless_requisition_id');
        if (! $requisitionId) {
            return redirect()->route('accounts.index')->with('error', 'Invalid session');
        }

        Log::info('Requisition ID');
        try {
            // Get the accounts associated with the requisition
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.config('services.gocardless.access_token'),
                'accept' => 'application/json',
            ])->get("{$this->baseUrl}/requisitions/{$requisitionId}/");

            Log::info('Response');

            if (! $response->successful()) {
                return redirect()->route('accounts.index')->with('error', 'Failed to fetch accounts');
            }

            $requisitionData = $response->json();

            Log::info('Requisition Data');

            // For each account, fetch details and create in our system
            foreach ($requisitionData['accounts'] as $accountId) {
                $accountResponse = Http::withHeaders([
                    'Authorization' => 'Bearer '.config('services.gocardless.access_token'),
                    'accept' => 'application/json',
                ])->get("{$this->baseUrl}/accounts/{$accountId}/details/");
                Log::info('Status Response', ['success' => $accountResponse->successful()]);
                Log::info('Account Response', ['data' => $accountResponse->json()]);

                if ($accountResponse->successful()) {
                    $accountData = $accountResponse->json()['account'];
                    Log::info('Account Response');
                    // Create account in our system
                    Account::create([
                        'user_id' => auth()->id(),
                        'name' => 'Imported Account '.($accountData['ownerName'] ?? ''),
                        'gocardless_account_id' => $accountId,
                        'bank_name' => $accountData['institution'] ?? null,
                        'iban' => $accountData['iban'] ?? '',
                        'type' => 'checking',
                        'currency' => $accountData['currency'] ?? 'EUR',
                        'balance' => 0,
                        'is_gocardless_synced' => true,
                        'gocardless_last_synced_at' => now(),
                    ]);
                } else {
                    session()->flash('error', 'Failed to fetch account details');
                    Log::error('Account Response', ['data' => $accountResponse->json()]);
                }
            }

            return redirect()->route('accounts.index')->with('success', 'Accounts imported successfully');

        } catch (\Exception $e) {
            Log::error('GoCardless callback error', [
                'message' => $e->getMessage(),
            ]);

            return redirect()->route('accounts.index')->with('error', 'Failed to import accounts');
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
                            'description' => implode(' ', $transaction['remittanceInformationUnstructuredArray'] ?? []),
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

    /**
     * Sync transactions for an account.
     *
     * @return void
     */
    public function syncTransactions(Account $account)
    {
        if (! $account->is_gocardless_synced) {
            return response()->json(['error' => 'Account is not synced with GoCardless'], 400);
        }

        try {
            // Get access token
            $tokenResponse = Http::post("{$this->baseUrl}/token/new/", [
                'secret_id' => $account->user->gocardless_secret_id,
                'secret_key' => $account->user->gocardless_secret_key,
            ]);

            if (! $tokenResponse->successful()) {
                return response()->json(['error' => 'Invalid credentials'], 401);
            }

            $accessToken = $tokenResponse->json()['access'];

            // Get transactions
            $transactionsResponse = Http::withHeaders([
                'Authorization' => 'Bearer '.$accessToken,
                'accept' => 'application/json',
            ])->get("{$this->baseUrl}/accounts/{$account->gocardless_account_id}/transactions/");

            if (! $transactionsResponse->successful()) {
                return response()->json(['error' => 'Failed to fetch transactions'], 500);
            }

            $transactions = $transactionsResponse->json()['results'];

            // Process transactions
            foreach ($transactions as $transaction) {
                Transaction::updateOrCreate(
                    ['transaction_id' => $transaction['id']],
                    [
                        'account_id' => $account->id,
                        'amount' => $transaction['amount'],
                        'currency' => $transaction['currency'],
                        'description' => $transaction['description'],
                        'date' => $transaction['date'],
                        'type' => $transaction['type'],
                        'status' => $transaction['status'],
                    ]
                );
            }

            // Update last synced timestamp
            $account->update([
                'gocardless_last_synced_at' => now(),
            ]);

            return response()->json(['message' => 'Transactions synced successfully']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to sync transactions: '.$e->getMessage()], 500);
        }
    }
}
