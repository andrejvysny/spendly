<?php

declare(strict_types=1);

namespace App\Services\Transfers;

final class Iban
{
    /**
     * Normalize an IBAN for comparison: uppercase, all whitespace stripped.
     * Returns null for null/blank input.
     */
    public static function normalize(?string $iban): ?string
    {
        if ($iban === null || trim($iban) === '') {
            return null;
        }

        return strtoupper(trim((string) preg_replace('/\s+/', '', $iban)));
    }
}
