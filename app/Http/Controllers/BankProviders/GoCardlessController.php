<?php

namespace App\Http\Controllers\BankProviders;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class GoCardlessController extends Controller
{
    /**
     * Synchronizes transactions for the specified account with GoCardless.
     *
     * Retrieves booked transactions from GoCardless for the given account, creates new transaction records if they do not already exist, updates the account's last synced timestamp, and returns a JSON response with the results. Returns an error response if the account is not synced with GoCardless or if an exception occurs during the process.
     *
     * @param  int  $account  The ID of the account to synchronize.
     * @return JsonResponse JSON response indicating the outcome of the synchronization, including counts of total and existing transactions.
     */
    public function syncTransactions(int $account): JsonResponse
    {
        Log::info('Syncing transactions for account', [
            'account_id' => $account,
        ]);

        try {
            $account = Account::findOrFail($account);

            if (! $account->is_gocardless_synced) {
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

            return response()->json(['message' => 'Transactions synced successfully', 'count' => count($bookedTransactions), 'count_existing' => count($existing)]);
        } catch (\Exception $e) {
            Log::error('Transaction sync error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['error' => 'Failed to sync transactions: '.$e->getMessage()], 500);
        }
    }
}
