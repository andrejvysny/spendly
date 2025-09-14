<?php

namespace App\Contracts\Repositories;

use App\Models\Account;
use Illuminate\Support\Collection;

interface AccountRepositoryInterface extends BaseRepositoryContract
{
    public function findByIdForUser(int $accountId, int $userId): ?Account;

    public function findByGocardlessId(string $gocardlessAccountId, int $userId): ?Account;

    public function findByUser(int $userId): Collection;

    public function getGocardlessSyncedAccounts(int $userId): Collection;

    public function updateSyncTimestamp(Account $account): bool;

    public function create(array $data): Account;

    public function gocardlessAccountExists(string $gocardlessAccountId, int $userId): bool;
}
