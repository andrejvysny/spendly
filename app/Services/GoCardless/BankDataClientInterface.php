<?php

namespace App\Services\GoCardless;

interface BankDataClientInterface
{
    public function getSecretTokens(): array;

    public function createEndUserAgreement(string $institutionId, array $userData): array;

    public function getAccounts(string $requisitionId): array;

    public function getAccountDetails(string $accountId): array;

    public function getTransactions(string $accountId, ?string $dateFrom = null, ?string $dateTo = null): array;

    public function getBalances(string $accountId): array;

    public function createRequisition(string $institutionId, string $redirectUrl, ?string $agreementId = null): array;

    public function getRequisitions(?string $requisitionId = null): array;

    public function deleteRequisition(string $requisitionId): bool;

    public function getInstitutions(string $countryCode): array;
}
