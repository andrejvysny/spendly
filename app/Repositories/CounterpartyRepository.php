<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\CounterpartyRepositoryInterface;
use App\Models\Counterparty;
use App\Repositories\Concerns\UserScoped;

class CounterpartyRepository extends BaseRepository implements CounterpartyRepositoryInterface
{
    use UserScoped;

    public function __construct(Counterparty $model)
    {
        parent::__construct($model);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Counterparty
    {
        return $this->model->create($data);
    }
}
