<?php

declare(strict_types=1);

namespace App\Services\GoCardless;

use App\Exceptions\GoCardlessApiException;
use App\Exceptions\GoCardlessConsentExpiredException;
use App\Exceptions\GoCardlessRateLimitException;
use App\Support\SensitiveDataRedactor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GoCardlessBankDataClient implements BankDataClientInterface
{
    /**
     * Hard stop for transaction pagination — a cursor that never terminates must not hang a worker.
     */
    private const MAX_TRANSACTION_PAGES = 50;

    /**
     * Retries allowed for a single transactions page.
     */
    private const MAX_PAGE_RETRIES = 2;

    /**
     * Retries allowed across a whole getTransactions() run, however many pages it spans.
     */
    private const MAX_TRANSACTION_RETRIES = 5;

    /**
     * Substrings GoCardless uses on 401/403/409 responses when the End User Agreement lapsed or
     * the institution suspended the account — as opposed to a credential/token problem. Matched
     * case-insensitively against the response body.
     *
     * @var list<string>
     */
    private const array CONSENT_FAILURE_MARKERS = [
        'AccessExpiredError',
        'Access to account has expired',
        'AccountSuspended',
        'account is suspended',
        // The documented 409 payload words it differently from the 401/403 ones: summary
        // "Account suspended", detail "This account or its requisition was suspended due to
        // numerous errors...". Neither of the two markers above matches that text.
        'Account suspended',
        'requisition was suspended',
        'ExpiredConsent',
        'EUA has expired',
    ];

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
     * Create an HTTP request authenticated with an explicit token, using the standard timeouts.
     *
     * The token is passed in rather than resolved here so that a call can be replayed with a
     * different token without re-entering token resolution.
     */
    private function requestWith(string $token): PendingRequest
    {
        return Http::withToken($token)
            ->timeout(30)
            ->connectTimeout(10);
    }

    /**
     * Execute an authenticated API call, replaying it once with a fresh token after a 401.
     *
     * A 401 means GoCardless rejected the request while authenticating it, before any side effect
     * was applied, so a single replay of the identical call is safe even for POST and DELETE.
     * The replay only happens when a genuinely different token was obtained; otherwise the original
     * 401 response is returned unchanged for the caller to handle.
     *
     * @param  callable(PendingRequest): Response  $call
     *
     * @throws ConnectionException
     */
    private function sendWithAuthRetry(callable $call): Response
    {
        $token = $this->getAccessToken();
        $response = $call($this->requestWith($token));

        if ($response->status() === 401) {
            $fresh = $this->forceRefreshToken($token);

            if ($fresh !== null && $fresh !== $token) {
                $response = $call($this->requestWith($fresh));
            }
        }

        return $response;
    }

    /**
     * Execute an authenticated API call and translate an unsuccessful response into an exception.
     *
     * @param  callable(PendingRequest): Response  $call
     *
     * @throws GoCardlessRateLimitException On 429 responses
     * @throws \Exception On other non-successful responses
     */
    private function send(string $context, callable $call): Response
    {
        $response = $this->sendWithAuthRetry($call);

        $this->handleResponse($response, $context);

        return $response;
    }

    /**
     * Obtain an access token that is known not to be the one the API just rejected.
     *
     * Bypasses the usual expiry heuristics: the API is the authority on whether a token works,
     * and it already said this one does not.
     *
     * @return string|null Null when no fresh token could be obtained — the caller then lets the
     *                     original 401 surface through handleResponse().
     */
    private function forceRefreshToken(string $failedToken): ?string
    {
        try {
            if ($this->tokenManager) {
                return $this->tokenManager->refreshAfterUnauthorized($failedToken);
            }

            // In-memory fallback (mock/sandbox): drop the cached expiry so the token is re-fetched.
            $this->accessTokenExpires = null;

            return $this->getAccessToken();
        } catch (\Throwable $e) {
            Log::warning('GoCardless token refresh after 401 failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
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
                return $this->processRefreshedTokenResponse($response);
            }
        }

        $response = Http::timeout(30)->connectTimeout(10)
            ->post("{$this->baseUrl}/token/new/", [
                'secret_id' => $this->secretId,
                'secret_key' => $this->secretKey,
            ]);

        if (! $response->successful()) {
            Log::warning('GoCardless token request failed', [
                'status' => $response->status(),
                'body' => SensitiveDataRedactor::redact($response->body()),
            ]);

            throw new \Exception('Failed to get access token (HTTP '.$response->status().')');
        }

        return $this->processNewTokenResponse($response);
    }

    /**
     * Parse a POST /token/new/ response, which mints a full pair.
     *
     * SpectacularJWTObtain is the only token schema that carries a refresh token, so all four
     * fields are required here.
     *
     * @param  \Illuminate\Http\Client\Response  $response  The HTTP response containing token data.
     * @return string The new access token.
     *
     * @throws \InvalidArgumentException When token response has invalid types or missing required fields.
     */
    private function processNewTokenResponse(Response $response): string
    {
        $data = $this->decodeTokenResponse($response);

        // Check for required keys
        if (! isset($data['access'], $data['refresh'], $data['access_expires'], $data['refresh_expires'])) {
            throw new \InvalidArgumentException('Invalid token response: missing required fields');
        }

        if (! is_string($data['refresh'])) {
            throw new \InvalidArgumentException('Invalid token response: refresh token must be a string');
        }

        $this->refreshToken = $data['refresh'];
        $this->refreshTokenExpires = $this->expiryFromNow($data['refresh_expires'], 'refresh_expires');

        return $this->applyAccessToken($data);
    }

    /**
     * Parse a POST /token/refresh/ response.
     *
     * SpectacularJWTRefresh carries only `access` and `access_expires` — refreshing does not rotate
     * the refresh token. Demanding the full pair here (as one shared parser used to) rejected every
     * valid refresh, so the in-memory token path could never recover from an expired access token.
     * A rotated refresh token is honoured if one ever appears, but never required.
     *
     * @return string The new access token.
     *
     * @throws \InvalidArgumentException When token response has invalid types or missing required fields.
     */
    private function processRefreshedTokenResponse(Response $response): string
    {
        $data = $this->decodeTokenResponse($response);

        if (! isset($data['access'], $data['access_expires'])) {
            throw new \InvalidArgumentException('Invalid token response: missing required fields');
        }

        if (isset($data['refresh']) && is_string($data['refresh']) && $data['refresh'] !== '') {
            $this->refreshToken = $data['refresh'];

            if (isset($data['refresh_expires'])) {
                $this->refreshTokenExpires = $this->expiryFromNow($data['refresh_expires'], 'refresh_expires');
            }
        }

        return $this->applyAccessToken($data);
    }

    /**
     * Validate and store the access token half shared by both token responses.
     *
     * @param  array<mixed>  $data
     *
     * @throws \InvalidArgumentException
     */
    private function applyAccessToken(array $data): string
    {
        if (! is_string($data['access'])) {
            throw new \InvalidArgumentException('Invalid token response: access token must be a string');
        }

        $this->accessToken = $data['access'];
        $this->accessTokenExpires = $this->expiryFromNow($data['access_expires'], 'access_expires');

        return $this->accessToken;
    }

    /**
     * Decode a token endpoint body into an array, so the callers can index it safely.
     *
     * @return array<mixed>
     *
     * @throws \InvalidArgumentException
     */
    private function decodeTokenResponse(Response $response): array
    {
        $data = $response->json();

        if (! is_array($data)) {
            throw new \InvalidArgumentException('Invalid token response: missing required fields');
        }

        return $data;
    }

    /**
     * Validate a "seconds from now" expiry and turn it into an absolute instant.
     *
     * @throws \InvalidArgumentException
     */
    private function expiryFromNow(mixed $seconds, string $field): \DateTime
    {
        if (! is_numeric($seconds)) {
            throw new \InvalidArgumentException("Invalid token response: {$field} must be numeric");
        }

        $seconds = (int) $seconds;

        if ($seconds <= 0) {
            throw new \InvalidArgumentException("Invalid token response: {$field} must be positive");
        }

        return (new \DateTime)->add(new \DateInterval('PT'.$seconds.'S'));
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
     * The raw response body never reaches the exception message — it may contain account data or
     * fragments of secrets. It is redacted and logged under a correlation id instead, so operators
     * can still look up what GoCardless actually said without that ever leaking to a caller.
     *
     * @throws GoCardlessRateLimitException On 429 responses
     * @throws GoCardlessConsentExpiredException On 401/403/409 responses whose body names a consent failure
     * @throws GoCardlessApiException On other non-successful responses
     */
    private function handleResponse(Response $response, string $context): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();
        $correlationId = (string) Str::uuid();
        $body = $response->body();
        $redactedBody = SensitiveDataRedactor::redact($body);

        Log::warning('GoCardless API error', [
            'context' => $context,
            'status' => $status,
            'correlation_id' => $correlationId,
            'body' => $redactedBody,
        ]);

        if ($status === 429) {
            $resetHeader = $response->header('X-Ratelimit-Account-Success-Reset');
            $retryAfter = $resetHeader ? (int) $resetHeader : 60;

            throw new GoCardlessRateLimitException($retryAfter, "Rate limited during {$context}. Retry after {$retryAfter}s.");
        }

        // Checked before the generic failure so an expired 90-day consent is never mistaken for a
        // transient auth error. A 401 only reaches here after send() already replayed the call with
        // a refreshed token, so the token is not what GoCardless is objecting to.
        //
        // 409 is in this list because the account endpoints document AccountSuspendedError there
        // ("This account or its requisition was suspended due to numerous errors that occurred
        // while accessing it") — see docs/gocardless.swagger.json. Without it a suspension fell
        // through to the generic branch, so the job rethrew, spent its backoff and exception budget
        // on something no retry can fix, and ended on a generic `failed` instead of
        // `needs_reconnect` — meaning the UI never asked the user to reconnect.
        if (in_array($status, [401, 403, 409], true) && $this->indicatesConsentFailure($body, $redactedBody)) {
            throw new GoCardlessConsentExpiredException(
                $this->identifierFromUri($response, 'requisitions'),
                $this->identifierFromUri($response, 'accounts'),
            );
        }

        throw new GoCardlessApiException($context, $status, $correlationId);
    }

    /**
     * Whether a 401/403/409 body names a withdrawn/expired consent rather than a credential problem.
     *
     * Matched against the raw body *and* the redacted copy: redaction rewrites JSON values for
     * sensitive keys, and a marker that happened to sit inside one would otherwise be lost.
     */
    private function indicatesConsentFailure(string $body, string $redactedBody): bool
    {
        foreach (self::CONSENT_FAILURE_MARKERS as $marker) {
            if (Str::contains($body, $marker, ignoreCase: true) || Str::contains($redactedBody, $marker, ignoreCase: true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pull the `{id}` out of a `.../{segment}/{id}/...` API path, when the effective URI is known.
     *
     * The URI is only populated for real transfers (it comes from Guzzle's transfer stats), so this
     * degrades to null rather than reaching for the request that produced the response.
     */
    private function identifierFromUri(Response $response, string $segment): ?string
    {
        $path = $response->effectiveUri()?->getPath();

        if ($path === null || $path === '') {
            return null;
        }

        if (preg_match('#/'.preg_quote($segment, '#').'/([^/]+)#', $path, $matches) !== 1) {
            return null;
        }

        return rawurldecode($matches[1]);
    }

    public function createEndUserAgreement(string $institutionId, array $userData): array
    {
        $response = $this->send(
            'create end user agreement',
            fn (PendingRequest $request): Response => $request->post("{$this->baseUrl}/agreements/enduser/", [
                'institution_id' => $institutionId,
                'max_historical_days' => 90,
                'access_valid_for_days' => 90,
                'access_scope' => ['balances', 'details', 'transactions'],
            ])
        );

        return $response->json();
    }

    /**
     * Retrieves an existing End User Agreement by ID.
     *
     * Used after the bank redirect to read the authoritative access window
     * (accepted timestamp + access_valid_for_days) instead of estimating it.
     *
     * @return array<string, mixed>
     */
    public function getEndUserAgreement(string $agreementId): array
    {
        $response = $this->send(
            'get end user agreement',
            fn (PendingRequest $request): Response => $request->get("{$this->baseUrl}/agreements/enduser/{$agreementId}/")
        );

        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    /**
     * Retrieves all bank accounts associated with a given requisition ID.
     *
     * Throws an exception if the API request fails.
     *
     * @param  string  $requisitionId  The requisition identifier.
     * @return array List of account IDs linked to the requisition, or an empty array if none are found.
     */
    public function getAccounts(string $requisitionId): array
    {
        $response = $this->send(
            'get accounts',
            fn (PendingRequest $request): Response => $request->get("{$this->baseUrl}/requisitions/{$requisitionId}/")
        );

        return $response->json()['accounts'] ?? [];
    }

    /**
     * Retrieves account metadata (institution_id, status, etc.) from the GoCardless account endpoint.
     */
    public function getAccountMetadata(string $accountId): array
    {
        $response = $this->send(
            'get account metadata',
            fn (PendingRequest $request): Response => $request->get("{$this->baseUrl}/accounts/{$accountId}/")
        );

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
        $response = $this->send(
            'get account details',
            fn (PendingRequest $request): Response => $request->get("{$this->baseUrl}/accounts/{$accountId}/details/")
        );

        return $response->json();
    }

    /**
     * Retrieves transactions for a specified account, following the API pagination cursor.
     *
     * The date filters are sent with the first request only. Every subsequent page is fetched from
     * the absolute `next` URL exactly as returned: passing query parameters alongside it would make
     * Guzzle replace that URL's query string, dropping the page cursor and re-fetching page one
     * forever.
     *
     * A failure after the first page never discards what was already read — the pages collected so
     * far are returned with `partial` set, and the caller decides what to do with an incomplete set.
     *
     * @param  string  $accountId  The unique identifier of the account.
     * @param  string|null  $dateFrom  Optional start date (YYYY-MM-DD) to filter transactions.
     * @param  string|null  $dateTo  Optional end date (YYYY-MM-DD) to filter transactions.
     * @return array{transactions: array{booked: list<mixed>}, partial: bool, partial_reason: string|null, pages_fetched: int}
     *
     * @throws GoCardlessRateLimitException If the very first page is rate limited.
     * @throws \Exception If the very first page cannot be fetched.
     */
    public function getTransactions(string $accountId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $params = [];
        if ($dateFrom) {
            $params['date_from'] = $dateFrom;
        }
        if ($dateTo) {
            $params['date_to'] = $dateTo;
        }

        $booked = [];
        $pagesFetched = 0;
        $partialReason = null;
        $retryBudget = self::MAX_TRANSACTION_RETRIES;
        $requestedUrls = [];
        $url = "{$this->baseUrl}/accounts/{$accountId}/transactions/";

        while (true) {
            if ($pagesFetched >= self::MAX_TRANSACTION_PAGES) {
                $partialReason = 'max_pages';
                Log::warning('GoCardless transaction pagination hit the page cap', [
                    'account_id' => $accountId,
                    'pages_fetched' => $pagesFetched,
                ]);
                break;
            }

            $pageUrl = $url;
            $isFirstPage = $pagesFetched === 0;
            $call = $isFirstPage
                ? fn (PendingRequest $request): Response => $request->get($pageUrl, $params)
                : fn (PendingRequest $request): Response => $request->get($pageUrl);

            try {
                $response = $this->fetchTransactionPage($call, $retryBudget);
            } catch (GoCardlessRateLimitException $e) {
                if ($isFirstPage) {
                    throw $e;
                }

                $partialReason = 'rate_limited';
                Log::warning('GoCardless rate limited mid-pagination, returning partial transactions', [
                    'account_id' => $accountId,
                    'pages_fetched' => $pagesFetched,
                    'retry_after' => $e->retryAfterSeconds,
                ]);
                break;
            } catch (\Exception $e) {
                if ($isFirstPage) {
                    throw $e;
                }

                $partialReason = 'page_fetch_failed';
                Log::warning('GoCardless transaction page failed, returning partial transactions', [
                    'account_id' => $accountId,
                    'pages_fetched' => $pagesFetched,
                    'error' => $e->getMessage(),
                ]);
                break;
            }

            $requestedUrls[$pageUrl] = true;
            $pagesFetched++;

            $data = $response->json();
            $data = is_array($data) ? $data : [];

            $transactions = $data['transactions'] ?? null;
            if (is_array($transactions) && isset($transactions['booked']) && is_array($transactions['booked'])) {
                $booked = array_merge($booked, array_values($transactions['booked']));
            }

            $next = $data['next'] ?? null;
            if ($next === null || $next === '') {
                break;
            }

            // Never follow a cursor pointing off the API host, and never re-request a page already
            // read — both mean the cursor is unusable, and following it is how the loop never ended.
            if (! is_string($next) || ! str_starts_with($next, $this->baseUrl) || isset($requestedUrls[$next])) {
                $partialReason = 'invalid_next_url';
                Log::warning('GoCardless returned an unusable pagination cursor', [
                    'account_id' => $accountId,
                    'pages_fetched' => $pagesFetched,
                ]);
                break;
            }

            $url = $next;
        }

        return [
            'transactions' => ['booked' => $booked],
            'partial' => $partialReason !== null,
            'partial_reason' => $partialReason,
            'pages_fetched' => $pagesFetched,
        ];
    }

    /**
     * Fetch a single transactions page, retrying only the failures a retry can plausibly fix.
     *
     * Connection errors and 5xx responses are retried (at most self::MAX_PAGE_RETRIES times for
     * this page, and only while the run-wide budget allows). Rate limits and other 4xx responses
     * are surfaced immediately — replaying those only burns quota.
     *
     * @param  callable(PendingRequest): Response  $call
     * @param  int  $retryBudget  Retries left for the whole pagination run; decremented in place.
     *
     * @throws GoCardlessRateLimitException
     * @throws ConnectionException
     * @throws \Exception
     */
    private function fetchTransactionPage(callable $call, int &$retryBudget): Response
    {
        $pageAttempts = 0;

        while (true) {
            $lastChance = $pageAttempts >= self::MAX_PAGE_RETRIES || $retryBudget <= 0;

            try {
                $response = $this->sendWithAuthRetry($call);

                if ($response->status() < 500 || $lastChance) {
                    $this->handleResponse($response, 'get transactions');

                    return $response;
                }
            } catch (ConnectionException $e) {
                if ($lastChance) {
                    throw $e;
                }
            }

            $pageAttempts++;
            $retryBudget--;
            sleep($pageAttempts);
        }
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
        $response = $this->send(
            'get balances',
            fn (PendingRequest $request): Response => $request->get("{$this->baseUrl}/accounts/{$accountId}/balances/")
        );

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
     * @param  string|null  $agreementId  Optional End User Agreement ID to attach to the requisition.
     * @param  string|null  $reference  Optional correlation id echoed back on the redirect as `ref=`.
     * @return array The API response as an associative array.
     */
    public function createRequisition(string $institutionId, string $redirectUrl, ?string $agreementId = null, ?string $reference = null): array
    {
        $payload = [
            'institution_id' => $institutionId,
            'redirect' => $redirectUrl,
            'user_language' => 'EN',
        ];

        if ($agreementId) {
            $payload['agreement'] = $agreementId;
        }

        if ($reference !== null && $reference !== '') {
            $payload['reference'] = $reference;
        }

        $response = $this->send(
            'create requisition',
            fn (PendingRequest $request): Response => $request->post("{$this->baseUrl}/requisitions/", $payload)
        );

        return $response->json();
    }

    /**
     * Retrieves one or all requisitions from the GoCardless API.
     *
     * If a requisition ID is provided, returns details for that requisition; otherwise, returns all requisitions.
     *
     * @param  string|null  $requisitionId  Optional requisition ID to fetch a specific requisition.
     * @return array The requisition data as an associative array.
     *
     * @throws \Exception If the API request fails.
     */
    public function getRequisitions(?string $requisitionId = null): array
    {
        $url = $requisitionId ? "{$this->baseUrl}/requisitions/{$requisitionId}/" : "{$this->baseUrl}/requisitions/";

        $response = $this->send(
            'get requisitions',
            fn (PendingRequest $request): Response => $request->get($url)
        );

        return $response->json();
    }

    /**
     * Deletes a requisition by its ID from the GoCardless API.
     *
     * @param  string  $requisitionId  The ID of the requisition to delete.
     * @return bool True if the requisition was successfully deleted.
     *
     * @throws \Exception If the API request fails.
     */
    public function deleteRequisition(string $requisitionId): bool
    {
        $this->send(
            'delete requisition',
            fn (PendingRequest $request): Response => $request->delete("{$this->baseUrl}/requisitions/{$requisitionId}/")
        );

        return true;
    }

    /**
     * Retrieves a list of financial institutions available for a specified country code.
     *
     * Uses caching if enabled to reduce redundant API calls. Throws an exception if the API request fails.
     *
     * Only tenant-independent data may be cached; anything derived from a requisition/account id is
     * per-user and MUST NOT be cached in the shared store.
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
        $response = $this->send(
            'get institutions',
            fn (PendingRequest $request): Response => $request->get("{$this->baseUrl}/institutions?country={$countryCode}")
        );

        if ($this->useCache) {
            Cache::put($key, $response->json(), $this->cacheDuration);
        }

        return $response->json();
    }
}
