<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ExchangeRate;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExchangeRateService
{
    private const string FRANKFURTER_URL = 'https://api.frankfurter.dev';

    private const int CACHE_TTL_SECONDS = 86400; // 24h

    /**
     * Fetch and store ECB rates for a given date.
     */
    public function fetchRatesForDate(Carbon $date): int
    {
        $dateStr = $date->format('Y-m-d');

        $response = Http::timeout(15)->get(self::FRANKFURTER_URL."/{$dateStr}");

        if (! $response->successful()) {
            Log::warning('Frankfurter API request failed', [
                'date' => $dateStr,
                'status' => $response->status(),
            ]);

            return 0;
        }

        /** @var array{rates?: array<string, float>, date?: string} $data */
        $data = $response->json();
        $rates = $data['rates'] ?? [];
        $actualDate = $data['date'] ?? $dateStr;
        $count = 0;

        foreach ($rates as $currency => $rate) {
            ExchangeRate::updateOrCreate(
                [
                    'base_currency' => 'EUR',
                    'target_currency' => $currency,
                    'date' => $actualDate,
                ],
                [
                    'rate' => $rate,
                    'source' => 'ecb',
                ]
            );
            $count++;
        }

        // Invalidate cache for this date
        Cache::forget("exchange_rates:{$actualDate}");

        return $count;
    }

    /**
     * Get exchange rate with caching.
     */
    public function getRate(string $from, string $to, Carbon $date): float
    {
        if ($from === $to) {
            return 1.0;
        }

        $cacheKey = "exchange_rate:{$from}:{$to}:{$date->format('Y-m-d')}";

        /** @var float $result */
        $result = Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($from, $to, $date): float {
            $this->ensureRatesExist($date);

            $rate = ExchangeRate::getRate($from, $to, $date);

            if ($rate === null) {
                Log::warning('Exchange rate not found, defaulting to 1.0', [
                    'from' => $from,
                    'to' => $to,
                    'date' => $date->format('Y-m-d'),
                ]);

                return 1.0;
            }

            return $rate;
        });

        return (float) $result;
    }

    /**
     * Convert an amount between currencies.
     */
    public function convert(float $amount, string $from, string $to, Carbon $date): float
    {
        if ($from === $to) {
            return $amount;
        }

        $rate = $this->getRate($from, $to, $date);

        return round($amount * $rate, 2);
    }

    /**
     * Ensure rates exist for a given date (fetch if missing).
     */
    public function ensureRatesExist(Carbon $date): void
    {
        // Check if we have any rates within 7 days (handles weekends)
        $exists = ExchangeRate::where('base_currency', 'EUR')
            ->where('date', '<=', $date->format('Y-m-d'))
            ->where('date', '>=', $date->copy()->subDays(7)->format('Y-m-d'))
            ->exists();

        if (! $exists) {
            $this->fetchRatesForDate($date);
        }
    }
}
