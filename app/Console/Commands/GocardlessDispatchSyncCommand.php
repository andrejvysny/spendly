<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesUser;
use App\Contracts\Repositories\AccountRepositoryInterface;
use App\Jobs\SyncGoCardlessAccountJob;
use App\Models\Account;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;

/**
 * Books a sync for every GoCardless-linked account that is due for one.
 *
 * This is the scheduled entry point, and it replaces `gocardless:sync-all --all`, which walked
 * every user's accounts inline inside the scheduler process: one slow bank stalled the run, one
 * exception ended it, and nothing about it was restartable. Here the command only decides *what*
 * should sync and hands each account to the queue.
 *
 * Two things make that safe to run every four hours across a whole installation:
 *
 * - Accounts that synced recently are skipped, so quota is spent on accounts that might have
 *   changed rather than on re-asking the same question.
 * - Dispatches are staggered, so a hundred accounts do not arrive at the bank in the same second.
 *   The delay is capped so the tail of a large installation still runs within the scheduling window.
 */
class GocardlessDispatchSyncCommand extends Command
{
    use ResolvesUser;

    private const int DEFAULT_MIN_INTERVAL_HOURS = 8;

    private const int DEFAULT_STAGGER_SECONDS = 20;

    /**
     * Ceiling on the stagger delay. Beyond this the queued job would still be waiting when the
     * next four-hourly run comes around, and the unique lock would silently swallow that run.
     */
    private const int MAX_STAGGER_DELAY_SECONDS = 1800;

    protected $signature = 'gocardless:dispatch-sync
        {--user= : Restrict to one user (ID or email); default is every user}
        {--account= : Restrict to a single local account ID}
        {--min-interval-hours= : Skip accounts synced within this many hours (default: config)}
        {--dry-run : Report what would be queued without dispatching anything}';

    protected $description = 'Queue a per-account GoCardless sync job for every account that is due.';

    /** @var array<string, int> */
    private array $skipped = [
        'needs_reconnect' => 0,
        'cooldown' => 0,
        'recently_synced' => 0,
        'in_progress' => 0,
    ];

    public function __construct(
        private readonly AccountRepositoryInterface $accountRepository,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $accounts = $this->resolveAccounts();
        if ($accounts === null) {
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $minIntervalHours = $this->minIntervalHours();
        $stagger = $this->configInt('dispatch_stagger_seconds', self::DEFAULT_STAGGER_SECONDS);

        $dispatched = 0;

        foreach ($accounts as $account) {
            $skip = $this->skipReason($account, $minIntervalHours);
            if ($skip !== null) {
                $this->skipped[$skip]++;

                continue;
            }

            if ($dryRun) {
                $this->line("Would queue account {$account->id}.");
                $dispatched++;

                continue;
            }

            $delaySeconds = min($dispatched * $stagger, self::MAX_STAGGER_DELAY_SECONDS);

            SyncGoCardlessAccountJob::dispatch($account->id, $account->getUserId())
                ->delay(now()->addSeconds($delaySeconds));

            $this->accountRepository->markSyncQueued($account);
            $dispatched++;
        }

        $this->info(sprintf(
            '%sdispatched %d, skipped %d (needs_reconnect %d, cooldown %d, recently_synced %d, in_progress %d).',
            $dryRun ? '[dry-run] ' : '',
            $dispatched,
            array_sum($this->skipped),
            $this->skipped['needs_reconnect'],
            $this->skipped['cooldown'],
            $this->skipped['recently_synced'],
            $this->skipped['in_progress'],
        ));

        return self::SUCCESS;
    }

    /**
     * Why this account is not due, or null if it is.
     */
    private function skipReason(Account $account, int $minIntervalHours): ?string
    {
        if ((bool) $account->gocardless_needs_reconnect) {
            return 'needs_reconnect';
        }

        $retryAfter = $this->carbonAttr($account, 'gocardless_sync_retry_after');
        if ($retryAfter !== null && $retryAfter->isFuture()) {
            return 'cooldown';
        }

        // A run whose worker died never reported back, so the row still claims to be in progress.
        // Reaped before the in-progress check, otherwise the account is skipped here forever.
        $this->accountRepository->reapStaleSync($account);

        // Uniqueness would collapse a duplicate anyway, but skipping keeps the stagger honest:
        // a job that is never pushed must not consume a delay slot.
        if (in_array($account->gocardless_sync_status, Account::SYNC_STATUSES_IN_PROGRESS, true)) {
            return 'in_progress';
        }

        $lastSynced = $this->carbonAttr($account, 'gocardless_last_synced_at');
        if ($minIntervalHours > 0 && $lastSynced !== null && $lastSynced->gt(now()->subHours($minIntervalHours))) {
            return 'recently_synced';
        }

        return null;
    }

    /**
     * @return iterable<int, Account>|null Null when an option named something that does not exist.
     */
    private function resolveAccounts(): ?iterable
    {
        $userOption = $this->option('user');
        $userId = null;

        if (is_string($userOption) && $userOption !== '') {
            $user = $this->resolveUser($userOption);
            if ($user === null) {
                $this->error('No user found.');

                return null;
            }
            $userId = (int) $user->id;
        }

        $accountOption = $this->option('account');
        if (is_string($accountOption) && $accountOption !== '') {
            if (! is_numeric($accountOption)) {
                $this->error('Option --account= must be a numeric account ID.');

                return null;
            }

            $accountId = (int) $accountOption;
            $account = $userId !== null
                ? $this->accountRepository->findByIdForUser($accountId, $userId)
                : Account::query()->where('is_gocardless_synced', true)->find($accountId);

            if (! $account instanceof Account || ! $account->is_gocardless_synced) {
                $this->error("No GoCardless-linked account {$accountId} found.");

                return null;
            }

            return [$account];
        }

        return $userId !== null
            ? $this->accountRepository->getGocardlessSyncedAccounts($userId)
            : $this->accountRepository->getAllGocardlessSyncedAccounts();
    }

    private function minIntervalHours(): int
    {
        $option = $this->option('min-interval-hours');

        if (is_numeric($option)) {
            return max(0, (int) $option);
        }

        return $this->configInt('min_sync_interval_hours', self::DEFAULT_MIN_INTERVAL_HOURS);
    }

    private function carbonAttr(Account $account, string $key): ?CarbonInterface
    {
        $value = $account->getAttribute($key);

        return $value instanceof CarbonInterface ? $value : null;
    }

    private function configInt(string $key, int $default): int
    {
        $value = config("services.gocardless.{$key}", $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
