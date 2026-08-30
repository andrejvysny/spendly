<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * The bank has withdrawn access for a requisition: the 90-day End User Agreement
 * lapsed, or the account was suspended by the institution. Recoverable only by the
 * user re-authorizing with their bank, never by retrying the same call.
 *
 * Distinct from GoCardlessApiException so the sync path can flag the account for
 * reconnect instead of treating it as a transient API failure.
 *
 * The message is deliberately generic and safe to surface: the GoCardless body that
 * identified this condition is logged (redacted, under a correlation id) by the client
 * and never carried here.
 */
final class GoCardlessConsentExpiredException extends \RuntimeException
{
    public function __construct(
        public readonly ?string $requisitionId = null,
        public readonly ?string $accountId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct('GoCardless consent expired or access suspended', 0, $previous);
    }
}
