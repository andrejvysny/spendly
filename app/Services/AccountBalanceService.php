<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Repositories\AccountRepositoryInterface;
use App\Models\Account;
use App\Models\Transaction;
use App\Services\GoCardless\BankDataClientInterface;
use Illuminate\Support\Facades\Log;

/**
 * Service for managing and recalculating account balances.
 */
class AccountBalanceService
{
    public function __construct(
        private readonly AccountRepositoryInterface $accountRepository,
        private readonly ?BankDataClientInterface $bankDataClient = null
    ) {}

    /**
     * Recalculate and update the balance for an account.
     *
     * For GoCardless accounts: Uses latest transaction's balance_after_transaction
     * (API sync will overwrite with authoritative value).
     * For non-GoCardless accounts: Uses latest transaction's balance_after_transaction
     * or opening_balance + sum(transactions).
     */
    public function recalculateForAccount(Account $account): bool
    {
        try {
            $newBalance = $this->calculateBalanceForAccount($account);

            if ($newBalance !== null) {
                return $this->accountRepository->updateBalance($account, $newBalance);
            }

            // No transactions and no opening balance - keep existing balance
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to recalculate account balance', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Calculate the current balance for an account without persisting.
     *
     * @return float|null The calculated balance, or null if no data available
     */
    public function calculateBalanceForAccount(Account $account): ?float
    {
        // Get the latest transaction by booked_date with a valid balance_after_transaction
        $latestTransaction = Transaction::where('account_id', $account->id)
            ->whereNotNull('balance_after_transaction')
            ->orderBy('booked_date', 'desc')
            ->orderBy('id', 'desc') // Secondary sort for same-day transactions
            ->first();

        if ($latestTransaction !== null) {
            return (float) $latestTransaction->balance_after_transaction;
        }

        // No valid balance_after_transaction found
        // Check for opening_balance (if field exists)
        if (isset($account->opening_balance) && $account->opening_balance !== null) {
            // Calculate from opening_balance + sum of all transactions
            $transactionSum = Transaction::where('account_id', $account->id)
                ->sum('amount');

            return (float) $account->opening_balance + (float) $transactionSum;
        }

        // Fallback: sum all transactions from current balance
        // This maintains the existing balance if no other data is available
        return null;
    }

    /**
     * Refresh account balance from GoCardless API.
     *
     * @throws \Exception If account is not GoCardless synced or API call fails
     */
    public function refreshAccountBalanceFromApi(Account $account): bool
    {
        if (! $account->is_gocardless_synced || ! $account->gocardless_account_id) {
            throw new \Exception('Account is not synced with GoCardless');
        }

        if (! $this->bankDataClient) {
            throw new \Exception('GoCardless client not available');
        }

        try {
            // Get balances from GoCardless
            // The BankDataClientInterface implementation handles token management internally
            $balances = $this->bankDataClient->getBalances($account->gocardless_account_id);
            $currentBalance = $this->parseClosingBookedBalance($balances);

            if ($currentBalance !== null) {
                return $this->accountRepository->updateBalance($account, $currentBalance);
            }

            Log::warning('No closingBooked balance found in GoCardless response', [
                'account_id' => $account->id,
                'gocardless_account_id' => $account->gocardless_account_id,
            ]);

            return false;
        } catch (\Exception $e) {
            Log::error('Failed to refresh balance from GoCardless API', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Parse the closingBooked balance from GoCardless balances response.
     *
     * @param  array<string, mixed>  $balances
     */
    private function parseClosingBookedBalance(array $balances): ?float
    {
        $balancesList = $balances['balances'] ?? [];

        if (! is_array($balancesList)) {
            return null;
        }

        foreach ($balancesList as $balance) {
            if (! is_array($balance)) {
                continue;
            }

            $balanceType = $balance['balanceType'] ?? null;
            if ($balanceType === 'closingBooked') {
                $balanceAmount = $balance['balanceAmount'] ?? [];
                $amount = is_array($balanceAmount) ? ($balanceAmount['amount'] ?? 0) : 0;

                // Ensure $amount is numeric before casting
                if (is_numeric($amount)) {
                    return (float) $amount;
                }

                return null;
            }
        }

        return null;
    }

    /**
     * Recalculate balances for all accounts of a user.
     *
     * @param  bool  $useApi  Whether to use GoCardless API for synced accounts
     * @return array{success: int, failed: int, errors: array<string>}
     */
    public function recalculateAllForUser(int $userId, bool $useApi = false): array
    {
        $accounts = $this->accountRepository->findByUser($userId);
        $results = [
            'success' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach ($accounts as $account) {
            try {
                $success = false;

                if ($useApi && $account->is_gocardless_synced && $account->gocardless_account_id) {
                    try {
                        $success = $this->refreshAccountBalanceFromApi($account);
                    } catch (\Exception $e) {
                        // Fall back to transaction-based calculation
                        Log::warning('API refresh failed, falling back to transaction-based calculation', [
                            'account_id' => $account->id,
                            'error' => $e->getMessage(),
                        ]);
                        $success = $this->recalculateForAccount($account);
                    }
                } else {
                    $success = $this->recalculateForAccount($account);
                }

                if ($success) {
                    $results['success']++;
                } else {
                    $results['failed']++;
                    $results['errors'][] = "Account {$account->id}: Update returned false";
                }
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = "Account {$account->id}: {$e->getMessage()}";
            }
        }

        return $results;
    }
}
