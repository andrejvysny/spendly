<?php

declare(strict_types=1);

namespace App\Services\GoCardless\DTOs;

use App\Enums\GoCardlessCredentialSource;

/**
 * A resolved GoCardless secret pair plus where it came from.
 *
 * Immutable on purpose: the pair is resolved once per client build and the tokens minted
 * from it are bound to its fingerprint, so it must not drift underneath the TokenManager.
 */
final readonly class GoCardlessCredentials
{
    public function __construct(
        public string $secretId,
        public string $secretKey,
        public GoCardlessCredentialSource $source,
    ) {}

    /**
     * Stable identifier for the credential pair, safe to persist next to the tokens it minted.
     *
     * Only the secret id is hashed: it changes whenever the pair is rotated (GoCardless issues
     * both halves together), and keeping the secret key out of any stored digest means a leaked
     * database column offers nothing to brute-force the key with.
     */
    public function fingerprint(): string
    {
        return hash('sha256', $this->secretId);
    }
}
