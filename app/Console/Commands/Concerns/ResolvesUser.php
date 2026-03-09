<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Models\User;

trait ResolvesUser
{
    private function resolveUser(?string $userInput): ?User
    {
        if ($userInput === null || $userInput === '') {
            return User::query()->orderBy('id')->first();
        }
        if (is_numeric($userInput)) {
            return User::find((int) $userInput);
        }

        return User::query()->where('email', $userInput)->first();
    }
}
