<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Models\GoCardlessSyncFailure;
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
}
