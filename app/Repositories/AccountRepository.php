<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\AccountRepositoryInterface;
use App\Models\Account;
use App\Repositories\Concerns\UserScoped;
use Illuminate\Support\Collection;

class AccountRepository extends BaseRepository implements AccountRepositoryInterface
{
    use UserScoped;

    public function __construct(Account $model)
    {
        parent::__construct($model);
    }

    public function findByIdForUser(int $accountId, int $userId): ?Account
    {
        $account = $this->model->where('id', $accountId)
            ->where('user_id', $userId)
            ->first();

        return $account instanceof Account ? $account : null;
    }

    public function findByGocardlessId(string $gocardlessAccountId, int $userId): ?Account
    {
        $account = $this->model->where('gocardless_account_id', $gocardlessAccountId)
            ->where('user_id', $userId)
            ->first();

        return $account instanceof Account ? $account : null;
    }

    /**
     * @return Collection<int, Account>
     */
    public function getGocardlessSyncedAccounts(int $userId): Collection
    {
        return $this->model->where('user_id', $userId)
            ->where('is_gocardless_synced', true)
            ->get();
    }

    public function updateSyncTimestamp(Account $account): bool
    {
        return $account->update([
            'gocardless_last_synced_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Account
    {
        return $this->model->create($data);
    }

    public function gocardlessAccountExists(string $gocardlessAccountId, int $userId): bool
    {
        return $this->model->where('gocardless_account_id', $gocardlessAccountId)
            ->where('user_id', $userId)
            ->exists();
    }

    /**
     * Update the balance of an account.
     *
     * @param  Account  $account  The account to update
     * @param  float|string  $balance  The new balance value
     */
    public function updateBalance(Account $account, float|string $balance): bool
    {
        return $account->update([
            'balance' => $balance,
        ]);
    }
}
