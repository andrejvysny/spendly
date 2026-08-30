<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\Repositories\GoCardlessSyncFailureRepositoryInterface;
use Illuminate\Console\Command;

/**
 * Drops resolved sync-failure records once they stop being useful.
 *
 * Every row holds the provider's full transaction payload — amounts, IBANs, counterparty names.
 * Keeping those forever for a self-hosted finance app is a liability with no upside once the
 * transaction they describe has been imported, so this is the retention half of the encryption
 * change that went in alongside it.
 *
 * Two retentions because the rows mean different things: a resolved row describes data that made
 * it in and is now redundant, while an exhausted row is the only record of data that never did.
 */
class PruneGoCardlessSyncFailuresCommand extends Command
{
    private const int DEFAULT_RESOLVED_DAYS = 30;

    private const int DEFAULT_EXHAUSTED_DAYS = 90;

    protected $signature = 'gocardless:prune-failures
        {--resolved-days= : Retention for successfully resolved failures (default: 30)}
        {--exhausted-days= : Retention for failures that never imported (default: 90)}
        {--dry-run : Report what would be deleted without deleting it}';

    protected $description = 'Delete old GoCardless sync-failure records and the bank payloads they store.';

    public function handle(GoCardlessSyncFailureRepositoryInterface $failures): int
    {
        $resolvedDays = $this->days('resolved-days', self::DEFAULT_RESOLVED_DAYS);
        $exhaustedDays = $this->days('exhausted-days', self::DEFAULT_EXHAUSTED_DAYS);

        $resolvedBefore = now()->subDays($resolvedDays);
        $exhaustedBefore = now()->subDays($exhaustedDays);

        if ($this->option('dry-run')) {
            $this->info(sprintf(
                '[dry-run] would delete resolved failures before %s and exhausted ones before %s.',
                $resolvedBefore->toDateString(),
                $exhaustedBefore->toDateString(),
            ));

            return self::SUCCESS;
        }

        $deleted = $failures->prune($resolvedBefore, $exhaustedBefore);

        $this->info("Pruned {$deleted} GoCardless sync failure record(s).");

        return self::SUCCESS;
    }

    private function days(string $option, int $default): int
    {
        $value = $this->option($option);

        return is_numeric($value) ? max(1, (int) $value) : $default;
    }
}
