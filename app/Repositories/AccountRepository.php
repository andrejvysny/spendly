<?php

namespace App\Repositories;

use App\Models\Account;
use Illuminate\Support\Collection;

class AccountRepository
{
    /**
     * Find an account by ID for a specific user.
     */
    public function findByIdForUser(int $accountId, int $userId): ?Account
    {
        return Account::where('id', $accountId)
            ->where('user_id', $userId)
            ->first();
    }

    /**
     * Find an account by GoCardless account ID.
     */
    public function findByGocardlessId(string $gocardlessAccountId, int $userId): ?Account
    {
        return Account::where('gocardless_account_id', $gocardlessAccountId)
            ->where('user_id', $userId)
            ->first();
    }

    /**
     * Get all GoCardless synced accounts for a user.
     */
    public function getGocardlessSyncedAccounts(int $userId): Collection
    {
        return Account::where('user_id', $userId)
            ->where('is_gocardless_synced', true)
            ->get();
    }

    /**
     * Update account sync timestamp.
     */
    public function updateSyncTimestamp(Account $account): bool
    {
        return $account->update([
            'gocardless_last_synced_at' => now(),
        ]);
    }

    /**
     * Create a new account.
     */
    public function create(array $data): Account
    {
        return Account::create($data);
    }

    /**
     * Check if GoCardless account exists for user.
     */
    public function gocardlessAccountExists(string $gocardlessAccountId, int $userId): bool
    {
        return Account::where('gocardless_account_id', $gocardlessAccountId)
            ->where('user_id', $userId)
            ->exists();
    }
}
