<?php

declare(strict_types=1);

namespace App\Services\GoCardless\DTOs;

/**
 * Outcome of the deduplication decision for a single mapped GoCardless transaction.
 */
final readonly class DedupDecision
{
    public const string ACTION_CREATE = 'create';

    public const string ACTION_UPDATE = 'update';

    public const string ACTION_SKIP = 'skip';

    /** Row has no counterpart anywhere: insert it. */
    public const string REASON_NEW = 'new';

    /** The provider transaction ID already exists in this account: refresh that row. */
    public const string REASON_EXISTING_TRANSACTION_ID = 'existing_transaction_id';

    /** A CSV-imported row is the same movement: enrich it with provider data. */
    public const string REASON_ADOPTED_CSV_IMPORT = 'adopted_csv_import';

    /** Looks like a CSV import of the same movement, but not confidently enough to merge. */
    public const string REASON_PROBABLE_DUPLICATE = 'probable_duplicate';

    /** No provider ID and an identical fingerprint already exists in this account. */
    public const string REASON_FINGERPRINT_DUPLICATE = 'fingerprint_duplicate';

    /** Row exists and the caller asked not to update existing rows. */
    public const string REASON_UPDATE_DISABLED = 'update_disabled';

    /** The same provider transaction ID appears twice inside one sync batch. */
    public const string REASON_DUPLICATE_IN_BATCH = 'duplicate_in_batch';

    /** Two rows in one batch resolved onto the same update target. */
    public const string REASON_DUPLICATE_UPDATE_TARGET = 'duplicate_update_target';

    private function __construct(
        public string $action,
        public string $reason,
        public ?string $targetTransactionId,
        public bool $needsReview,
    ) {}

    public static function create(string $reason, bool $needsReview = false): self
    {
        return new self(self::ACTION_CREATE, $reason, null, $needsReview);
    }

    public static function update(string $targetTransactionId, string $reason): self
    {
        return new self(self::ACTION_UPDATE, $reason, $targetTransactionId, false);
    }

    public static function skip(string $reason): self
    {
        return new self(self::ACTION_SKIP, $reason, null, false);
    }

    public function isCreate(): bool
    {
        return $this->action === self::ACTION_CREATE;
    }

    public function isUpdate(): bool
    {
        return $this->action === self::ACTION_UPDATE;
    }

    public function isSkip(): bool
    {
        return $this->action === self::ACTION_SKIP;
    }
}
