<?php

declare(strict_types=1);

namespace App\Services\GoCardless;

use App\Exceptions\GoCardlessRateLimitException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class GoCardlessBankDataClient implements BankDataClientInterface
{
    private string $baseUrl = 'https://bankaccountdata.gocardless.com/api/v2';

    private ?TokenManager $tokenManager = null;

    public function __construct(
        private string $secretId,
        private string $secretKey,
        private ?string $accessToken = null,
        private ?string $refreshToken = null,
        private ?\DateTime $refreshTokenExpires = null,
        private ?\DateTime $accessTokenExpires = null,
        private bool $useCache = true,
        private int $cacheDuration = 3600,
        ?TokenManager $tokenManager = null
    ) {
        $this->tokenManager = $tokenManager;
        if (! $this->tokenManager) {
            $this->getAccessToken();
        }
    }

    /**
     * Create an authenticated HTTP request with standard timeouts.
     */
    private function request(): PendingRequest
    {
        return Http::withToken($this->getAccessToken())
            ->timeout(30)
            ->connectTimeout(10);
    }

    /**
     * Returns the current access and refresh tokens.
     *
     * @return array Associative array with 'access' and 'refresh' token values.
     */
    public function getSecretTokens(): array
    {
        return [
            'access' => $this->accessToken,
            'refresh' => $this->refreshToken,
        ];
    }

    /**
     * Retrieves a valid access token for authenticating API requests.
     *
     * Returns the current access token if it is valid and not expired. If expired, attempts to refresh it using the refresh token if available and valid; otherwise, requests a new token using the secret credentials. Throws an exception if unable to obtain a valid token.
     *
     * @return string The valid access token.
     */
    private function getAccessToken(): string
    {
        // Delegate to TokenManager when available (persists tokens to DB)
        if ($this->tokenManager) {
            return $this->tokenManager->getAccessToken();
        }

        // Fallback: in-memory token management (used by mock or when no TokenManager)
        if ($this->accessToken && $this->accessTokenExpires > new \DateTime) {
            return $this->accessToken;
        }

        if ($this->refreshToken && $this->refreshTokenExpires > new \DateTime) {
            $response = Http::timeout(30)->connectTimeout(10)
                ->post("{$this->baseUrl}/token/refresh/", [
                    'refresh' => $this->refreshToken,
                ]);

            if ($response->successful()) {
                return $this->processTokenResponse($response);
            }
        }

        $response = Http::timeout(30)->connectTimeout(10)
            ->post("{$this->baseUrl}/token/new/", [
                'secret_id' => $this->secretId,
                'secret_key' => $this->secretKey,
            ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to get access token: '.$response->body());
        }

        return $this->processTokenResponse($response);
    }

    /**
     * Parses the token response, updates access and refresh tokens, and sets their expiration times.
     *
     * @param  \Illuminate\Http\Client\Response  $response  The HTTP response containing token data.
     * @return string The new access token.
     *
     * @throws \InvalidArgumentException When token response has invalid types or missing required fields.
     */
    private function processTokenResponse(\Illuminate\Http\Client\Response $response): string
    {
        $data = $response->json();

        // Check for required keys
        if (! isset($data['access'], $data['refresh'], $data['access_expires'], $data['refresh_expires'])) {
            throw new \InvalidArgumentException('Invalid token response: missing required fields');
        }

        // Validate that access and refresh tokens are strings
        if (! is_string($data['access'])) {
            throw new \InvalidArgumentException('Invalid token response: access token must be a string');
        }

        if (! is_string($data['refresh'])) {
            throw new \InvalidArgumentException('Invalid token response: refresh token must be a string');
        }

        // Validate that expiry values are numeric (integers or floats)
        if (! is_numeric($data['access_expires'])) {
            throw new \InvalidArgumentException('Invalid token response: access_expires must be numeric');
        }

        if (! is_numeric($data['refresh_expires'])) {
            throw new \InvalidArgumentException('Invalid token response: refresh_expires must be numeric');
        }

        // Validate that expiry values are positive
        if ((int) $data['access_expires'] <= 0) {
            throw new \InvalidArgumentException('Invalid token response: access_expires must be positive');
        }

        if ((int) $data['refresh_expires'] <= 0) {
            throw new \InvalidArgumentException('Invalid token response: refresh_expires must be positive');
        }

        $this->accessToken = $data['access'];
        $this->refreshToken = $data['refresh'];

        // Calculate expiration times
        $this->accessTokenExpires = (new \DateTime)->add(new \DateInterval('PT'.(int) $data['access_expires'].'S'));
        $this->refreshTokenExpires = (new \DateTime)->add(new \DateInterval('PT'.(int) $data['refresh_expires'].'S'));

        return $this->accessToken;
    }

    /**
     * Creates a new end user agreement for a specified financial institution.
     *
     * Initiates an agreement granting access to balances, details, and transactions for the given institution and user data, with access valid for 90 days.
     *
     * @param  string  $institutionId  The identifier of the financial institution.
     * @param  array  $userData  User-specific data required by the institution.
     * @return array The API response containing the created agreement details.
     *
     * @throws \Exception If the agreement creation fails.
     */
    /**
     * Check API response for errors, throwing typed exceptions for rate limits and auth failures.
     *
     * @throws GoCardlessRateLimitException On 429 responses
     * @throws \Exception On other non-successful responses
     */
    private function handleResponse(\Illuminate\Http\Client\Response $response, string $context): void
    {
        if ($response->successful()) {
            return;
        }

        if ($response->status() === 429) {
            $resetHeader = $response->header('X-Ratelimit-Account-Success-Reset');
            $retryAfter = $resetHeader ? (int) $resetHeader : 60;

            throw new GoCardlessRateLimitException($retryAfter, "Rate limited during {$context}. Retry after {$retryAfter}s.");
        }

        if ($response->status() === 401) {
            throw new \RuntimeException("GoCardless authentication failed during {$context}: ".$response->body());
        }

        throw new \Exception("Failed to {$context}: ".$response->body());
    }

    public function createEndUserAgreement(string $institutionId, array $userData): array
    {
        $response = $this->request()
            ->post("{$this->baseUrl}/agreements/enduser/", [
                'institution_id' => $institutionId,
                'max_historical_days' => 90,
                'access_valid_for_days' => 90,
                'access_scope' => ['balances', 'details', 'transactions'],
            ]);

        $this->handleResponse($response, 'create end user agreement');

        return $response->json();
    }

    /**
     * Retrieves all bank accounts associated with a given requisition ID.
     *
     * Uses cached data if available and caching is enabled. Throws an exception if the API request fails.
     *
     * @param  string  $requisitionId  The requisition identifier.
     * @return array List of account IDs linked to the requisition, or an empty array if none are found.
     */
    public function getAccounts(string $requisitionId): array
    {
        if ($this->useCache) {
            $cached = Cache::get("gocardless_accounts_{$requisitionId}");
            if ($cached !== null) {
                return $cached;
            }
        }
        $response = $this->request()
            ->get("{$this->baseUrl}/requisitions/{$requisitionId}/");

        $this->handleResponse($response, 'get accounts');

        $accounts = $response->json()['accounts'] ?? [];

        // Only cache non-empty results — empty means auth may not be complete yet
        if ($this->useCache && $accounts !== []) {
            Cache::put("gocardless_accounts_{$requisitionId}", $accounts, $this->cacheDuration);
        }

        return $accounts;
    }

    /**
     * Retrieves account metadata (institution_id, status, etc.) from the GoCardless account endpoint.
     */
    public function getAccountMetadata(string $accountId): array
    {
        $key = "gocardless_account_metadata_{$accountId}";
        if ($this->useCache) {
            $cached = Cache::get($key);
            if ($cached !== null) {
                return $cached;
            }
        }

        $response = $this->request()
            ->get("{$this->baseUrl}/accounts/{$accountId}/");

        $this->handleResponse($response, 'get account metadata');

        if ($this->useCache) {
            Cache::put($key, $response->json(), $this->cacheDuration);
        }

        return $response->json();
    }

    /**
     * Retrieves detailed information for a specific bank account by account ID.
     *
     * @param  string  $accountId  The unique identifier of the account.
     * @return array The account details as an associative array.
     *
     * @throws \Exception If the API request fails.
     */
    public function getAccountDetails(string $accountId): array
    {
        $key = "gocardless_account_details_{$accountId}";
        if ($this->useCache) {
            $cached = Cache::get($key);
            if ($cached !== null) {
                return $cached;
            }
        }

        $response = $this->request()
            ->get("{$this->baseUrl}/accounts/{$accountId}/details/");

        $this->handleResponse($response, 'get account details');

        if ($this->useCache) {
            Cache::put($key, $response->json(), $this->cacheDuration);
        }

        return $response->json();
    }

    /**
     * Retrieves transactions for a specified account, optionally filtered by date range.
     *
     * @param  string  $accountId  The unique identifier of the account.
     * @param  string|null  $dateFrom  Optional start date (YYYY-MM-DD) to filter transactions.
     * @param  string|null  $dateTo  Optional end date (YYYY-MM-DD) to filter transactions.
     * @return array Array of transactions for the account.
     *
     * @throws \Exception If the API request fails.
     */
    public function getTransactions(string $accountId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        // Check cache first
        $cacheKey = "gocardless_transactions_{$accountId}_{$dateFrom}_{$dateTo}";
        if ($this->useCache) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        $params = [];
        if ($dateFrom) {
            $params['date_from'] = $dateFrom;
        }
        if ($dateTo) {
            $params['date_to'] = $dateTo;
        }

        $allTransactions = [];
        $nextPage = null;
        $retryCount = 0;
        $maxRetries = 3;

        do {
            try {
                $url = $nextPage ?? "{$this->baseUrl}/accounts/{$accountId}/transactions/";
                $response = $this->request()
                    ->get($url, $params);

                $this->handleResponse($response, 'get transactions');

                $data = $response->json();
                $transactions = $data['transactions'] ?? [];

                // Merge transactions
                if (isset($transactions['booked'])) {
                    $allTransactions['transactions']['booked'] = array_merge(
                        $allTransactions['transactions']['booked'] ?? [],
                        $transactions['booked']
                    );
                }
                // Get next page URL if available
                $nextPage = $data['next'] ?? null;

                // Reset retry count on successful request
                $retryCount = 0;

            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                $retryCount++;
                if ($retryCount >= $maxRetries) {
                    throw new \Exception('Failed to get transactions after '.$maxRetries.' retries: '.$e->getMessage());
                }
                // Wait before retrying (exponential backoff)
                sleep(pow(2, $retryCount));
            }
        } while ($nextPage);

        if ($this->useCache) {
            Cache::put($cacheKey, $allTransactions, $this->cacheDuration);
        }

        return $allTransactions;
    }

    /**
     * Retrieves the balances for a specified account.
     *
     * @param  string  $accountId  The unique identifier of the account.
     * @return array The balances data returned by the GoCardless API.
     *
     * @throws \Exception If the API request fails.
     */
    public function getBalances(string $accountId): array
    {
        $key = "gocardless_balances_{$accountId}";
        if ($this->useCache) {
            $cached = Cache::get($key);
            if ($cached !== null) {
                return $cached;
            }
        }

        $response = $this->request()
            ->get("{$this->baseUrl}/accounts/{$accountId}/balances/");

        $this->handleResponse($response, 'get balances');

        if ($this->useCache) {
            Cache::put($key, $response->json(), 300);
        }

        return $response->json();
    }

    /**
     * Creates a new requisition for a specified institution and redirect URL.
     *
     * Initiates a requisition with the given institution ID and redirect URL, setting the user language to English.
     * Throws an exception if the API request fails.
     *
     * @param  string  $institutionId  The identifier of the financial institution.
     * @param  string  $redirectUrl  The URL to redirect the user after authorization.
     * @param  string|null  $agreementId  (Unused) Optional agreement ID for the requisition.
     * @return array The API response as an associative array.
     */
    public function createRequisition(string $institutionId, string $redirectUrl, ?string $agreementId = null): array
    {
        $payload = [
            'institution_id' => $institutionId,
            'redirect' => $redirectUrl,
            'user_language' => 'EN',
        ];

        if ($agreementId) {
            $payload['agreement'] = $agreementId;
        }

        $response = $this->request()
            ->post("{$this->baseUrl}/requisitions/", $payload);

        $this->handleResponse($response, 'create requisition');

        Cache::forget('gocardless_requisitions_all');

        return $response->json();
    }

    /**
     * Retrieves one or all requisitions from the GoCardless API.
     *
     * If a requisition ID is provided, returns details for that requisition; otherwise, returns all requisitions. Uses cache if enabled.
     *
     * @param  string|null  $requisitionId  Optional requisition ID to fetch a specific requisition.
     * @return array The requisition data as an associative array.
     *
     * @throws \Exception If the API request fails.
     */
    public function getRequisitions(?string $requisitionId = null): array
    {
        $key = $requisitionId ? "gocardless_requisitions_{$requisitionId}" : 'gocardless_requisitions_all';
        if ($this->useCache) {
            $cached = Cache::get($key);
            if ($cached !== null) {
                return $cached;
            }
        }

        $response = $this->request()
            ->get($requisitionId ? "{$this->baseUrl}/requisitions/{$requisitionId}/" : "{$this->baseUrl}/requisitions/");

        $this->handleResponse($response, 'get requisitions');

        $data = $response->json();

        // Only cache linked requisitions — transient statuses (CR, GC, UA) should not be cached
        if ($requisitionId !== null) {
            $isTransient = in_array($data['status'] ?? null, ['CR', 'GC', 'UA'], true);
        } else {
            $isTransient = collect($data['results'] ?? [])
                ->contains(fn (array $r) => in_array($r['status'] ?? null, ['CR', 'GC', 'UA'], true));
        }
        if ($this->useCache && ! $isTransient) {
            Cache::put($key, $data, $this->cacheDuration);
        }

        return $data;
    }

    /**
     * Deletes a requisition by its ID from the GoCardless API.
     *
     * Removes the specified requisition and clears related cached data.
     *
     * @param  string  $requisitionId  The ID of the requisition to delete.
     * @return bool True if the requisition was successfully deleted.
     *
     * @throws \Exception If the API request fails.
     */
    public function deleteRequisition(string $requisitionId): bool
    {
        $response = $this->request()
            ->delete("{$this->baseUrl}/requisitions/{$requisitionId}/");

        $this->handleResponse($response, 'delete requisition');
        // Clear the cached requisitions
        Cache::forget("gocardless_requisitions_{$requisitionId}");
        Cache::forget('gocardless_requisitions_all');

        return true;
    }

    /**
     * Retrieves a list of financial institutions available for a specified country code.
     *
     * Uses caching if enabled to reduce redundant API calls. Throws an exception if the API request fails.
     *
     * @param  string  $countryCode  ISO country code to filter institutions.
     * @return array List of institutions for the given country.
     */
    public function getInstitutions(string $countryCode): array
    {
        $key = "gocardless_institutions_{$countryCode}";
        if ($this->useCache) {
            $cached = Cache::get($key);
            if ($cached !== null) {
                return $cached;
            }
        }
        $response = $this->request()
            ->get("{$this->baseUrl}/institutions?country={$countryCode}");

        $this->handleResponse($response, 'get institutions');
        if ($this->useCache) {
            Cache::put($key, $response->json(), $this->cacheDuration);
        }

        return $response->json();
    }
}
