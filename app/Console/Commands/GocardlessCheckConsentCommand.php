<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesUser;
use App\Contracts\Repositories\AccountRepositoryInterface;
use App\Contracts\Repositories\GoCardlessRequisitionRepositoryInterface;
use App\Enums\GoCardlessRequisitionStatus;
use App\Exceptions\GoCardlessConsentExpiredException;
use App\Models\Account;
use App\Models\GoCardlessRequisition;
use App\Models\User;
use App\Services\GoCardless\GoCardlessService;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the local view of GoCardless consent honest.
 *
 * A bank connection silently dies 90 days after the user authorized it. Nothing tells us —
 * the next sync just starts failing. This command runs daily and does three things:
 *
 *  1. Expires connections whose known access window has already passed.
 *  2. Stamps a one-time warning on connections about to lapse, so the UI can nag early.
 *  3. Polls GoCardless for connections whose status we have not checked recently, catching
 *     revocations (and completed authorizations) that never produced a callback.
 *
 * Every step is per-row fault isolated: one unreachable bank must not stop the rest.
 */
class GocardlessCheckConsentCommand extends Command
{
    use ResolvesUser;

    /**
     * Local statuses the user (or a newer link) has already decided — a remote poll must
     * never resurrect them, since GoCardless still reports superseded requisitions as LN.
     *
     * @var list<GoCardlessRequisitionStatus>
     */
    private const array TERMINAL_STATUSES = [
        GoCardlessRequisitionStatus::REVOKED,
        GoCardlessRequisitionStatus::REPLACED,
        GoCardlessRequisitionStatus::CANCELLED,
    ];

    private const int DEFAULT_WARNING_DAYS = 7;

    private const int DEFAULT_STALE_HOURS = 24;

    /** @var array{expired: int, warned: int, polled: int, status_changes: int, accounts_flagged: int, failures: int} */
    private array $stats = [
        'expired' => 0,
        'warned' => 0,
        'polled' => 0,
        'status_changes' => 0,
        'accounts_flagged' => 0,
        'failures' => 0,
    ];

    private bool $dryRun = false;

    /**
     * Restricts every step to one owner when --user is given; null means all users.
     */
    private ?int $targetUserId = null;

    protected $signature = 'gocardless:check-consent
        {--user= : Restrict to one user (ID or email); default is every user}
        {--limit=50 : Maximum requisitions to poll against the GoCardless API in one run}
        {--dry-run : Report what would change without writing anything}';

    protected $description = 'Expire lapsed GoCardless consents, warn about ones close to lapsing, and poll requisition status.';

    public function __construct(
        private readonly GoCardlessRequisitionRepositoryInterface $requisitionRepository,
        private readonly AccountRepositoryInterface $accountRepository,
        private readonly GoCardlessService $gocardlessService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $userOption = $this->option('user');
        if (is_string($userOption) && $userOption !== '') {
            $user = $this->resolveUser($userOption);
            if ($user === null) {
                $this->error('No user found.');

                return self::FAILURE;
            }
            $this->targetUserId = (int) $user->id;
        }

        $this->dryRun = (bool) $this->option('dry-run');
        $limit = max(1, $this->intOption('limit', 50));

        $this->expireAndWarn();
        $this->poll($limit);

        $this->info(sprintf(
            '%sexpired %d, warned %d, polled %d (%d status changes), accounts flagged %d, failures %d.',
            $this->dryRun ? '[dry-run] ' : '',
            $this->stats['expired'],
            $this->stats['warned'],
            $this->stats['polled'],
            $this->stats['status_changes'],
            $this->stats['accounts_flagged'],
            $this->stats['failures'],
        ));

        return self::SUCCESS;
    }

