<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Best-effort scrub of secrets from raw text (HTTP response bodies, log context) before it is
 * logged or surfaced to a client. Pattern-based, not exhaustive — callers must still treat the
 * source as sensitive rather than relying on this as a guarantee.
 */
final class SensitiveDataRedactor
{
    /**
     * JSON object keys whose values are replaced wholesale.
     *
     * @var list<string>
     */
    private const SENSITIVE_KEYS = [
        'secret_id',
        'secret_key',
        'access',
        'refresh',
        'token',
        'ssn',
        'iban',
        'authorization',
    ];

    public static function redact(string $text, int $limit = 500): string
    {
        $keys = implode('|', array_map(
            static fn (string $key): string => preg_quote($key, '/'),
            self::SENSITIVE_KEYS
        ));

        $redacted = preg_replace('/"('.$keys.')"\s*:\s*"[^"]*"/i', '"$1":"[redacted]"', $text);
        $redacted = is_string($redacted) ? $redacted : $text;

        $redacted = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $redacted);
        $redacted = is_string($redacted) ? $redacted : $text;

        return Str::limit($redacted, $limit);
    }
}
