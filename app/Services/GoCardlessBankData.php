<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class GoCardlessBankData
{
    private string $baseUrl = 'https://bankaccountdata.gocardless.com/api/v2';

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

    public function getSecretTokens(): array
    {
        return [
            'access' => $this->accessToken,
            'refresh' => $this->refreshToken,
        ];
    }

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
     * Process token response and set up expiration times
     */
    private function processTokenResponse($response): string
    {
        $data = $response->json();

        $this->accessToken = $data['access'];
        $this->refreshToken = $data['refresh'];

        // Calculate expiration times
        $this->accessTokenExpires = (new \DateTime)->add(new \DateInterval('PT'.$data['access_expires'].'S'));
        $this->refreshTokenExpires = (new \DateTime)->add(new \DateInterval('PT'.$data['refresh_expires'].'S'));

        return $this->accessToken;
    }

    /**
     * Create a new end user agreement
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
     * Get all accounts for a requisition
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
     * Get account details
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
     * Get account transactions
     */
    public function getTransactions(string $accountId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        // Check cache first
        $cacheKey = "gocardless_transactions_{$accountId}_{$dateFrom}_{$dateTo}";
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $params = [];
        if ($dateFrom) {
            $params['date_from'] = $dateFrom;
        }
        if ($dateTo) {
            $params['date_to'] = $dateTo;
        }

        $response = Http::withToken($this->getAccessToken())
            ->get("{$this->baseUrl}/accounts/{$accountId}/transactions/", $params);

        // Chache the response for 1 hour
        Cache::put($cacheKey, $response->json(), 36000);

        if (! $response->successful()) {
            throw new \Exception('Failed to get transactions: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Get account balances
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
     * Create a new requisition
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
