<?php

namespace App\Jobs;

use App\Contracts\RuleEngine\RuleEngineInterface;
use App\Models\RuleEngine\Rule;
use App\Models\RuleEngine\Trigger;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessRulesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     */
    public int $maxExceptions = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 300; // 5 minutes

    private User $user;

    private array $ruleIds;

    private ?Carbon $startDate;

    private ?Carbon $endDate;

    private array $transactionIds;

    private bool $dryRun;

    /**
     * Create a new job instance.
     */
    public function __construct(
        User $user,
        array $ruleIds = [],
        ?Carbon $startDate = null,
        ?Carbon $endDate = null,
        array $transactionIds = [],
        bool $dryRun = false
    ) {
        $this->user = $user;
        $this->ruleIds = $ruleIds;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->transactionIds = $transactionIds;
        $this->dryRun = $dryRun;
    }

    /**
     * Execute the job.
     */
    public function handle(RuleEngineInterface $ruleEngine): void
    {
        Log::info('Starting rule processing job', [
            'user_id' => $this->user->id,
            'rule_ids' => $this->ruleIds,
            'start_date' => $this->startDate?->toDateString(),
            'end_date' => $this->endDate?->toDateString(),
            'transaction_ids' => count($this->transactionIds),
            'dry_run' => $this->dryRun,
        ]);

        $ruleEngine
            ->setUser($this->user)
            ->setDryRun($this->dryRun)
            ->clearExecutionResults();

        try {
            // Process specific transactions if provided
            if (! empty($this->transactionIds)) {
                $transactions = $this->user->transactions()
                    ->whereIn('id', $this->transactionIds)
                    ->get();

                if (! empty($this->ruleIds)) {
                    $ruleEngine->processTransactionsForRules($transactions, collect($this->ruleIds));
                } else {
                    $ruleEngine->processTransactions($transactions, Trigger::MANUAL);
                }
            }
            // Process date range if provided
            elseif ($this->startDate && $this->endDate) {
                $ruleEngine->processDateRange(
                    $this->startDate,
                    $this->endDate,
                    ! empty($this->ruleIds) ? $this->ruleIds : null
                );
            }
            // Process all transactions for specific rules
            elseif (! empty($this->ruleIds)) {
                $transactions = $this->user->transactions()->get();
                $ruleEngine->processTransactionsForRules($transactions, collect($this->ruleIds));
            }

            $results = $ruleEngine->getExecutionResults();

            Log::info('Rule processing job completed', [
                'user_id' => $this->user->id,
                'total_matches' => count(array_filter($results, fn ($r) => ! empty($r['actions']))),
                'total_processed' => count($results),
            ]);

            // Optionally notify user of completion
            // $this->user->notify(new RuleProcessingCompleted($results));

        } catch (\Exception $e) {
            Log::error('Rule processing job failed', [
                'user_id' => $this->user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Determine the time at which the job should timeout.
     */
    public function retryUntil(): \DateTime
    {
        return now()->addHours(2);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Rule processing job permanently failed', [
            'user_id' => $this->user->id,
            'error' => $exception->getMessage(),
        ]);

        // Optionally notify user of failure
        // $this->user->notify(new RuleProcessingFailed($exception->getMessage()));
    }
}
