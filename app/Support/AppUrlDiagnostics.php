<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Single source of truth for judging whether APP_URL is safe to build GoCardless bank-redirect
 * URLs from in production. Shared by `spendly:check-config` and the bank-data settings page so
 * the two never drift apart.
 *
 * Uses config('app.env') rather than app()->environment(): the latter is a container binding
 * fixed at bootstrap and cannot be faked per-test via config(), the former can.
 */
final class AppUrlDiagnostics
{
    private const string MESSAGE = 'Bank redirect URLs are built from APP_URL; GoCardless connections will fail.';

    /**
     * Null outside production (a local/dev/staging APP_URL is not worth flagging) or when the
     * production URL is fine. Non-null with a user-facing message otherwise.
     */
    public function warning(): ?string
    {
        if (config('app.env') !== 'production') {
            return null;
        }

        return $this->isUnsuitableForProduction(config('app.url')) ? self::MESSAGE : null;
    }

    /**
     * Only an https:// URL on a real (non-IP, non-localhost) domain passes.
     */
    private function isUnsuitableForProduction(mixed $url): bool
    {
        if (! is_string($url) || trim($url) === '') {
            return true;
        }

        $url = trim($url);

        if (! str_starts_with($url, 'https://')) {
            return true;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return true;
        }

        if (in_array($host, ['localhost', '127.0.0.1'], true)) {
            return true;
        }

        return filter_var($host, FILTER_VALIDATE_IP) !== false;
    }
}
