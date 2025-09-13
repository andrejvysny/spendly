<?php

namespace App\Repositories;

use App\Models\Merchant;
use App\Contracts\Repositories\MerchantRepositoryInterface;
use Illuminate\Support\Collection;

class MerchantRepository extends BaseRepository implements MerchantRepositoryInterface
{
    public function __construct(Merchant $model)
    {
        parent::__construct($model);
    }

    public function create(array $data): Merchant
    {
        if (!isset($data['user_id'])) {
            $data['user_id'] = auth()->id();
        }
        return $this->model->create($data);
    }

    public function update(int $id, array $data): ?Merchant
    {
        $merchant = $this->model->find($id);
        if (!$merchant) {
            return null;
        }

        $merchant->update($data);
        return $merchant->fresh();
    }

    public function findByUserId(int $userId): Collection
    {
        return $this->model->where('user_id', $userId)->get();
    }

    public function findByUserAndName(int $userId, string $name): ?Merchant
    {
        return $this->model->where('user_id', $userId)
            ->where('name', $name)
            ->first();
    }

    public function firstOrCreate(array $attributes, array $values = []): Merchant
    {
        return $this->model->firstOrCreate($attributes, $values);
    }
}
