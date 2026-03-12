<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ExchangeRateService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class FetchExchangeRatesCommand extends Command
{
    protected $signature = 'exchange-rates:fetch {--date= : Date to fetch (Y-m-d, default: today)}';

    protected $description = 'Fetch ECB exchange rates from Frankfurter API';

    public function handle(ExchangeRateService $service): int
    {
        $dateStr = $this->option('date');
        $date = $dateStr ? Carbon::parse($dateStr) : Carbon::today();

        $this->info("Fetching exchange rates for {$date->format('Y-m-d')}...");

        $count = $service->fetchRatesForDate($date);

        if ($count === 0) {
            $this->warn('No rates fetched (API may be unavailable or date is a holiday).');

            return self::FAILURE;
        }

        $this->info("Fetched {$count} exchange rates.");

        return self::SUCCESS;
    }
}
