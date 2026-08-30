<?php

declare(strict_types=1);

namespace App\Services\GoCardless;

use App\Contracts\Repositories\TransactionRepositoryInterface;
use App\Services\GoCardless\DTOs\DedupDecision;
use Illuminate\Support\Collection;

/**
 * Decides whether a mapped GoCardless transaction should be created, update an
 * existing row, or be skipped.
 *
 * The provider transaction ID is the only trustworthy identity signal: two
 * genuinely distinct purchases can share amount, date, description and partner
 * (and therefore the fingerprint), so fingerprint-based rejection is applied
 * only to rows the provider gave no ID for.
 */
class TransactionDeduplicator
{
    public function __construct(
        private readonly TransactionRepositoryInterface $transactionRepository
    ) {}

    /**
     * @param  array<string, mixed>  $mappedData  Post-validation mapped transaction data.
     * @param  bool  $hasProviderId  Whether the provider supplied a transaction ID (i.e. the
     *                               ID was not synthesised by the validator).
     * @param  Collection<int, string>  $existingTransactionIds  Transaction IDs already stored for this account.
     */
    public function decide(
        int $accountId,
        array $mappedData,
        bool $hasProviderId,
        Collection $existingTransactionIds,
        bool $updateExisting
    ): DedupDecision {
        $transactionId = $this->stringValue($mappedData['transaction_id'] ?? null);

        // 1. Same transaction ID in the same account: this is the same movement.
        if ($transactionId !== '' && $existingTransactionIds->contains($transactionId)) {
            return $updateExisting
                ? DedupDecision::update($transactionId, DedupDecision::REASON_EXISTING_TRANSACTION_ID)
                : DedupDecision::skip(DedupDecision::REASON_UPDATE_DISABLED);
        }

        // 2. A CSV import of the same movement can be adopted and enriched.
        $existingImport = $this->transactionRepository->findStrongMatchingImport($accountId, $mappedData);
        if ($existingImport !== null) {
            $targetId = $this->stringValue($existingImport->transaction_id);

            if ($targetId !== '') {
                return DedupDecision::update($targetId, DedupDecision::REASON_ADOPTED_CSV_IMPORT);
            }
        }

        // 3. Fingerprint is only a duplicate signal when there is no provider ID to
        //    tell two identical same-day movements apart.
        if (! $hasProviderId) {
            $fingerprint = $this->stringValue($mappedData['fingerprint'] ?? null);

            if ($fingerprint !== '' && $this->transactionRepository->fingerprintExists($accountId, $fingerprint)) {
                return DedupDecision::skip(DedupDecision::REASON_FINGERPRINT_DUPLICATE);
            }
        }

        // 4. Weaker CSV-import overlap: keep the row but flag it for a human.
        if ($this->transactionRepository->hasPotentialImportMatch($accountId, $mappedData)) {
            return DedupDecision::create(DedupDecision::REASON_PROBABLE_DUPLICATE, true);
        }

        return DedupDecision::create(DedupDecision::REASON_NEW);
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
