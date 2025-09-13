<?php

namespace App\Contracts\Repositories;

use App\Models\Merchant;
use Illuminate\Support\Collection;

interface MerchantRepositoryInterface extends BaseRepositoryContract
{
    public function create(array $data): Merchant;
    public function update(int $id, array $data): ?Merchant;
    public function findByUser(int $userId): Collection;
    public function findByUserAndName(int $userId, string $name): ?Merchant;
    public function firstOrCreate(array $attributes, array $values = []): Merchant;
}
