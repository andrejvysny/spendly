<?php

namespace App\Contracts\Repositories;

use App\Models\Tag;
use Illuminate\Support\Collection;

interface TagRepositoryInterface extends BaseRepositoryContract
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Tag;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $id, array $data): ?Tag;

    /**
     * @return Collection<int, Tag>
     */
    public function findByUserId(int $userId): Collection;

    public function findByUserAndName(int $userId, string $name): ?Tag;

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $values
     */
    public function firstOrCreate(array $attributes, array $values = []): Tag;
}
