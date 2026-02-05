<?php

declare(strict_types=1);

namespace App\Repositories\Concerns;

use Illuminate\Support\Collection;

trait UserScoped
{
    /**
     * @return Collection<int, \Illuminate\Database\Eloquent\Model>
     */
    public function findByUser(int $userId): Collection
    {
        return $this->model->where('user_id', $userId)->get();
    }

    public function findByUserAndName(int $userId, string $name): ?object
    {
        return $this->model
            ->where('user_id', $userId)
            ->where('name', $name)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $values
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function firstOrCreate(array $attributes, array $values = []): object
    {
        return $this->model->firstOrCreate($attributes, $values);
    }
}
