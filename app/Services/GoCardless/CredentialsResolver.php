<?php

declare(strict_types=1);

namespace App\Services\GoCardless;

use App\Enums\GoCardlessCredentialSource;
use App\Exceptions\MissingGoCardlessCredentialsException;
use App\Models\User;
use App\Services\GoCardless\DTOs\GoCardlessCredentials;

/**
 * Decides which GoCardless secret pair a user's bank sync runs on.
 *
 * Two sources, in strict precedence order:
 *   1. the user's own stored pair (a personal override),
 *   2. the instance-level pair from GOCARDLESS_SECRET_ID / GOCARDLESS_SECRET_KEY.
 *
 * A half-filled personal override is always an error and never silently falls through to the
 * instance pair: quietly syncing a user's bank under the administrator's credentials because
 * they mistyped one field would be a surprise nobody can debug from the UI.
 */
class CredentialsResolver
{
    /**
     * @throws MissingGoCardlessCredentialsException When no complete pair is available.
     */
    public function resolve(User $user): GoCardlessCredentials
    {
        $secretId = $this->nonEmptyString($user->gocardless_secret_id);
        $secretKey = $this->nonEmptyString($user->gocardless_secret_key);

        if ($secretId !== null && $secretKey !== null) {
            return new GoCardlessCredentials($secretId, $secretKey, GoCardlessCredentialSource::USER);
        }

        if ($secretId !== null || $secretKey !== null) {
            throw MissingGoCardlessCredentialsException::partial();
        }

        return $this->instanceCredentials()
            ?? throw new MissingGoCardlessCredentialsException;
    }

    /**
     * Same resolution, but a missing or half-filled pair yields null instead of throwing.
     *
     * Intended for read-only callers (settings page, UI gating) that need to describe the current
     * state rather than act on it. Partial overrides collapse to null here too: from a gating
     * point of view "cannot sync" is the honest answer, and resolve() still reports why.
     */
    public function tryResolve(User $user): ?GoCardlessCredentials
    {
        try {
            return $this->resolve($user);
        } catch (MissingGoCardlessCredentialsException) {
            return null;
        }
    }

    public function hasInstanceCredentials(): bool
    {
        return $this->instanceCredentials() !== null;
    }

    /**
     * Whether the user has a complete personal override, which outranks the instance pair.
     */
    public function hasUserOverride(User $user): bool
    {
        return $this->nonEmptyString($user->gocardless_secret_id) !== null
            && $this->nonEmptyString($user->gocardless_secret_key) !== null;
    }

    /**
     * The source that would be used for this user, or null when bank sync is not configured.
     */
    public function sourceFor(User $user): ?GoCardlessCredentialSource
    {
        return $this->tryResolve($user)?->source;
    }

    private function instanceCredentials(): ?GoCardlessCredentials
    {
        $secretId = $this->nonEmptyString(config('services.gocardless.secret_id'));
        $secretKey = $this->nonEmptyString(config('services.gocardless.secret_key'));

        if ($secretId === null || $secretKey === null) {
            return null;
        }

        return new GoCardlessCredentials($secretId, $secretKey, GoCardlessCredentialSource::INSTANCE);
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
