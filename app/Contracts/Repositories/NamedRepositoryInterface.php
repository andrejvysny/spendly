<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

/**
 * @template TModel of \Illuminate\Database\Eloquent\Model
 * @extends UserScopedRepositoryInterface<TModel>
 */
interface NamedRepositoryInterface extends UserScopedRepositoryInterface
{
    /**
     * @return TModel|null
     */
    public function findByUserAndName(int $userId, string $name): ?object;

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $values
     * @return TModel
     */
    public function firstOrCreate(array $attributes, array $values = []): object;
}
