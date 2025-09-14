<?php

namespace App\Contracts\Repositories;

use App\Models\User;

interface UserRepositoryInterface extends BaseRepositoryContract
{
    public function create(array $data): User;

    public function update(int $id, array $data): ?User;

    public function findByEmail(string $email): ?User;

    public function findByEmailVerificationToken(string $token): ?User;

    public function findByPasswordResetToken(string $token): ?User;

    public function updatePassword(int $id, string $password): bool;

    public function markEmailAsVerified(int $id): bool;
}
