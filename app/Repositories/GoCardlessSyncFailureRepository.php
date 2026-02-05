<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\GoCardlessSyncFailureRepositoryInterface;
use App\Models\GoCardlessSyncFailure;
use Illuminate\Support\Collection;

class GoCardlessSyncFailureRepository implements GoCardlessSyncFailureRepositoryInterface
{
    public function __construct(
        private readonly GoCardlessSyncFailure $model
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): GoCardlessSyncFailure
    {
        return GoCardlessSyncFailure::create($data);
    }

    /**
     * @return Collection<int, GoCardlessSyncFailure>
     */
    public function getUnresolvedByAccount(int $accountId): Collection
    {
        return GoCardlessSyncFailure::where('account_id', $accountId)
            ->whereNull('resolved_at')
            ->orderBy('created_at')
            ->get();
    }

    public function markResolved(int $id, string $resolution): void
    {
        GoCardlessSyncFailure::where('id', $id)->update([
            'resolved_at' => now(),
            'resolution' => $resolution,
        ]);
    }
}
