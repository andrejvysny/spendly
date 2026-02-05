<?php

declare(strict_types=1);

namespace App\Services\GoCardless;

use App\Models\User;
use App\Services\GoCardless\Mock\MockGoCardlessFixtureRepository;
use Illuminate\Support\Facades\Cache;

class MockGoCardlessBankDataClient implements BankDataClientInterface
{
    private const string CACHE_KEY_PREFIX = 'gocardless_mock_requisitions_';

    private const string MOCK_ACCOUNT_1 = 'mock_account_1';

    private const string MOCK_ACCOUNT_2 = 'mock_account_2';

    private const string MOCK_INSTITUTION = 'MOCK_INSTITUTION';

    public function __construct(
        private User $user,
        private MockGoCardlessFixtureRepository $fixtureRepository
    ) {}

    public function getSecretTokens(): array
    {
        return [
            'access' => 'mock_access_token',
            'refresh' => 'mock_refresh_token',
        ];
    }

    public function createEndUserAgreement(string $institutionId, array $userData): array
    {
        return [
            'id' => 'mock_agreement_' . uniqid(),
            'institution_id' => $institutionId,
            'max_historical_days' => 90,
            'access_valid_for_days' => 90,
            'created' => now()->toIso8601String(),
        ];
    }

    public function getAccounts(string $requisitionId): array
    {
        $requisition = $this->findRequisition($requisitionId);
        $institutionId = $requisition !== null ? ($requisition['institution_id'] ?? null) : null;
        $accountIds = $this->getAccountIdsForRequisition($institutionId);
        $this->markRequisitionLinked($requisitionId, $accountIds);

        return $accountIds;
    }

    public function getAccountDetails(string $accountId): array
    {
        $payload = $this->fixtureRepository->getAccountDetailsPayload($accountId);
        if ($payload !== null) {
            $resolved = $this->fixtureRepository->resolveAccountId($accountId);
            if ($resolved !== null) {
                $payload['account']['institution_id'] = $resolved['institution'];
                $payload['account']['id'] = $accountId;
            }

            return $payload;
        }

        return [
            'account' => [
                'id' => $accountId,
                'resourceId' => $accountId,
                'iban' => 'GB99MOCK' . substr(md5($accountId), 0, 8),
                'name' => 'Mock Account ' . substr($accountId, -4),
                'currency' => 'EUR',
                'ownerName' => 'Mock User',
                'type' => 'checking',
            ],
        ];
    }

    public function getTransactions(string $accountId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $payload = $this->fixtureRepository->getTransactionsPayload($accountId, $dateFrom, $dateTo);
        if ($payload !== null) {
            return $payload;
        }

        $transactions = [
            'booked' => [],
            'pending' => [],
        ];

        for ($i = 0; $i < 5; $i++) {
            $transactions['booked'][] = [
                'transactionId' => 'mock_tx_booked_' . $accountId . '_' . $i,
                'bookingDate' => now()->subDays($i)->format('Y-m-d'),
                'valueDate' => now()->subDays($i)->format('Y-m-d'),
                'transactionAmount' => [
                    'amount' => (string) rand(-100, -10),
                    'currency' => 'EUR',
                ],
                'remittanceInformationUnstructured' => 'Mock Transaction ' . $i,
                'remittanceInformationUnstructuredArray' => ['Mock Transaction ' . $i],
            ];
        }

        $transactions['pending'][] = [
            'transactionId' => 'mock_tx_pending_' . $accountId,
            'valueDate' => now()->addDays(1)->format('Y-m-d'),
            'transactionAmount' => [
                'amount' => '-15.50',
                'currency' => 'EUR',
            ],
            'remittanceInformationUnstructured' => 'Pending Mock Transaction',
        ];

        return [
            'transactions' => $transactions,
        ];
    }

    public function getBalances(string $accountId): array
    {
        $payload = $this->fixtureRepository->getBalancesPayload($accountId);
        if ($payload !== null) {
            return $payload;
        }

        return [
            'balances' => [
                [
                    'balanceType' => 'closingBooked',
                    'balanceAmount' => [
                        'amount' => '1250.00',
                        'currency' => 'EUR',
                    ],
                    'referenceDate' => now()->format('Y-m-d'),
                ],
            ],
        ];
    }

