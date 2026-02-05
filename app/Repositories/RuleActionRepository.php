<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\RuleActionRepositoryInterface;
use App\Models\RuleEngine\RuleAction;
use App\Repositories\Concerns\RuleScoped;

class RuleActionRepository extends BaseRepository implements RuleActionRepositoryInterface
{
    use RuleScoped;

    public function __construct(RuleAction $model)
    {
        parent::__construct($model);
    }
}
