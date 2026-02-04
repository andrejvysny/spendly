<?php

namespace App\Services\GoCardless;

use App\Models\User;

class MockGoCardlessBankDataClient implements BankDataClientInterface
{
    public function __construct(private User $user) {}

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
        // Return a couple of mock accounts
        return [
            'mock_account_1',
            'mock_account_2',
        ];
    }

    public function getAccountDetails(string $accountId): array
    {
        return [
            'account' => [
                'id' => $accountId,
                'iban' => 'GB99MOCK' . rand(10000000, 99999999),
                'name' => 'Mock Account ' . substr($accountId, -4),
                'currency' => 'EUR',
                'ownerName' => 'Mock User',
            ],
        ];
    }

    public function getTransactions(string $accountId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $transactions = [
            'booked' => [],
            'pending' => [],
        ];

        // Generate some random booked transactions
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
            ];
        }

         // Generate a pending transaction
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
        return [
            'id' => 'mock_requisition_' . uniqid(),
            'created' => now()->toIso8601String(),
            'redirect' => $redirectUrl,
            'status' => 'CR',
            'agreement' => $agreementId ?? 'mock_agreement_id',
            'accounts' => [],
            'link' => $redirectUrl,
        ];
    }

    public function getRequisitions(?string $requisitionId = null): array
    {
        if ($requisitionId) {
            return [
                 'id' => $requisitionId,
                 'created' => now()->subDays(1)->toIso8601String(),
                 'status' => 'LN', // Linked
                 'accounts' => ['mock_account_1', 'mock_account_2'],
                 'institution_id' => 'MOCK_INSTITUTION',
            ];
        }

        return [
            'count' => 1,
            'next' => null,
            'previous' => null,
            'results' => [
                [
                    'id' => 'mock_req_1',
                    'status' => 'LN',
                    'institution_id' => 'MOCK_INSTITUTION',
                ]
            ]
        ];
    }

    public function deleteRequisition(string $requisitionId): bool
    {
        return true;
    }

    public function getInstitutions(string $countryCode): array
    {
        return [
            [
                'id' => 'MOCK_INSTITUTION',
                'name' => 'Mock Bank',
                'bic' => 'MOCKGB2L',
                'transaction_total_days' => 90,
                'countries' => [$countryCode],
                'logo' => 'https://example.com/mock-logo.png',
            ],
        ];
    }
}