    /**
     * One window query covers both passes: everything expiring within the warning horizon is
     * either already past (expire) or approaching (warn).
     */
    private function expireAndWarn(): void
    {
        $warningDays = $this->configInt('consent_warning_days', self::DEFAULT_WARNING_DAYS);
        $rows = $this->requisitionRepository->getExpiringBefore(now()->addDays($warningDays));

        foreach ($rows as $row) {
            if (! $this->inScope($row)) {
                continue;
            }

            $validUntil = $this->carbonAttr($row, 'access_valid_until');
            if ($validUntil === null) {
                continue;
            }

            if ($validUntil->isPast()) {
                $this->expire($row, 'access window passed');

                continue;
            }

            if ($this->carbonAttr($row, 'expiry_warning_sent_at') !== null) {
                continue;
            }

            $this->stats['warned']++;
            $this->line(sprintf(
                'Requisition %d (%s) expires in %d day(s).',
                $this->rowId($row),
                $this->stringAttr($row, 'institution_id') ?? 'unknown institution',
                (int) $row->daysUntilExpiry(),
            ));

            if ($this->dryRun) {
                continue;
            }

            // Stamped rather than mailed: the expiry is surfaced in-app via days_until_expiry,
            // and the stamp is what keeps a daily run from re-announcing the same connection.
            $row->update(['expiry_warning_sent_at' => now()]);

            Log::info('GoCardless consent nearing expiry', [
                'requisition_row_id' => $this->rowId($row),
                'user_id' => $row->getAttribute('user_id'),
                'days_until_expiry' => $row->daysUntilExpiry(),
            ]);
        }
    }

    /**
     * Re-check requisitions whose status we have not confirmed recently.
     */
    private function poll(int $limit): void
    {
        $staleHours = $this->configInt('consent_check_stale_hours', self::DEFAULT_STALE_HOURS);
        $rows = $this->requisitionRepository->getPollable(now()->subHours($staleHours), $limit);

        foreach ($rows as $row) {
            if (! $this->inScope($row)) {
                continue;
            }

            // Pending rows never reach the expire pass (that query is linked-only), so a pending
            // row carrying a stale access window is caught here instead — and skipping the API
            // call keeps a dead connection from burning request quota every day.
            $validUntil = $this->carbonAttr($row, 'access_valid_until');
            if ($validUntil !== null && $validUntil->isPast()) {
                $this->expire($row, 'access window passed');

                continue;
            }

            $this->pollRow($row);
        }
    }

