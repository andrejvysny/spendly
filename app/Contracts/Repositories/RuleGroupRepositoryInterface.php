<?php

namespace App\Contracts\Repositories;

use App\Models\RuleEngine\RuleGroup;
use Illuminate\Support\Collection;

interface RuleGroupRepositoryInterface extends BaseRepositoryContract
{
    public function create(array $data): RuleGroup;
    public function update(int $id, array $data): ?RuleGroup;
    public function findByUser(int $userId): Collection;
}
