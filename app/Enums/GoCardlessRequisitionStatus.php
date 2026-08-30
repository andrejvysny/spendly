<?php

declare(strict_types=1);

namespace App\Enums;

enum GoCardlessRequisitionStatus: string
{
    case PENDING = 'pending';
    case LINKED = 'linked';
    case EXPIRED = 'expired';
    case SUSPENDED = 'suspended';
    case REJECTED = 'rejected';
    case ERROR = 'error';
    case CANCELLED = 'cancelled';
    case REPLACED = 'replaced';
    case REVOKED = 'revoked';

    /**
     * Map a raw GoCardless requisition status code (the `StatusEnum` schema in
     * docs/gocardless.swagger.json: CR, ID, LN, RJ, ER, SU, EX, GC, UA, GA, SA)
     * to our local status. Unknown/future codes default to PENDING; callers
     * should persist the raw code separately (gocardless_status column) so
     * nothing is silently lost.
     */
    public static function fromGoCardless(string $code): self
    {
        return match ($code) {
            'CR', 'ID', 'GC', 'UA', 'SA', 'GA' => self::PENDING,
            'LN' => self::LINKED,
            'EX' => self::EXPIRED,
            'SU' => self::SUSPENDED,
            'RJ' => self::REJECTED,
            'ER' => self::ERROR,
            default => self::PENDING,
        };
    }

    /**
     * Whether the requisition is currently linked and usable for syncing.
     */
    public function isActive(): bool
    {
        return $this === self::LINKED;
    }

    /**
     * Whether the user needs to re-authorize with the bank.
     */
    public function needsReconnect(): bool
    {
        return in_array($this, [self::EXPIRED, self::SUSPENDED, self::REJECTED, self::ERROR], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending authorization',
            self::LINKED => 'Linked',
            self::EXPIRED => 'Expired',
            self::SUSPENDED => 'Suspended',
            self::REJECTED => 'Rejected',
            self::ERROR => 'Error',
            self::CANCELLED => 'Cancelled',
            self::REPLACED => 'Replaced',
            self::REVOKED => 'Revoked',
        };
    }
}
