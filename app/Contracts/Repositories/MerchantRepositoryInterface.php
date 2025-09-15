<?php

namespace App\Contracts\Repositories;

use App\Models\Merchant;
use Illuminate\Support\Collection;

interface MerchantRepositoryInterface extends BaseRepositoryContract
{
    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): Merchant;

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): ?Merchant;

    /**
     * @return Collection<int, Merchant>
     */
    public function findByUserId(int $userId): Collection;

    public function findByUserAndName(int $userId, string $name): ?Merchant;

    /**
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $values
     */
    public function firstOrCreate(array $attributes, array $values = []): Merchant;
}
