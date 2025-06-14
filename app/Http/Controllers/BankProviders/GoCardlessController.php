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





}
