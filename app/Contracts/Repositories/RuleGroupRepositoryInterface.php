<?php

namespace App\Contracts\Repositories;

use App\Models\RuleEngine\RuleGroup;
use Illuminate\Support\Collection;

interface RuleGroupRepositoryInterface extends BaseRepositoryContract
{
    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): RuleGroup;

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): ?RuleGroup;

    /**
     * @return Collection<int, RuleGroup>
     */
    public function findByUser(int $userId): Collection;
}
