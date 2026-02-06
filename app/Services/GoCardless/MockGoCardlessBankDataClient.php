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

    private const array MOCK_ACCOUNTS = [
        self::MOCK_ACCOUNT_1 => [
            'institution_id' => 'Revolut',
            'bank_name' => 'Revolut',
            'name' => 'Mock Revolut Account',
            'iban' => 'LT11MOCK000000000001',
            'currency' => 'EUR',
            'ownerName' => 'Mock User',
            'cashAccountType' => 'CACC',
            'type' => 'checking',
            'bic' => 'REVOGB21',
        ],
        self::MOCK_ACCOUNT_2 => [
            'institution_id' => 'SLSP',
            'bank_name' => 'Slovenská sporiteľňa',
            'name' => 'Mock SLSP Account',
            'iban' => 'SK11MOCK000000000002',
            'currency' => 'EUR',
            'ownerName' => 'Mock User',
            'cashAccountType' => 'CACC',
            'type' => 'checking',
            'bic' => 'GIBASKBX',
        ],
    ];

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

        $profile = $this->getMockAccountProfile($accountId);

        return [
            'account' => [
                'id' => $accountId,
                'resourceId' => $accountId,
                'iban' => $profile['iban'],
                'name' => $profile['name'],
                'currency' => $profile['currency'],
                'ownerName' => $profile['ownerName'],
                'cashAccountType' => $profile['cashAccountType'],
                'type' => $profile['type'],
                'institution_id' => $profile['institution_id'],
                'bank_name' => $profile['bank_name'],
                'bic' => $profile['bic'],
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
            'booked' => $this->buildMockBookedTransactions($accountId),
            'pending' => $this->buildMockPendingTransactions($accountId),
        ];
        return ['transactions' => $transactions];
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

    /**
     * @return array<string, string>
     */
    private function getMockAccountProfile(string $accountId): array
    {
        $profile = self::MOCK_ACCOUNTS[$accountId] ?? null;
        if ($profile !== null) {
            return $profile;
        }

        return [
            'institution_id' => self::MOCK_INSTITUTION,
            'bank_name' => 'Mock Bank',
            'name' => 'Mock Account ' . substr($accountId, -4),
            'iban' => 'GB99MOCK' . substr(md5($accountId), 0, 8),
            'currency' => 'EUR',
            'ownerName' => 'Mock User',
            'cashAccountType' => 'CACC',
            'type' => 'checking',
            'bic' => 'MOCKGB2L',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildMockBookedTransactions(string $accountId): array
    {
        $profile = $this->getMockAccountProfile($accountId);
        $iban = $profile['iban'];
        $otherAccountId = $accountId === self::MOCK_ACCOUNT_1 ? self::MOCK_ACCOUNT_2 : self::MOCK_ACCOUNT_1;
        $otherIban = $this->getMockAccountProfile($otherAccountId)['iban'];

        if ($accountId === self::MOCK_ACCOUNT_1) {
            $booked = [];
            $recurringOffsets = [90, 60, 30, 0];
            foreach ($recurringOffsets as $idx => $daysAgo) {
                $date = $this->daysAgo($daysAgo);
                $booked[] = [
                    'transactionId' => 'mock_tx_recurring_' . $accountId . '_' . $idx,
                    'bookingDate' => $date,
                    'valueDate' => $date,
                    'transactionAmount' => [
                        'amount' => '-9.99',
                        'currency' => 'EUR',
                    ],
                    'remittanceInformationUnstructuredArray' => ['Netflix'],
                    'creditorName' => 'Netflix',
                    'debtorAccount' => ['iban' => $iban],
                    'creditorAccount' => ['iban' => 'GB12MOCKNETFLIX01'],
                    'proprietaryBankTransactionCode' => 'CARD_PAYMENT',
                    'bankTransactionCode' => 'MCRD',
                ];
            }

            $dupDate = $this->daysAgo(10);
            $duplicateBase = [
                'bookingDate' => $dupDate,
                'valueDate' => $dupDate,
                'transactionAmount' => [
                    'amount' => '-12.34',
                    'currency' => 'EUR',
                ],
                'remittanceInformationUnstructuredArray' => ['Mock Cafe'],
                'creditorName' => 'Mock Cafe',
                'debtorAccount' => ['iban' => $iban],
                'creditorAccount' => ['iban' => 'GB12MOCKCAFE0001'],
                'proprietaryBankTransactionCode' => 'CARD_PAYMENT',
                'bankTransactionCode' => 'MCRD',
            ];
            $booked[] = array_merge($duplicateBase, ['transactionId' => 'mock_tx_duplicate_' . $accountId . '_a']);

            $transferDate = $this->daysAgo(20);
            $booked[] = [
                'transactionId' => 'mock_tx_transfer_out_' . $accountId,
                'bookingDate' => $transferDate,
                'valueDate' => $transferDate,
                'transactionAmount' => [
                    'amount' => '-250.00',
                    'currency' => 'EUR',
                ],
                'remittanceInformationUnstructured' => 'Transfer to savings',
                'creditorName' => 'Savings Account',
                'debtorAccount' => ['iban' => $iban],
                'creditorAccount' => ['iban' => $otherIban],
                'proprietaryBankTransactionCode' => 'CARD_PAYMENT',
                'bankTransactionCode' => 'PMNT',
            ];

            return $booked;
        }

        if ($accountId === self::MOCK_ACCOUNT_2) {
            $transferDate = $this->daysAgo(20);

            return [
                [
                    'transactionId' => 'mock_tx_transfer_in_' . $accountId,
                    'bookingDate' => $transferDate,
                    'valueDate' => $transferDate,
                    'transactionAmount' => [
                        'amount' => '250.00',
                        'currency' => 'EUR',
                    ],
                    'remittanceInformationUnstructured' => 'Transfer from main',
                    'debtorName' => 'Main Account',
                    'debtorAccount' => ['iban' => $otherIban],
                    'creditorAccount' => ['iban' => $iban],
                    'proprietaryBankTransactionCode' => 'MANUAL',
                    'bankTransactionCode' => 'PMNT',
                ],
                [
                    'transactionId' => 'mock_tx_slsp_card_' . $accountId,
                    'bookingDate' => $this->daysAgo(5),
                    'valueDate' => $this->daysAgo(5),
                    'transactionAmount' => [
                        'amount' => '-45.60',
                        'currency' => 'EUR',
                    ],
                    'remittanceInformationUnstructured' => 'MCC-5411',
                    'creditorName' => 'Local Grocery',
                    'debtorAccount' => ['iban' => $iban],
                    'creditorAccount' => ['iban' => 'SK99MOCKGROCERY01'],
                    'proprietaryBankTransactionCode' => 'POSPAYMENT',
                    'bankTransactionCode' => 'MCRD',
                ],
            ];
        }

        $fallbackDate = $this->daysAgo(1);

        return [
            [
                'transactionId' => 'mock_tx_booked_' . $accountId,
                'bookingDate' => $fallbackDate,
                'valueDate' => $fallbackDate,
                'transactionAmount' => [
                    'amount' => '-25.00',
                    'currency' => 'EUR',
                ],
                'remittanceInformationUnstructured' => 'Mock Transaction',
                'debtorAccount' => ['iban' => $iban],
                'creditorAccount' => ['iban' => 'GB12MOCKFALLBACK01'],
                'proprietaryBankTransactionCode' => 'CARD_PAYMENT',
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildMockPendingTransactions(string $accountId): array
    {
        if ($accountId !== self::MOCK_ACCOUNT_1) {
            return [];
        }

        return [
            [
                'transactionId' => 'mock_tx_pending_' . $accountId,
                'valueDate' => now()->addDays(1)->format('Y-m-d'),
                'transactionAmount' => [
                    'amount' => '-15.50',
                    'currency' => 'EUR',
                ],
                'remittanceInformationUnstructured' => 'Pending Mock Transaction',
            ],
        ];
    }

    private function daysAgo(int $days): string
    {
        return now()->subDays($days)->format('Y-m-d');
    }
}
