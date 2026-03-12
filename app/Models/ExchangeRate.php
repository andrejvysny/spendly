<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    protected $fillable = [
        'base_currency',
        'target_currency',
        'rate',
        'date',
        'source',
    ];

    protected $casts = [
        'rate' => 'decimal:8',
        'date' => 'date',
    ];

    /**
     * Get the exchange rate for a given currency pair and date.
     */
    public static function getRate(string $from, string $to, Carbon $date): ?float
    {
        if ($from === $to) {
            return 1.0;
        }

        // Try direct EUR->target lookup (ECB rates are EUR-based)
        if ($from === 'EUR') {
            return self::findRateWithWalkback($from, $to, $date);
        }

        if ($to === 'EUR') {
            $rate = self::findRateWithWalkback('EUR', $from, $date);

            return $rate !== null ? 1.0 / $rate : null;
        }

        // Cross-rate via EUR: from->EUR->to
        $fromToEur = self::findRateWithWalkback('EUR', $from, $date);
        $eurToTarget = self::findRateWithWalkback('EUR', $to, $date);

        if ($fromToEur === null || $eurToTarget === null) {
            return null;
        }

        return $eurToTarget / $fromToEur;
    }

    /**
     * Find rate with 7-day walkback for weekends/holidays.
     */
    private static function findRateWithWalkback(string $base, string $target, Carbon $date): ?float
    {
        /** @var string|null $rate */
        $rate = self::where('base_currency', $base)
            ->where('target_currency', $target)
            ->where('date', '<=', $date->format('Y-m-d'))
            ->where('date', '>=', $date->copy()->subDays(7)->format('Y-m-d'))
            ->orderBy('date', 'desc')
            ->value('rate');

        return $rate !== null ? (float) $rate : null;
    }
}
