<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserRepository extends BaseRepository implements UserRepositoryInterface
{
    public function __construct(User $model)
    {
        parent::__construct($model);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): User
    {
        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        $model = $this->model->create($data);

        return $model instanceof User ? $model : $this->model->find($model->getKey());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $id, array $data): ?User
    {
        $user = $this->model->find($id);
        if (! $user) {
            return null;
        }

        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        $user->update($data);

        $fresh = $user->fresh();

        return $fresh instanceof User ? $fresh : null;
    }

    public function findByEmail(string $email): ?User
    {
        $user = $this->model->where('email', $email)->first();

        return $user instanceof User ? $user : null;
    }

    public function findByEmailVerificationToken(string $token): ?User
    {
        $user = $this->model->where('email_verification_token', $token)->first();

        return $user instanceof User ? $user : null;
    }

    public function findByPasswordResetToken(string $token): ?User
    {
        $user = $this->model->where('password_reset_token', $token)->first();

        return $user instanceof User ? $user : null;
    }

    public function updatePassword(int $id, string $password): bool
    {
        return $this->model->where('id', $id)->update([
            'password' => Hash::make($password),
            'password_reset_token' => null,
        ]) > 0;
    }

    public function markEmailAsVerified(int $id): bool
    {
        return $this->model->where('id', $id)->update([
            'email_verified_at' => now(),
            'email_verification_token' => null,
        ]) > 0;
    }
}
