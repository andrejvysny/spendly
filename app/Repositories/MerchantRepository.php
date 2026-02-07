<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\MerchantRepositoryInterface;
use App\Models\Merchant;
use App\Repositories\Concerns\UserScoped;

class MerchantRepository extends BaseRepository implements MerchantRepositoryInterface
{
    use UserScoped;

    public function __construct(Merchant $model)
    {
        parent::__construct($model);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Merchant
    {
        return $this->model->create($data);
    }
}
