<?php

namespace App\Services\GoCardless;

interface BankDataClientInterface
{
    public function getSecretTokens(): array;

    public function createEndUserAgreement(string $institutionId, array $userData): array;

    /**
     * Fetch an existing End User Agreement so its exact access window
     * (accepted timestamp + access_valid_for_days) can be persisted.
     *
     * @return array<string, mixed>
     */
    public function getEndUserAgreement(string $agreementId): array;

    public function getAccounts(string $requisitionId): array;

    public function getAccountMetadata(string $accountId): array;

    public function getAccountDetails(string $accountId): array;

    public function getTransactions(string $accountId, ?string $dateFrom = null, ?string $dateTo = null): array;

    public function getBalances(string $accountId): array;

    /**
     * @param  string|null  $reference  Caller-supplied correlation id; GoCardless echoes it
     *                                  back on the redirect as `ref=<reference>`.
     */
    public function createRequisition(string $institutionId, string $redirectUrl, ?string $agreementId = null, ?string $reference = null): array;

    public function getRequisitions(?string $requisitionId = null): array;

    public function deleteRequisition(string $requisitionId): bool;

    public function getInstitutions(string $countryCode): array;
}
