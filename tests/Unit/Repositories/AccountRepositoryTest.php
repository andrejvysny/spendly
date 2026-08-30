<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Models\Account;
use App\Models\User;
use App\Repositories\AccountRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private AccountRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new AccountRepository(new Account);
    }

    public function test_create_for_user_sets_user_id_regardless_of_payload(): void
    {
        $user = User::factory()->create();

        $account = $this->repository->createForUser($user->id, [
            'name' => 'Checking',
            'type' => 'checking',
            'currency' => 'EUR',
            'balance' => 0,
        ]);

        $this->assertSame($user->id, $account->user_id);
        $this->assertDatabaseHas('accounts', [
            'id' => $account->id,
            'user_id' => $user->id,
            'name' => 'Checking',
        ]);
    }

    public function test_create_for_user_ignores_user_id_in_data_payload(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        // Even if a caller (accidentally or maliciously) includes a user_id in
        // $data, the explicit $userId argument is authoritative.
        $account = $this->repository->createForUser($owner->id, [
            'name' => 'Checking',
            'type' => 'checking',
            'currency' => 'EUR',
            'balance' => 0,
            'user_id' => $attacker->id,
        ]);

        $this->assertSame($owner->id, $account->user_id);
    }
}
