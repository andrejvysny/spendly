<?php

namespace App\Repositories;

use App\Models\Tag;
use App\Contracts\Repositories\TagRepositoryInterface;
use Illuminate\Support\Collection;

class TagRepository extends BaseRepository implements TagRepositoryInterface
{
    public function __construct(Tag $model)
    {
        parent::__construct($model);
    }

    public function create(array $data): Tag
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): ?Tag
    {
        $tag = $this->model->find($id);
        if (!$tag) {
            return null;
        }

        $tag->update($data);
        return $tag->fresh();
    }

    public function findByUserId(int $userId): Collection
    {
        return $this->model->where('user_id', $userId)->get();
    }

    public function findByUserAndName(int $userId, string $name): ?Tag
    {
        return $this->model->where('user_id', $userId)
            ->where('name', $name)
            ->first();
    }

    public function firstOrCreate(array $attributes, array $values = []): Tag
    {
        return $this->model->firstOrCreate($attributes, $values);
    }
}
