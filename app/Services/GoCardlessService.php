<?php

namespace App\Services;

use App\Models\Account;
use App\Models\User;
use App\Repositories\AccountRepository;
use App\Services\GoCardlessBankData;
use App\Services\GocardlessMapper;
use App\Services\TokenManager;
use App\Services\TransactionSyncService;
use Illuminate\Support\Facades\Log;

class GoCardlessService
{
    private GoCardlessBankData $client;
    private TokenManager $tokenManager;

    public function __construct(
        private AccountRepository $accountRepository,
        private TransactionSyncService $transactionSyncService,
        private GocardlessMapper $mapper,
        private User $user
    ) {
        $this->tokenManager = new TokenManager($user);
        $this->initializeClient();
    }

    /**
     * Initialize the GoCardless client with user credentials.
     */
    private function initializeClient(): void
    {
        try {
            $accessToken = $this->tokenManager->getAccessToken();
            
            $this->client = new GoCardlessBankData(
                $this->user->gocardless_secret_id,
                $this->user->gocardless_secret_key,
                $accessToken,
                $this->user->gocardless_refresh_token,
                $this->user->gocardless_refresh_token_expires_at,
                $this->user->gocardless_access_token_expires_at,
                true // Enable caching
            );
        } catch (\Exception $e) {
            Log::error('Failed to initialize GoCardless client', [
                'user_id' => $this->user->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Sync transactions for a specific account.
     *
     * @param int $accountId
     * @return array
     * @throws \Exception
     */
    public function syncAccountTransactions(int $accountId): array
    {
        Log::info('Starting transaction sync', [
            'account_id' => $accountId,
            'user_id' => $this->user->id,
        ]);

        // Get the account
        $account = $this->accountRepository->findByIdForUser($accountId, $this->user->id);
        
        if (!$account) {
            throw new \Exception('Account not found');
        }

        if (!$account->is_gocardless_synced) {
            throw new \Exception('Account is not synced with GoCardless');
        }

        // Calculate date range
        $dateRange = $this->transactionSyncService->calculateDateRange($account);

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
        $stats = $this->transactionSyncService->syncTransactions($bookedTransactions, $account);

        // Update sync timestamp
        $this->accountRepository->updateSyncTimestamp($account);

        Log::info('Transaction sync completed', [
            'account_id' => $accountId,
            'stats' => $stats,
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
     * @return array
     */
    public function syncAllAccounts(): array
    {
        $accounts = $this->accountRepository->getGocardlessSyncedAccounts($this->user->id);
        $results = [];

        foreach ($accounts as $account) {
            try {
                $result = $this->syncAccountTransactions($account->id);
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
     *
     * @param string $countryCode
     * @return array
     */
    public function getInstitutions(string $countryCode): array
    {
        return $this->client->getInstitutions($countryCode);
    }

    /**
     * Create a requisition for bank account linking.
     *
     * @param string $institutionId
     * @param string $redirectUrl
     * @return array
     */
    public function createRequisition(string $institutionId, string $redirectUrl): array
    {
        return $this->client->createRequisition($institutionId, $redirectUrl);
    }

    /**
     * Get requisition details.
     *
     * @param string $requisitionId
     * @return array
     */
    public function getRequisition(string $requisitionId): array
    {
        return $this->client->getRequisitions($requisitionId);
    }

    /**
     * Import account from GoCardless.
     *
     * @param string $gocardlessAccountId
     * @return Account
     * @throws \Exception
     */
    public function importAccount(string $gocardlessAccountId): Account
    {
        // Check if account already exists
        if ($this->accountRepository->gocardlessAccountExists($gocardlessAccountId, $this->user->id)) {
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

        $mappedData['user_id'] = $this->user->id;
        $mappedData['name'] = $mappedData['name'] ?? 'Imported Account';

        return $this->accountRepository->create($mappedData);
    }
} 