<?php

declare(strict_types=1);

namespace App\Services\Transfers;

use App\Models\Transaction;

/**
 * Detects internal single-leg moves that have no matching credit leg in any
 * synced account - typically Revolut pocket/vault transfers ("To pocket EUR
 * Savings", "Revolut Vault | To EUR RoundUps"). These are money moved between
 * spaces of the same product, not income/expense.
 */
final class SingleLegDetector
{
    public function __construct(private readonly TransferConfig $config) {}

    public function isSingleLegTransfer(Transaction $transaction): bool
    {
        $metadata = is_array($transaction->metadata) ? $transaction->metadata : [];

        if (($metadata['single_leg_transfer_candidate'] ?? false) === true) {
            return true;
        }

        $matchesPattern = $this->matchesPattern($transaction);
        if (! $matchesPattern) {
            return false;
        }

        // GoCardless path: proprietary TRANSFER code with no counterparty account at all.
        $proprietaryCode = AccountContext::fold((string) ($metadata['proprietaryBankTransactionCode'] ?? ''));
        if (str_contains($proprietaryCode, 'TRANSFER')
            && Iban::normalize($transaction->source_iban) === null
            && Iban::normalize($transaction->target_iban) === null
        ) {
            return true;
        }

        // CSV path: import-time transfer flag plus a pocket-like description.
        return ($metadata['transfer_candidate'] ?? false) === true;
    }

    private function matchesPattern(Transaction $transaction): bool
    {
        $haystack = AccountContext::fold(
            (string) ($transaction->description ?? '').' '.(string) ($transaction->partner ?? '')
        );
        if ($haystack === '') {
            return false;
        }

        foreach ($this->config->singleLegPatterns as $pattern) {
            $needle = AccountContext::fold($pattern);
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
