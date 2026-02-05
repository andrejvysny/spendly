<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\RuleConditionRepositoryInterface;
use App\Models\RuleEngine\RuleCondition;
use App\Repositories\Concerns\RuleScoped;

class RuleConditionRepository extends BaseRepository implements RuleConditionRepositoryInterface
{
    use RuleScoped;

    public function __construct(RuleCondition $model)
    {
        parent::__construct($model);
    }
}
