<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Validates that the value is the primary key of a record in $table that belongs
 * to the given user (defaults to the authenticated user) via $userColumn.
 *
 * Prevents cross-tenant IDOR where a request references another user's
 * category / account / tag / counterparty / recurring group as a foreign key.
 * Null / empty values pass (compose with `nullable` / `required`).
 */
class OwnedByUser implements ValidationRule
{
    public function __construct(
        private readonly string $table,
        private readonly ?int $userId = null,
        private readonly string $userColumn = 'user_id',
        private readonly string $idColumn = 'id',
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $userId = $this->userId ?? Auth::id();
        if ($userId === null) {
            $fail('The selected :attribute is invalid.');

            return;
        }

        $exists = DB::table($this->table)
            ->where($this->idColumn, $value)
            ->where($this->userColumn, $userId)
            ->exists();

        if (! $exists) {
            $fail('The selected :attribute is invalid.');
        }
    }
}
