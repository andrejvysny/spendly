<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class GoCardlessBankData
{
    private string $baseUrl = 'https://bankaccountdata.gocardless.com/api/v2';

    /**
     * Initializes the GoCardlessBankData service with authentication credentials, tokens, expiration times, and caching options.
     *
     * Ensures a valid access token is available upon instantiation.
     *
     * @param  string  $secretId  GoCardless API secret ID.
     * @param  string  $secretKey  GoCardless API secret key.
     * @param  string|null  $accessToken  Optional initial access token.
     * @param  string|null  $refreshToken  Optional initial refresh token.
     * @param  \DateTime|null  $refreshTokenExpires  Optional refresh token expiration time.
     * @param  \DateTime|null  $accessTokenExpires  Optional access token expiration time.
     * @param  bool  $useCache  Whether to enable caching for API responses.
     * @param  int  $cacheDuration  Cache duration in seconds.
     */
    public function __construct(
        private string $secretId,
        private string $secretKey,
        private ?string $accessToken = null,
        private ?string $refreshToken = null,
        private ?\DateTime $refreshTokenExpires = null,
        private ?\DateTime $accessTokenExpires = null,
        private bool $useCache = true,
        private int $cacheDuration = 3600
    ) {
        // Initialize access token and expiration times
        $this->getAccessToken();
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
        // Check if we already have a valid access token
        if ($this->accessToken && $this->accessTokenExpires > new \DateTime) {
            return $this->accessToken;
        }

        // If we have a refresh token and it's not expired, use it to get a new access token
        if ($this->refreshToken && $this->refreshTokenExpires > new \DateTime) {
            $response = Http::post("{$this->baseUrl}/token/refresh/", [
                'refresh_token' => $this->refreshToken,
            ]);

            if ($response->successful()) {
                return $this->processTokenResponse($response);
            }
            // If refresh token fails, continue to get a new token with credentials
        }

        // Get a new token using the credentials
        $response = Http::post("{$this->baseUrl}/token/new/", [
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
     */
    private function processTokenResponse(\Illuminate\Http\Client\Response $response): string
    {
        $data = $response->json();

        if (! isset($data['access'], $data['refresh'], $data['access_expires'], $data['refresh_expires'])) {
            throw new \InvalidArgumentException('Invalid token response');
        }

        $this->accessToken = $data['access'];
        $this->refreshToken = $data['refresh'];

        // Calculate expiration times
        $this->accessTokenExpires = (new \DateTime)->add(new \DateInterval('PT'.$data['access_expires'].'S'));
        $this->refreshTokenExpires = (new \DateTime)->add(new \DateInterval('PT'.$data['refresh_expires'].'S'));

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
    public function createEndUserAgreement(string $institutionId, array $userData): array
    {
        $response = Http::withToken($this->getAccessToken())
            ->post("{$this->baseUrl}/agreements/enduser/", [
                'institution_id' => $institutionId,
                'max_historical_days' => 90,
                'access_valid_for_days' => 90,
                'access_scope' => ['balances', 'details', 'transactions'],
                'user_data' => $userData,
            ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to create end user agreement: '.$response->body());
        }

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
        if ($this->useCache && Cache::has("gocardless_accounts_{$requisitionId}")) {
            return Cache::get("gocardless_accounts_{$requisitionId}");
        }
        $response = Http::withToken($this->getAccessToken())
            ->get("{$this->baseUrl}/requisitions/{$requisitionId}/");

        if (! $response->successful()) {
            throw new \Exception('Failed to get accounts: '.$response->body());
        }
        if ($this->useCache) {
            Cache::put("gocardless_accounts_{$requisitionId}", $response->json()['accounts'] ?? [], $this->cacheDuration);
        }

        return $response->json()['accounts'] ?? [];
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
        $response = Http::withToken($this->getAccessToken())
            ->get("{$this->baseUrl}/accounts/{$accountId}/details/");

        if (! $response->successful()) {
            throw new \Exception('Failed to get account details: '.$response->body());
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
        if ($this->useCache && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
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
                $response = Http::withToken($this->getAccessToken())
                    ->get($url, $params);

                if (! $response->successful()) {
                    throw new \Exception('Failed to get transactions: '.$response->body());
                }

                $data = $response->json();
                $transactions = $data['transactions'] ?? [];

                // Merge transactions
                if (isset($transactions['booked'])) {
                    $allTransactions['transactions']['booked'] = array_merge(
                        $allTransactions['transactions']['booked'] ?? [],
                        $transactions['booked']
                    );
                }
                if (isset($transactions['pending'])) {
                    $allTransactions['transactions']['pending'] = array_merge(
                        $allTransactions['transactions']['pending'] ?? [],
                        $transactions['pending']
                    );
                }

                // Get next page URL if available
                $nextPage = $data['next'] ?? null;

                // Reset retry count on successful request
                $retryCount = 0;

            } catch (\Exception $e) {
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
        $response = Http::withToken($this->getAccessToken())
            ->get("{$this->baseUrl}/accounts/{$accountId}/balances/");

        if (! $response->successful()) {
            throw new \Exception('Failed to get balances: '.$response->body());
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
        $response = Http::withToken($this->getAccessToken())
            ->post("{$this->baseUrl}/requisitions/", [
                'institution_id' => $institutionId,
                'redirect' => $redirectUrl,
                // 'agreement' => $agreementId,
                'user_language' => 'EN',
            ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to create requisition: '.$response->body());
        }

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
        if ($this->useCache && Cache::has($key)) {
            return Cache::get($key);
        }

        $response = Http::withToken($this->getAccessToken())
            ->get($requisitionId ? "{$this->baseUrl}/requisitions/{$requisitionId}/" : "{$this->baseUrl}/requisitions/");
        if (! $response->successful()) {
            throw new \Exception('Failed to get requisition: '.$response->body());
        }
        if ($this->useCache) {
            Cache::put($key, $response->json(), $this->cacheDuration);
        }

        return $response->json();
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
        $response = Http::withToken($this->getAccessToken())
            ->delete("{$this->baseUrl}/requisitions/{$requisitionId}/");

        if (! $response->successful()) {
            throw new \Exception('Failed to delete requisition: '.$response->body());
        }
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
        if ($this->useCache && Cache::has($key)) {
            return Cache::get($key);
        }
        $response = Http::withToken($this->getAccessToken())
            ->get("{$this->baseUrl}/institutions?country={$countryCode}");
        if (! $response->successful()) {
            throw new \Exception('Failed to get institutions: '.$response->body());
        }
        if ($this->useCache) {
            Cache::put($key, $response->json(), $this->cacheDuration);
        }

        return $response->json();
    }
}
