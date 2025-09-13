<?php

namespace App\Contracts\Repositories;

use App\Models\Tag;
use Illuminate\Support\Collection;

interface TagRepositoryInterface extends BaseRepositoryContract
{
    public function create(array $data): Tag;
    public function update(int $id, array $data): ?Tag;
    public function findByUser(int $userId): Collection;
    public function findByUserAndName(int $userId, string $name): ?Tag;
    public function firstOrCreate(array $attributes, array $values = []): Tag;
}
