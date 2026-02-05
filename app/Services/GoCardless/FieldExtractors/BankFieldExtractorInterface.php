<?php

declare(strict_types=1);

namespace App\Services\GoCardless\FieldExtractors;

interface BankFieldExtractorInterface
{
    /**
     * Extract a non-empty description from the transaction.
     */
    public function extractDescription(array $transaction): string;

    /**
     * Extract partner/merchant/counterparty name if available.
     */
    public function extractPartner(array $transaction): ?string;

    /**
     * Extract merchant category code (e.g. from MCC-xxxx pattern) if available.
     */
    public function extractMerchantCategoryCode(array $transaction): ?string;

    /**
     * Extract currency exchange data when present (Revolut etc.).
     *
     * @return array{instructedAmount?: array{amount?: string, currency?: string}, sourceCurrency?: string, targetCurrency?: string, exchangeRate?: string, unitCurrency?: string}|null
     */
    public function extractCurrencyExchange(array $transaction): ?array;

    /**
     * Determine transaction type from codes and amount.
     */
    public function extractTransactionType(array $transaction, float $amount): string;

    /**
     * Extract metadata (internalTransactionId, entryReference, codes, etc.).
     *
     * @return array<string, mixed>
     */
    public function extractMetadata(array $transaction): array;
}
