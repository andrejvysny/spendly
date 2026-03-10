<?php

declare(strict_types=1);

namespace App\Services\GoCardless;

class BalanceResolver
{
    private const array PREFERRED_TYPES = ['closingBooked', 'interimAvailable', 'expected', 'interimBooked'];

    /**
     * Resolve the best available balance from a GoCardless balances response.
     *
     * @param  array<int, array<string, mixed>>  $balances
     */
    public static function resolve(array $balances): ?float
    {
        $byType = [];
        foreach ($balances as $balance) {
            /** @var array{balanceType: string, balanceAmount: array{amount: string|float}} $balance */
            $byType[$balance['balanceType']] = (float) $balance['balanceAmount']['amount'];
        }

        foreach (self::PREFERRED_TYPES as $type) {
            if (isset($byType[$type])) {
                return $byType[$type];
            }
        }

        // Fall back to first available balance if no preferred type found
        if ($byType !== []) {
            return reset($byType);
        }

        return null;
    }
}
