<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\TagRepositoryInterface;
use App\Models\Tag;
use App\Repositories\Concerns\UserScoped;

class TagRepository extends BaseRepository implements TagRepositoryInterface
{
    use UserScoped;

    public function __construct(Tag $model)
    {
        parent::__construct($model);
    }
}
