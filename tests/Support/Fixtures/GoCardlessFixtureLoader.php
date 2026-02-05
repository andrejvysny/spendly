<?php

declare(strict_types=1);

namespace Tests\Support\Fixtures;

use Illuminate\Support\Facades\File;

final class GoCardlessFixtureLoader
{
    private static function fixturePath(string ...$parts): string
    {
        return base_path(implode(DIRECTORY_SEPARATOR, array_merge(['gocardless_bank_account_data'], $parts)));
    }

    /**
     * Load Revolut booked transactions from fixture JSON.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function loadRevolutTransactions(string $accountId = 'LT683250013083708433'): array
    {
        $path = self::fixturePath('Revolut', $accountId . '_transactions_booked.json');
        if (! File::exists($path)) {
            return [];
        }
        $json = File::get($path);
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $data['transactions']['booked'] ?? [];
    }

    /**
     * Load Revolut USD booked transactions when available.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function loadRevolutTransactionsUsd(string $accountId = 'LT683250013083708433'): array
    {
        $path = self::fixturePath('Revolut', $accountId . '_transactions_booked_USD.json');
        if (! File::exists($path)) {
            return [];
        }
        $json = File::get($path);
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $data['transactions']['booked'] ?? [];
    }

    /**
     * Load SLSP booked transactions from fixture JSON.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function loadSlspTransactions(string $accountId = 'SK6809000000005183172536'): array
    {
        $path = self::fixturePath('SLSP', $accountId . '_transactions_booked.json');
        if (! File::exists($path)) {
            return [];
        }
        $json = File::get($path);
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $data['transactions']['booked'] ?? [];
    }

    /**
     * Load Revolut account details from fixture JSON.
     *
     * @return array<string, mixed>
     */
    public static function loadRevolutDetails(string $accountId): array
    {
        $path = self::fixturePath('Revolut', $accountId . '_details.json');
        if (! File::exists($path)) {
            return [];
        }
        $json = File::get($path);
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $data['account'] ?? [];
    }

    /**
     * Load SLSP account details from fixture JSON.
     *
     * @return array<string, mixed>
     */
    public static function loadSlspDetails(string $accountId): array
    {
        $path = self::fixturePath('SLSP', $accountId . '_details.json');
        if (! File::exists($path)) {
            return [];
        }
        $json = File::get($path);
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $data['account'] ?? [];
    }

    /**
     * Check whether fixture directory and expected files exist.
     */
    public static function fixturesAvailable(): bool
    {
        $base = base_path('gocardless_bank_account_data');

        return File::isDirectory($base)
            && File::exists($base . DIRECTORY_SEPARATOR . 'Revolut')
            && File::exists($base . DIRECTORY_SEPARATOR . 'SLSP');
    }
}
