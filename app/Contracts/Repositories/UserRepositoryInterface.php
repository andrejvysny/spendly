<?php

namespace App\Contracts\Repositories;

use App\Models\User;

interface UserRepositoryInterface extends BaseRepositoryContract
{
    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): User;

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): ?User;

    public function findByEmail(string $email): ?User;

    public function findByEmailVerificationToken(string $token): ?User;

    public function findByPasswordResetToken(string $token): ?User;

    public function updatePassword(int $id, string $password): bool;

    public function markEmailAsVerified(int $id): bool;
}
