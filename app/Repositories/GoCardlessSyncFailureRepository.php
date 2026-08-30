<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\GoCardlessSyncFailureRepositoryInterface;
use App\Models\GoCardlessSyncFailure;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class GoCardlessSyncFailureRepository extends BaseRepository implements GoCardlessSyncFailureRepositoryInterface
{
    public function __construct(GoCardlessSyncFailure $model)
    {
        parent::__construct($model);
    }

    /**
     * Record a failure, collapsing repeats of the same provider row onto one record.
     *
     * A deterministically bad payload fails on every sync. Inserting each time turned one broken
     * transaction into unbounded table growth — and gocardless:retry-failures, which replays stored
     * payloads through the same pipeline, compounded it every 30 minutes. Rows with no provider id
     * cannot be recognised as repeats, so those still append.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): GoCardlessSyncFailure
    {
        $externalId = $data['external_transaction_id'] ?? null;

        if (is_string($externalId) && $externalId !== '') {
            $existing = $this->model->newQuery()
                ->where('account_id', $data['account_id'] ?? null)
                ->where('external_transaction_id', $externalId)
                ->first();

            if ($existing instanceof GoCardlessSyncFailure) {
                // The row is still failing, so it is not resolved any more; keep the retry history.
                $existing->fill(array_merge($data, [
                    'resolved_at' => null,
                    'resolution' => null,
                ]))->save();

                return $existing;
            }
        }

        $model = $this->model->create($data);

        return $model instanceof GoCardlessSyncFailure ? $model : $this->model->find($model->getKey());
    }

    /**
     * Park a row that has spent its retries, so it stops being indistinguishable from a fresh one.
     */
    public function markExhausted(int $id): void
    {
        $this->model->where('id', $id)->update([
            'resolved_at' => now(),
            'resolution' => GoCardlessSyncFailure::RESOLUTION_EXHAUSTED,
        ]);
    }

    /**
     * Delete resolved rows older than the given cut-offs, dropping the stored bank payloads with
     * them. Exhausted rows are kept longer because they are the only record of data that never
     * made it in.
     */
    public function prune(CarbonInterface $resolvedBefore, CarbonInterface $exhaustedBefore): int
    {
        $resolved = $this->model->newQuery()
            ->whereNotNull('resolved_at')
            ->where('resolution', '!=', GoCardlessSyncFailure::RESOLUTION_EXHAUSTED)
            ->where('resolved_at', '<', $resolvedBefore)
            ->delete();

        $exhausted = $this->model->newQuery()
            ->where('resolution', GoCardlessSyncFailure::RESOLUTION_EXHAUSTED)
            ->where('resolved_at', '<', $exhaustedBefore)
            ->delete();

        return (is_int($resolved) ? $resolved : 0) + (is_int($exhausted) ? $exhausted : 0);
    }

    /**
     * @return Collection<int, GoCardlessSyncFailure>
     */
    public function getUnresolvedByAccount(int $accountId): Collection
    {
        return $this->model->where('account_id', $accountId)
            ->whereNull('resolved_at')
            ->orderBy('created_at')
            ->get();
    }

    public function markResolved(int $id, string $resolution): void
    {
        $this->model->where('id', $id)->update([
            'resolved_at' => now(),
            'resolution' => $resolution,
        ]);
    }
}
