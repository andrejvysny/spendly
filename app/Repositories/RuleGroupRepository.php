<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\RuleGroupRepositoryInterface;
use App\Models\RuleEngine\RuleGroup;
use App\Repositories\Concerns\UserScoped;

class RuleGroupRepository extends BaseRepository implements RuleGroupRepositoryInterface
{
    use UserScoped;

    public function __construct(RuleGroup $model)
    {
        parent::__construct($model);
    }
}