    public function createRequisition(string $institutionId, string $redirectUrl, ?string $agreementId = null): array
    {
        $id = 'mock_requisition_' . uniqid();
        $requisition = $this->buildRequisition(
            $id,
            $redirectUrl,
            'CR',
            $institutionId,
            $agreementId ?? 'mock_agreement_id',
            []
        );
        $requisition['link'] = $redirectUrl . '?mock=1&requisition_id=' . $id;

        $this->appendRequisition($requisition);

        return $requisition;
    }

    public function getRequisitions(?string $requisitionId = null): array
    {
        if ($requisitionId !== null) {
            $single = $this->findRequisition($requisitionId);

            return $single ?? $this->buildRequisition(
                $requisitionId,
                '',
                'LN',
                self::MOCK_INSTITUTION,
                'mock_agreement_id',
                [self::MOCK_ACCOUNT_1, self::MOCK_ACCOUNT_2]
            );
        }

        $list = $this->getCachedRequisitions();

        return [
            'count' => count($list),
            'next' => null,
            'previous' => null,
            'results' => $list,
        ];
    }

    public function deleteRequisition(string $requisitionId): bool
    {
        $this->removeRequisition($requisitionId);

        return true;
    }

    public function getInstitutions(string $countryCode): array
    {
        if ($this->fixtureRepository->hasFixtureData()) {
            return $this->fixtureRepository->getInstitutions($countryCode);
        }

        return [
            [
                'id' => self::MOCK_INSTITUTION,
                'name' => 'Mock Bank',
                'bic' => 'MOCKGB2L',
                'transaction_total_days' => '90',
                'max_access_valid_for_days' => '90',
                'countries' => [$countryCode],
                'logo' => 'https://example.com/mock-logo.png',
            ],
        ];
    }

    /**
     * Build a full Requisition shape matching API and RequisitionDto.
     *
     * @param  array<string>  $accounts
     * @return array<string, mixed>
     */
    private function buildRequisition(
        string $id,
        string $redirect,
        string $status,
        string $institutionId,
        string $agreement,
        array $accounts,
        ?string $link = null
    ): array {
        $base = [
            'id' => $id,
            'created' => now()->toIso8601String(),
            'redirect' => $redirect,
            'status' => $status,
            'institution_id' => $institutionId,
            'agreement' => $agreement,
            'reference' => '',
            'accounts' => $accounts,
            'user_language' => 'EN',
            'link' => $link ?? $redirect,
            'ssn' => null,
            'account_selection' => false,
            'redirect_immediate' => false,
        ];

        return $base;
    }

    private function getCacheKey(): string
    {
        return self::CACHE_KEY_PREFIX . $this->user->id;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getCachedRequisitions(): array
    {
        return Cache::get($this->getCacheKey(), []);
    }

    private function setCachedRequisitions(array $requisitions): void
    {
        Cache::put($this->getCacheKey(), $requisitions, now()->addDays(1));
    }

    private function appendRequisition(array $requisition): void
    {
        $list = $this->getCachedRequisitions();
        $list[] = $requisition;
        $this->setCachedRequisitions($list);
    }

    /**
     * @param  array<int, string>  $accountIds
     */
    private function markRequisitionLinked(string $requisitionId, array $accountIds): void
    {
        $list = $this->getCachedRequisitions();
        foreach ($list as $i => $req) {
            if (($req['id'] ?? '') === $requisitionId) {
                $list[$i]['status'] = 'LN';
                $list[$i]['accounts'] = $accountIds;
                $this->setCachedRequisitions($list);
                break;
            }
        }
    }

    /**
     * Get account IDs for a requisition's institution (fixture or fallback).
     *
     * @return array<int, string>
     */
    private function getAccountIdsForRequisition(?string $institutionId): array
    {
        if ($institutionId !== null && $institutionId !== '') {
            $ids = $this->fixtureRepository->getAccountIdsForInstitution($institutionId);
            if ($ids !== []) {
                return $ids;
            }
        }

        return [self::MOCK_ACCOUNT_1, self::MOCK_ACCOUNT_2];
    }

    private function findRequisition(string $requisitionId): ?array
    {
        foreach ($this->getCachedRequisitions() as $req) {
            if (($req['id'] ?? '') === $requisitionId) {
                return $req;
            }
        }

        return null;
    }

    private function removeRequisition(string $requisitionId): void
    {
        $list = array_values(array_filter(
            $this->getCachedRequisitions(),
            fn (array $req): bool => ($req['id'] ?? '') !== $requisitionId
        ));
        $this->setCachedRequisitions($list);
    }
}
