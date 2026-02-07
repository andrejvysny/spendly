<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\GoCardlessSyncFailureRepositoryInterface;
use App\Models\GoCardlessSyncFailure;
use Illuminate\Support\Collection;

class GoCardlessSyncFailureRepository extends BaseRepository implements GoCardlessSyncFailureRepositoryInterface
{
    public function __construct(GoCardlessSyncFailure $model)
    {
        parent::__construct($model);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): GoCardlessSyncFailure
    {
        $model = $this->model->create($data);

        return $model instanceof GoCardlessSyncFailure ? $model : $this->model->find($model->getKey());
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
