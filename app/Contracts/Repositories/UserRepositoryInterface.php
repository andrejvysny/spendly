<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Models\User;

/**
 * @extends BaseRepositoryContract<User>
 */
interface UserRepositoryInterface extends BaseRepositoryContract
{
    public function findByEmail(string $email): ?User;

    public function findByEmailVerificationToken(string $token): ?User;

    public function findByPasswordResetToken(string $token): ?User;

    public function updatePassword(int $id, string $password): bool;

    public function markEmailAsVerified(int $id): bool;
}
