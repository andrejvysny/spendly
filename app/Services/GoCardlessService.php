<?php

namespace App\Services;

use App\Models\Account;
use App\Models\User;
use App\Repositories\AccountRepository;
use Illuminate\Support\Facades\Log;

class GoCardlessService
{
    private GoCardlessBankData $client;

    private TokenManager $tokenManager;

    public function __construct(
        private AccountRepository $accountRepository,
        private TransactionSyncService $transactionSyncService,
        private GocardlessMapper $mapper
    ) {}

    /**
     * Initialize the GoCardless client with user credentials.
     */
    private function initializeClient(User $user): void
    {
        try {
            $this->tokenManager = new TokenManager($user);
            $accessToken = $this->tokenManager->getAccessToken();

            // Ensure datetime fields are properly converted
            $refreshTokenExpires = $user->gocardless_refresh_token_expires_at;
            $accessTokenExpires = $user->gocardless_access_token_expires_at;

            // Convert to DateTime if they are strings
            if (is_string($refreshTokenExpires)) {
                $refreshTokenExpires = new \DateTime($refreshTokenExpires);
            }
            if (is_string($accessTokenExpires)) {
                $accessTokenExpires = new \DateTime($accessTokenExpires);
            }

            $this->client = new GoCardlessBankData(
                $user->gocardless_secret_id,
                $user->gocardless_secret_key,
                $accessToken,
                $user->gocardless_refresh_token,
                $refreshTokenExpires,
                $accessTokenExpires,
                true // Enable caching
            );
        } catch (\Exception $e) {
            Log::error('Failed to initialize GoCardless client', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Sync transactions for a specific account.
     *
     * @param  bool  $updateExisting  Whether to update already imported transactions (default: true)
     * @param  bool  $forceMaxDateRange  Whether to force sync from max days ago instead of last sync date (default: false)
     *
     * @throws \Exception
     */
    public function syncAccountTransactions(int $accountId, User $user, bool $updateExisting = true, bool $forceMaxDateRange = false): array
    {
        Log::info('Starting transaction sync', [
            'account_id' => $accountId,
            'user_id' => $user->id,
            'update_existing' => $updateExisting,
            'force_max_date_range' => $forceMaxDateRange,
        ]);

        // Initialize client with user
        $this->initializeClient($user);

        // Get the account
        $account = $this->accountRepository->findByIdForUser($accountId, $user->id);

        if (! $account) {
            throw new \Exception('Account not found');
        }

        if (! $account->is_gocardless_synced) {
            throw new \Exception('Account is not synced with GoCardless');
        }

        // Calculate date range
        $dateRange = $this->transactionSyncService->calculateDateRange($account, 90, $forceMaxDateRange);

        // Get transactions from GoCardless
        $response = $this->client->getTransactions(
            $account->gocardless_account_id,
            $dateRange['date_from'],
            $dateRange['date_to']
        );

        $bookedTransactions = $response['transactions']['booked'] ?? [];
        $pendingTransactions = $response['transactions']['pending'] ?? [];

        Log::info('Retrieved transactions from GoCardless', [
            'account_id' => $accountId,
            'booked_count' => count($bookedTransactions),
            'pending_count' => count($pendingTransactions),
            'date_from' => $dateRange['date_from'],
            'date_to' => $dateRange['date_to'],
        ]);

        // Sync booked transactions
        $stats = $this->transactionSyncService->syncTransactions($bookedTransactions, $account, $updateExisting);

        // Update sync timestamp
        $this->accountRepository->updateSyncTimestamp($account);

        Log::info('Transaction sync completed', [
            'account_id' => $accountId,
            'stats' => $stats,
            'update_existing' => $updateExisting,
            'force_max_date_range' => $forceMaxDateRange,
        ]);

        return [
            'account_id' => $accountId,
            'stats' => $stats,
            'date_range' => $dateRange,
        ];
    }

    /**
     * Sync all GoCardless accounts for the user.
     *
     * @param  bool  $updateExisting  Whether to update already imported transactions (default: true)
     * @param  bool  $forceMaxDateRange  Whether to force sync from max days ago instead of last sync date (default: false)
     */
    public function syncAllAccounts(User $user, bool $updateExisting = true, bool $forceMaxDateRange = false): array
    {
        // Initialize client with user
        $this->initializeClient($user);

        $accounts = $this->accountRepository->getGocardlessSyncedAccounts($user->id);
        $results = [];

        foreach ($accounts as $account) {
            try {
                $result = $this->syncAccountTransactions($account->id, $user, $updateExisting, $forceMaxDateRange);
                $results[] = array_merge($result, ['status' => 'success']);
            } catch (\Exception $e) {
                Log::error('Failed to sync account', [
                    'account_id' => $account->id,
                    'error' => $e->getMessage(),
                ]);

                $results[] = [
                    'account_id' => $account->id,
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Get available institutions for a country.
     */
    public function getInstitutions(string $countryCode, User $user): array
    {
        $this->initializeClient($user);

        return $this->client->getInstitutions($countryCode);
    }

    /**
     * Create a requisition for bank account linking.
     */
    public function createRequisition(string $institutionId, string $redirectUrl, User $user): array
    {
        $this->initializeClient($user);

        return $this->client->createRequisition($institutionId, $redirectUrl);
    }

    /**
     * Get requisition details.
     */
    public function getRequisition(string $requisitionId, User $user): array
    {
        $this->initializeClient($user);

        return $this->client->getRequisitions($requisitionId);
    }

    /**
     * Import account from GoCardless.
     *
     * @throws \Exception
     */
    public function importAccount(string $gocardlessAccountId, User $user): Account
    {
        $this->initializeClient($user);

        // Check if account already exists
        if ($this->accountRepository->gocardlessAccountExists($gocardlessAccountId, $user->id)) {
            throw new \Exception('Account already exists');
        }

        // Get account details from GoCardless
        $accountDetails = $this->client->getAccountDetails($gocardlessAccountId);
        $accountData = $accountDetails['account'] ?? [];

        // Get account balances
        $balances = $this->client->getBalances($gocardlessAccountId);
        $currentBalance = 0;

        foreach ($balances['balances'] ?? [] as $balance) {
            if ($balance['balanceType'] === 'closingBooked') {
                $currentBalance = $balance['balanceAmount']['amount'] ?? 0;
                break;
            }
        }

        // Map and create account
        $mappedData = $this->mapper->mapAccountData(array_merge($accountData, [
            'id' => $gocardlessAccountId,
            'balance' => $currentBalance,
        ]));

        $mappedData['user_id'] = $user->id;
        $mappedData['name'] = $mappedData['name'] ?? 'Imported Account';

        return $this->accountRepository->create($mappedData);
    }
}
