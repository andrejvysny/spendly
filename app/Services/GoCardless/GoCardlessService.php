<?php

declare(strict_types=1);

namespace App\Services\GoCardless;

use App\Contracts\Repositories\AccountRepositoryInterface;
use App\Enums\GoCardlessRequisitionStatus;
use App\Exceptions\AccountAlreadyExistsException;
use App\Exceptions\GoCardlessConsentExpiredException;
use App\Exceptions\GoCardlessRateLimitException;
use App\Models\Account;
use App\Models\GoCardlessRequisition;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class GoCardlessService
{
    private BankDataClientInterface $client;

    private ?int $currentUserId = null;

    public function __construct(
        private AccountRepositoryInterface $accountRepository,
        private TransactionSyncService $transactionSyncService,
        private GocardlessMapper $mapper,
        private ClientFactory\GoCardlessClientFactoryInterface $clientFactory
    ) {}

    /**
     * Check if the GoCardless client is initialized.
     */
    private function isClientInitialized(): bool
    {
        return isset($this->client) && $this->client instanceof BankDataClientInterface;
    }

    /**
     * Initialize the GoCardless client with user credentials.
     * Credential validation is handled by ProductionClientFactory::make().
     */
    private function initializeClient(User $user): void
    {
        try {
            $this->client = $this->clientFactory->make($user);
        } catch (\InvalidArgumentException $e) {
            // Re-throw validation errors as-is
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to initialize GoCardless client', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Failed to initialize GoCardless client: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Get the GoCardless client, initializing it if necessary.
     *
     * @throws \RuntimeException When client cannot be initialized
     */
    private function getClient(User $user): BankDataClientInterface
    {
        if (! $this->isClientInitialized() || $this->currentUserId !== $user->id) {
            $this->initializeClient($user);
            $this->currentUserId = $user->id;
        }

        return $this->client;
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
        $this->getClient($user);

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
        try {
            $response = $this->client->getTransactions(
                $account->gocardless_account_id,
                $dateRange['date_from'],
                $dateRange['date_to']
            );
        } catch (GoCardlessConsentExpiredException $e) {
            // Retrying can never fix this — only the user re-authorizing can. Record that on the
            // account (and its requisition) so the UI can ask for it, then let the caller decide
            // how to report the failure.
            $this->flagConsentExpired($account);

            throw $e;
        }

        // A partial fetch still syncs what came back; the gap is surfaced so it is not silent.
        if (($response['partial'] ?? false) === true) {
            Log::warning('GoCardless partial transaction fetch', [
                'account_id' => $accountId,
                'reason' => $response['partial_reason'] ?? null,
                'pages_fetched' => $response['pages_fetched'] ?? null,
            ]);
        }

        $bookedTransactions = $response['transactions']['booked'] ?? [];

        Log::info('Retrieved transactions from GoCardless', [
            'account_id' => $accountId,
            'booked_count' => count($bookedTransactions),
            'date_from' => $dateRange['date_from'],
            'date_to' => $dateRange['date_to'],
        ]);

        // Sync booked transactions
        $stats = $this->transactionSyncService->syncTransactions($bookedTransactions, $account, $updateExisting);

        // Advance the sync watermark only as far as what was actually and completely
        // synced — never past failed rows or a page GoCardless didn't return.
        $watermark = $this->resolveSyncWatermark($dateRange, $stats, (bool) ($response['partial'] ?? false));
        if ($watermark !== null) {
            $this->accountRepository->updateSyncTimestamp($account, $watermark);
        }

        // Refresh account balance from GoCardless API
        $balanceUpdated = $this->refreshAccountBalance($account);

        Log::info('Transaction sync completed', [
            'account_id' => $accountId,
            'stats' => $stats,
            'update_existing' => $updateExisting,
            'force_max_date_range' => $forceMaxDateRange,
            'balance_updated' => $balanceUpdated,
        ]);

        return [
            'account_id' => $accountId,
            'stats' => $stats,
            'date_range' => $dateRange,
            'balance_updated' => $balanceUpdated,
        ];
    }

    /**
     * Decide how far the sync watermark (gocardless_last_synced_at) may advance.
     *
     * TransactionSyncService::calculateDateRange() windows the next fetch from this
     * watermark (minus one day, capped at 90 days), so advancing it past data that
     * failed to persist — or past a page GoCardless never returned — would mean that
     * data is never retried by the next scheduled sync. The rules, in order:
     *
     * - Partial fetch: which pages are missing is unknown, so the watermark cannot
     *   move at all. The next run's 90-day-capped window will retry from wherever it
     *   last successfully advanced to.
     * - Full fetch with failures whose earliest date is known: pull the watermark back
     *   to the day before that failure, clamped to [now()-90d, date_to], so the next
     *   run re-fetches from before the earliest known gap.
     * - Full fetch with failures but no derivable date (e.g. mapping failed before a
     *   date could be parsed): do not advance, to stay safe.
     * - Clean fetch (no errors): advance to date_to, same as before this change.
     *
     * @param  array{date_from:string,date_to:string}  $dateRange
     * @param  array<string,mixed>  $stats
     */
    private function resolveSyncWatermark(array $dateRange, array $stats, bool $partial): ?Carbon
    {
        if ($partial) {
            return null;
        }

        $errors = is_numeric($stats['errors'] ?? null) ? (int) $stats['errors'] : 0;

        if ($errors === 0) {
            return Carbon::parse($dateRange['date_to']);
        }

        $earliest = $stats['earliest_unsynced_date'] ?? null;
        if (! is_string($earliest) || $earliest === '') {
            return null;
        }

        $watermark = Carbon::parse($earliest)->subDay();
        $floor = now()->subDays(90);
        $ceiling = Carbon::parse($dateRange['date_to']);

        if ($watermark->lt($floor)) {
            return $floor;
        }
        if ($watermark->gt($ceiling)) {
            return $ceiling;
        }

        return $watermark;
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
        $this->getClient($user);

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
     * Refresh account balance from GoCardless API.
     *
     * @param  Account  $account  The account to refresh balance for
     * @return bool True if balance was updated, false otherwise
     *
     * @throws GoCardlessConsentExpiredException When the bank has withdrawn access — the only
     *                                           failure here a caller can act on.
     */
    public function refreshAccountBalance(Account $account): bool
    {
        if (! $account->is_gocardless_synced || ! $account->gocardless_account_id) {
            Log::warning('Cannot refresh balance for non-GoCardless account', [
                'account_id' => $account->id,
            ]);

            return false;
        }

        try {
            $user = $account->user;
            if (! $user instanceof User) {
                $user = $account->user()->first();
            }
            if (! $user instanceof User) {
                Log::warning('Cannot refresh balance without account user', [
                    'account_id' => $account->id,
                ]);

                return false;
            }
            $this->getClient($user);

            $balances = $this->client->getBalances($account->gocardless_account_id);
            $currentBalance = BalanceResolver::resolve($balances['balances'] ?? []);

            if ($currentBalance !== null) {
                $this->accountRepository->updateBalance($account, $currentBalance);
                Log::info('Account balance updated from GoCardless', [
                    'account_id' => $account->id,
                    'balance' => $currentBalance,
                ]);

                return true;
            }

            Log::warning('No closingBooked balance found in GoCardless response', [
                'account_id' => $account->id,
                'balances' => $balances,
            ]);

            return false;
        } catch (GoCardlessConsentExpiredException $e) {
            // Unlike every other failure here, this is not "balance unavailable this run" — the
            // connection is dead until reconnected, so it must not be swallowed into a false.
            $this->flagConsentExpired($account);

            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to refresh account balance from GoCardless', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Record that an account (and the requisition that authorized it) needs the user to
     * re-authorize with their bank.
     *
     * Best-effort and never fatal: it runs while an exception is already propagating, so a
     * write failure here must not replace the original consent error.
     */
    private function flagConsentExpired(Account $account): void
    {
        try {
            $this->accountRepository->markNeedsReconnect($account);

            $requisitionRowId = $account->gocardless_requisition_id;
            if ($requisitionRowId === null) {
                return;
            }

            // Scoped by owner as well as key: the FK is set from callback data, and a mismatch
            // must fail closed rather than expire another tenant's connection.
            $requisition = GoCardlessRequisition::query()
                ->whereKey($requisitionRowId)
                ->where('user_id', $account->user_id)
                ->first();

            if ($requisition instanceof GoCardlessRequisition) {
                $requisition->update(['status' => GoCardlessRequisitionStatus::EXPIRED]);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to flag GoCardless consent expiry', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get available institutions for a country.
     *
     * @throws \Exception
     */
    public function getInstitutions(string $countryCode, User $user): array
    {
        $this->getClient($user);

        return $this->client->getInstitutions($countryCode);
    }

    /**
     * Create a requisition for bank account linking.
     *
     * Creates an End User Agreement first (90-day access) then links it to the requisition.
     * Falls back to requisition without agreement if EUA creation fails.
     *
     * @param  string|null  $reference  Correlation id GoCardless echoes back on the redirect as `ref=`.
     * @return array{requisition: array<string, mixed>, agreement: array<string, mixed>|null}
     */
    public function createRequisition(string $institutionId, string $redirectUrl, User $user, ?string $reference = null): array
    {
        $this->getClient($user);

        $agreementId = null;
        $agreement = null;
        try {
            $created = $this->client->createEndUserAgreement($institutionId, []);
            $agreementId = $created['id'] ?? null;
            $agreement = $agreementId !== null ? $created : null;
            Log::info('Created End User Agreement', [
                'institution_id' => $institutionId,
                'agreement_id' => $agreementId,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to create End User Agreement, proceeding without', [
                'institution_id' => $institutionId,
                'error' => $e->getMessage(),
            ]);
        }

        $agreementIdString = is_string($agreementId) ? $agreementId : null;

        return [
            'requisition' => $this->client->createRequisition($institutionId, $redirectUrl, $agreementIdString, $reference),
            'agreement' => is_array($agreement) ? $agreement : null,
        ];
    }

    /**
     * Fetch an existing End User Agreement (used to resolve the exact access window
     * after the bank redirect).
     *
     * @return array<string, mixed>
     *
     * @throws \Exception
     */
    public function getEndUserAgreement(string $agreementId, User $user): array
    {
        $this->getClient($user);

        return $this->client->getEndUserAgreement($agreementId);
    }

    /**
     * Get requisition details (single requisition by ID).
     *
     * @throws \Exception
     */
    public function getRequisition(string $requisitionId, User $user): array
    {
        $this->getClient($user);

        return $this->client->getRequisitions($requisitionId);
    }

    /**
     * Get all requisitions for the user (paginated list).
     *
     * @return array{count: int, next: string|null, previous: string|null, results: array<int, array<string, mixed>>}
     */
    public function getRequisitionsList(User $user): array
    {
        $this->getClient($user);

        return $this->client->getRequisitions(null);
    }

    /**
     * Enrich account IDs with details from local DB or GoCardless API.
     *
     * @param  array<int, string>  $accountIds
     * @return array<int, array{id: string, local_id: int|null, name: string, iban: string|null, currency: string|null, owner_name: string|null, status: string, last_synced_at: string|null}>
     */
    public function getEnrichedAccountsForRequisition(array $accountIds, User $user): array
    {
        $enriched = [];
        $this->getClient($user);

        foreach ($accountIds as $accountId) {
            $local = $this->accountRepository->findByGocardlessId($accountId, (int) $user->id);
            if ($local !== null) {
                $enriched[] = [
                    'id' => $accountId,
                    'local_id' => $local->id,
                    'name' => $local->name ?? 'Account',
                    'iban' => $local->iban,
                    'currency' => $local->currency,
                    'owner_name' => null,
                    'status' => 'Imported',
                    'last_synced_at' => $local->gocardless_last_synced_at?->toIso8601String(),
                    'enrichment_status' => 'complete',
                ];

                continue;
            }

            try {
                $details = $this->client->getAccountDetails($accountId);
                $account = $details['account'] ?? [];
                $enriched[] = [
                    'id' => $accountId,
                    'local_id' => null,
                    'name' => $account['name'] ?? $account['product'] ?? 'Account',
                    'iban' => $account['iban'] ?? null,
                    'currency' => $account['currency'] ?? null,
                    'owner_name' => $account['ownerName'] ?? null,
                    'status' => 'Ready to import',
                    'last_synced_at' => null,
                    'enrichment_status' => 'complete',
                ];
            } catch (GoCardlessRateLimitException $e) {
                Log::warning('Rate limited during account enrichment, returning partial results', [
                    'account_id' => $accountId,
                    'retry_after' => $e->retryAfterSeconds,
                    'enriched_so_far' => count($enriched),
                    'remaining' => count($accountIds) - count($enriched),
                ]);
                // Add remaining accounts as stubs and stop
                $remaining = array_slice($accountIds, count($enriched));
                foreach ($remaining as $remainingId) {
                    $enriched[] = [
                        'id' => $remainingId,
                        'local_id' => null,
                        'name' => 'Account',
                        'iban' => null,
                        'currency' => null,
                        'owner_name' => null,
                        'status' => 'Ready to import',
                        'last_synced_at' => null,
                        'enrichment_status' => 'stub',
                    ];
                }
                break;
            } catch (\Throwable $e) {
                Log::warning('Failed to fetch account details for enrichment', [
                    'account_id' => $accountId,
                    'error' => $e->getMessage(),
                ]);
                $enriched[] = [
                    'id' => $accountId,
                    'local_id' => null,
                    'name' => 'Account',
                    'iban' => null,
                    'currency' => null,
                    'owner_name' => null,
                    'status' => 'Ready to import',
                    'last_synced_at' => null,
                    'enrichment_status' => 'stub',
                ];
            }
        }

        return $enriched;
    }

    /**
     * Get account IDs linked to a requisition.
     *
     * @return array<int, string>
     */
    public function getAccounts(string $requisitionId, User $user): array
    {
        $this->getClient($user);

        return $this->client->getAccounts($requisitionId);
    }

    /**
     * Delete a requisition by ID.
     */
    public function deleteRequisition(string $requisitionId, User $user): bool
    {
        $this->getClient($user);

        return $this->client->deleteRequisition($requisitionId);
    }

    /**
     * Import account from GoCardless.
     *
     * @throws AccountAlreadyExistsException When the account is already linked for this user
     * @throws \Exception
     */
    public function importAccount(string $goCardlessAccountId, User $user): Account
    {
        $this->getClient($user);

        // Check if account already exists
        if ($this->accountRepository->gocardlessAccountExists($goCardlessAccountId, $user->id)) {
            throw new AccountAlreadyExistsException;
        }

        // Get account metadata (institution_id) and details from GoCardless
        $accountMetadata = [];
        try {
            $accountMetadata = $this->client->getAccountMetadata($goCardlessAccountId);
        } catch (\Throwable $e) {
            Log::warning('Failed to fetch account metadata', [
                'account_id' => $goCardlessAccountId,
                'error' => $e->getMessage(),
            ]);
        }
        $accountDetails = $this->client->getAccountDetails($goCardlessAccountId);
        $accountData = $accountDetails['account'] ?? [];

        // Get account balances (prefer closingBooked, fall back to interimAvailable/expected)
        $balances = $this->client->getBalances($goCardlessAccountId);
        $currentBalance = BalanceResolver::resolve($balances['balances'] ?? []) ?? 0;

        // Map and create account — merge institution_id from metadata
        $mappedData = $this->mapper->mapAccountData(array_merge($accountData, [
            'id' => $goCardlessAccountId,
            'institution_id' => $accountMetadata['institution_id'] ?? null,
            'balance' => $currentBalance,
        ]));

        $mappedData['name'] = $mappedData['name'] ?? 'Imported Account';

        return $this->accountRepository->createForUser($user->id, $mappedData);
    }

    /**
     * @return array<string, mixed>
     */
    public function getAccountDetailsRaw(string $goCardlessAccountId, User $user): array
    {
        return $this->getClient($user)->getAccountDetails($goCardlessAccountId);
    }

    /**
     * @return array<string, mixed>
     */
    public function getAccountBalancesRaw(string $goCardlessAccountId, User $user): array
    {
        return $this->getClient($user)->getBalances($goCardlessAccountId);
    }
}
