<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Models\GoCardlessSyncFailure;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

interface GoCardlessSyncFailureRepositoryInterface
{
    /**
     * Create a sync failure record.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): GoCardlessSyncFailure;

    /**
     * Get unresolved failures for an account.
     *
     * @return Collection<int, GoCardlessSyncFailure>
     */
    public function getUnresolvedByAccount(int $accountId): Collection;

    /**
     * Mark a failure as resolved.
     */
    public function markResolved(int $id, string $resolution): void;

    /**
     * Park a row that has spent its retries as terminal, so it is distinguishable from a fresh one.
     */
    public function markExhausted(int $id): void;

    /**
     * Delete resolved rows past their retention, dropping the stored bank payloads with them.
     *
     * @return int Rows deleted.
     */
    public function prune(CarbonInterface $resolvedBefore, CarbonInterface $exhaustedBefore): int;
}
