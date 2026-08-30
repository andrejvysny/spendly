<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesUser;
use App\Contracts\Repositories\AccountRepositoryInterface;
use App\Contracts\Repositories\GoCardlessRequisitionRepositoryInterface;
use App\Models\Account;
use App\Models\GoCardlessRequisition;
use App\Models\User;
use App\Services\GoCardless\CredentialsResolver;
use App\Services\GoCardless\GoCardlessService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * One-shot repair for installs that connected banks before requisitions were stored locally.
 *
 * Mirrors every requisition GoCardless still knows about into the local table and points
 * already-imported accounts at the row that authorized them, so the consent lifecycle
 * (expiry warnings, reconnect) has something to work with.
 *
 * Deliberately not scheduled: it is a full remote list per user, and the normal UI path
 * (`GET /requisitions?refresh=1`) already reconciles on demand.
 */
class GocardlessBackfillRequisitionsCommand extends Command
{
    use ResolvesUser;

    protected $signature = 'gocardless:backfill-requisitions
        {--user= : User ID or email (default: first user)}
        {--all : Backfill every user with GoCardless credentials or linked accounts}
        {--dry-run : Report what would change without writing anything}';

    protected $description = 'Import existing GoCardless requisitions into the local table and link them to imported accounts.';

    private bool $dryRun = false;

    /** @var array{users: int, requisitions: int, accounts_linked: int, failures: int} */
    private array $stats = [
        'users' => 0,
        'requisitions' => 0,
        'accounts_linked' => 0,
        'failures' => 0,
    ];

    public function __construct(
        private readonly GoCardlessRequisitionRepositoryInterface $requisitionRepository,
        private readonly AccountRepositoryInterface $accountRepository,
        private readonly GoCardlessService $gocardlessService,
        private readonly CredentialsResolver $credentialsResolver,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->dryRun = (bool) $this->option('dry-run');

        if ($this->option('all')) {
            foreach ($this->candidateUsers() as $user) {
                $this->backfillUser($user);
            }

            return $this->summary();
        }

        $userOption = $this->option('user');
        $user = $this->resolveUser(is_string($userOption) ? $userOption : null);
        if ($user === null) {
            $this->error('No user found.');

            return self::FAILURE;
        }

        if (! is_string($userOption) || $userOption === '') {
            $this->info("No --user given; defaulting to first user (id={$user->id}). Use --user= or --all.");
        }

        $this->backfillUser($user);

        return $this->summary();
    }

    /**
     * Users worth asking GoCardless about: they either have a resolvable credential pair
     * (their own, or the instance's) or already have accounts that came from a bank link.
     *
     * A raw `whereNotNull('gocardless_secret_id')` misses users relying entirely on
     * instance-level credentials, so resolvability is checked through CredentialsResolver
     * rather than the column directly.
     *
     * @return iterable<int, User>
     */
    private function candidateUsers(): iterable
    {
        return User::query()
            ->orderBy('id')
            ->cursor()
            ->filter(fn (User $user): bool => $this->credentialsResolver->tryResolve($user) !== null
                || $user->accounts()->where('is_gocardless_synced', true)->exists());
    }

    /**
     * Mirror one user's remote requisitions locally. A failure here is reported and skipped:
     * an expired credential for one user must not abandon the rest of the run.
     */
    private function backfillUser(User $user): void
    {
        $this->stats['users']++;

        try {
            $remote = $this->gocardlessService->getRequisitionsList($user);
        } catch (\Throwable $e) {
            $this->stats['failures']++;
            Log::warning('gocardless:backfill-requisitions could not list requisitions', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            $this->warn("User {$user->id}: failed to list requisitions - {$e->getMessage()}");

            return;
        }

        $results = $remote['results'];
        if ($results === []) {
            $this->line("User {$user->id}: no remote requisitions.");

            return;
        }

        foreach ($results as $payload) {
            $requisitionId = $payload['id'] ?? null;
            if (! is_string($requisitionId) || $requisitionId === '') {
                continue;
            }

            $this->stats['requisitions']++;

            if ($this->dryRun) {
                $this->line("User {$user->id}: would import requisition {$requisitionId}.");

                continue;
            }

            $row = $this->requisitionRepository->upsertFromRemote((int) $user->id, $payload);
            $this->linkAccounts($row, (int) $user->id);
        }
    }

    /**
     * Adopt accounts that were imported before requisitions were tracked.
     *
     * Only ever fills an empty FK — an account already pointing at a requisition was linked by
     * the callback, which knows more than a bulk remote listing does.
     */
    private function linkAccounts(GoCardlessRequisition $row, int $userId): void
    {
        $accounts = $row->getAttribute('accounts');
        if (! is_array($accounts)) {
            return;
        }

        foreach (array_filter($accounts, 'is_string') as $goCardlessAccountId) {
            $account = $this->accountRepository->findByGocardlessId($goCardlessAccountId, $userId);
            if (! $account instanceof Account || $account->gocardless_requisition_id !== null) {
                continue;
            }

            $rowKey = $row->getKey();
            $account->gocardless_requisition_id = is_numeric($rowKey) ? (int) $rowKey : null;
            $account->save();

            $this->stats['accounts_linked']++;
        }
    }

    private function summary(): int
    {
        $this->info(sprintf(
            '%s%d user(s), %d requisition(s), %d account(s) linked, %d failure(s).',
            $this->dryRun ? '[dry-run] ' : '',
            $this->stats['users'],
            $this->stats['requisitions'],
            $this->stats['accounts_linked'],
            $this->stats['failures'],
        ));

        return self::SUCCESS;
    }
}