    /**
     * Poll a single requisition. Never throws: one failing user or unreachable bank must not
     * abort the run for everyone else.
     */
    private function pollRow(GoCardlessRequisition $row): void
    {
        $requisitionId = $this->stringAttr($row, 'requisition_id');
        $user = $row->user;

        if ($requisitionId === null || ! $user instanceof User) {
            return;
        }

        try {
            $remote = $this->gocardlessService->getRequisition($requisitionId, $user);
        } catch (GoCardlessConsentExpiredException) {
            $this->expire($row, 'GoCardless reported consent as expired');

            return;
        } catch (\Throwable $e) {
            $this->stats['failures']++;
            Log::warning('gocardless:check-consent could not poll requisition', [
                'requisition_row_id' => $this->rowId($row),
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            $this->warn("Requisition {$this->rowId($row)}: poll failed - {$e->getMessage()}");

            return;
        }

        $this->stats['polled']++;
        $this->applyRemoteStatus($row, $remote);
    }

    /**
     * @param  array<mixed>  $remote
     */
    private function applyRemoteStatus(GoCardlessRequisition $row, array $remote): void
    {
        $goCardlessStatus = is_string($remote['status'] ?? null) ? $remote['status'] : null;
        $local = $this->localStatus($row);

        $updates = ['last_checked_at' => now()];
        if ($goCardlessStatus !== null) {
            $updates['gocardless_status'] = $goCardlessStatus;
        }

        $mapped = null;
        if ($goCardlessStatus !== null && ! $this->isTerminal($local)) {
            $mapped = GoCardlessRequisitionStatus::fromGoCardless($goCardlessStatus);
            if ($mapped !== $local) {
                $updates['status'] = $mapped;
                $this->stats['status_changes']++;
                $this->line(sprintf(
                    'Requisition %d: %s -> %s (%s).',
                    $this->rowId($row),
                    $local->value ?? 'unknown',
                    $mapped->value,
                    $goCardlessStatus,
                ));
            }
        }

        if (! $this->dryRun) {
            $row->update($updates);
        }

        if ($mapped !== null && $mapped->needsReconnect()) {
            $this->flagAccounts($row);
        }
    }

    /**
     * Mark a connection dead and tell every account it authorized.
     */
    private function expire(GoCardlessRequisition $row, string $reason): void
    {
        if ($this->isTerminal($this->localStatus($row))) {
            return;
        }

        $this->stats['expired']++;
        $this->line("Requisition {$this->rowId($row)}: expired ({$reason}).");

        if (! $this->dryRun) {
            // gocardless_status is deliberately left alone: this conclusion is ours, drawn from
            // the stored access window, and must not masquerade as something the API reported.
            $this->requisitionRepository->updateStatus($row, GoCardlessRequisitionStatus::EXPIRED);
            $row->update(['last_checked_at' => now()]);

            Log::info('GoCardless consent expired', [
                'requisition_row_id' => $this->rowId($row),
                'user_id' => $row->getAttribute('user_id'),
                'reason' => $reason,
            ]);
        }

        $this->flagAccounts($row);
    }

    /**
     * Flag every account this requisition authorized.
     *
     * Covers both the FK (accounts relinked by the callback) and the raw GoCardless account IDs
     * on the row — connections created before the FK existed only have the latter.
     */
    private function flagAccounts(GoCardlessRequisition $row): void
    {
        $seen = [];

        foreach ($row->linkedAccounts as $account) {
            $seen[$account->id] = true;
            $this->flagAccount($account);
        }

        $userId = $this->ownerId($row);
        foreach ($this->goCardlessAccountIds($row) as $goCardlessAccountId) {
            $account = $this->accountRepository->findByGocardlessId($goCardlessAccountId, $userId);
            if (! $account instanceof Account || isset($seen[$account->id])) {
                continue;
            }
            $seen[$account->id] = true;
            $this->flagAccount($account);
        }
    }

    private function flagAccount(Account $account): void
    {
        if ((bool) $account->gocardless_needs_reconnect) {
            return;
        }

        $this->stats['accounts_flagged']++;

        if (! $this->dryRun) {
            $this->accountRepository->markNeedsReconnect($account);
        }
    }

    private function inScope(GoCardlessRequisition $row): bool
    {
        return $this->targetUserId === null || $this->ownerId($row) === $this->targetUserId;
    }

    private function ownerId(GoCardlessRequisition $row): int
    {
        $userId = $row->getAttribute('user_id');

        return is_numeric($userId) ? (int) $userId : 0;
    }

    private function isTerminal(?GoCardlessRequisitionStatus $status): bool
    {
        return $status !== null && in_array($status, self::TERMINAL_STATUSES, true);
    }

    private function localStatus(GoCardlessRequisition $row): ?GoCardlessRequisitionStatus
    {
        $status = $row->getAttribute('status');

        return $status instanceof GoCardlessRequisitionStatus ? $status : null;
    }

    /**
     * @return list<string>
     */
    private function goCardlessAccountIds(GoCardlessRequisition $row): array
    {
        $accounts = $row->getAttribute('accounts');

        return is_array($accounts) ? array_values(array_filter($accounts, 'is_string')) : [];
    }

    private function carbonAttr(GoCardlessRequisition $row, string $key): ?CarbonInterface
    {
        $value = $row->getAttribute($key);

        return $value instanceof CarbonInterface ? $value : null;
    }

    private function stringAttr(GoCardlessRequisition $row, string $key): ?string
    {
        $value = $row->getAttribute($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function rowId(GoCardlessRequisition $row): int
    {
        $key = $row->getKey();

        return is_numeric($key) ? (int) $key : 0;
    }

    private function intOption(string $name, int $default): int
    {
        $value = $this->option($name);

        return is_numeric($value) ? (int) $value : $default;
    }

    private function configInt(string $key, int $default): int
    {
        $value = config("services.gocardless.{$key}", $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
