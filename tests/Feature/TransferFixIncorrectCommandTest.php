<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * transfers:fix-incorrect must be method-aware: it repairs only pairs whose
 * recorded detection method fails its own recheck, and never fights
 * single-leg, manual, or heuristic marks.
 */
class TransferFixIncorrectCommandTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeTransfer(Account $account, array $overrides = []): Transaction
    {
        /** @var Transaction $transaction */
        $transaction = Transaction::factory()->create(array_merge([
            'account_id' => $account->id,
            'currency' => 'EUR',
            'type' => Transaction::TYPE_TRANSFER,
            'booked_date' => Carbon::parse('2025-06-01'),
            'target_iban' => null,
            'source_iban' => null,
            'metadata' => null,
            'partner' => 'Partner',
            'description' => 'Transfer',
        ], $overrides));

        return $transaction;
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function runFixCommand(array $parameters): \Illuminate\Testing\PendingCommand
    {
        $result = $this->artisan('transfers:fix-incorrect', $parameters);
        assert($result instanceof \Illuminate\Testing\PendingCommand);

        return $result;
    }

    private function pairUp(Transaction $debit, Transaction $credit, ?string $method): void
    {
        $evidence = $method !== null
            ? ['transfer_detection' => ['method' => $method, 'pair_id' => null, 'matched_at' => '2025-06-01T00:00:00+00:00']]
            : null;

        $debit->update([
            'transfer_pair_transaction_id' => $credit->id,
            'metadata' => $evidence,
        ]);
        $credit->update([
            'transfer_pair_transaction_id' => $debit->id,
            'metadata' => $evidence,
        ]);
    }

    public function test_single_leg_transfer_survives_unpaired_fix(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'iban' => null]);

        $pocketMove = $this->makeTransfer($account, [
            'amount' => -10.00,
            'metadata' => [
                'single_leg_transfer' => true,
                'transfer_detection' => ['method' => 'single_leg', 'pair_id' => null],
            ],
        ]);

        $this->runFixCommand(['--user' => $user->id])->assertSuccessful();

        $this->assertSame(Transaction::TYPE_TRANSFER, $pocketMove->refresh()->type);
    }

    public function test_manual_unpaired_transfer_survives_fix(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'iban' => null]);

        $manual = $this->makeTransfer($account, [
            'amount' => -10.00,
            'metadata' => ['transfer_detection' => ['method' => 'manual', 'pair_id' => null]],
        ]);

        $this->runFixCommand(['--user' => $user->id])->assertSuccessful();

        $this->assertSame(Transaction::TYPE_TRANSFER, $manual->refresh()->type);
    }

    public function test_orphaned_transfer_without_own_counterparty_iban_is_reclassified(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'iban' => 'SK1111000000001111111111']);
        Account::factory()->create(['user_id' => $user->id, 'iban' => 'SK2222000000002222222222']);

        $orphan = $this->makeTransfer($account, [
            'amount' => -10.00,
            'target_iban' => 'DE99999999999999999999',
        ]);

        $this->runFixCommand(['--user' => $user->id])->assertSuccessful();

        $this->assertSame(Transaction::TYPE_PAYMENT, $orphan->refresh()->type);
    }

    public function test_heuristic_pair_survives_fix_pairs(): void
    {
        $user = User::factory()->create();
        $accountA = Account::factory()->create(['user_id' => $user->id, 'iban' => null]);
        $accountB = Account::factory()->create(['user_id' => $user->id, 'iban' => null]);

        $debit = $this->makeTransfer($accountA, ['amount' => -50.00]);
        $credit = $this->makeTransfer($accountB, ['amount' => 50.00]);
        $this->pairUp($debit, $credit, 'heuristic');

        $this->runFixCommand(['--user' => $user->id, '--fix-pairs' => true])->assertSuccessful();

        $this->assertSame(Transaction::TYPE_TRANSFER, $debit->refresh()->type);
        $this->assertSame($credit->id, $debit->transfer_pair_transaction_id);
    }

    public function test_legacy_pair_failing_bidirectional_recheck_is_unpaired(): void
    {
        $user = User::factory()->create();
        $accountA = Account::factory()->create(['user_id' => $user->id, 'iban' => 'SK1111000000001111111111']);
        $accountB = Account::factory()->create(['user_id' => $user->id, 'iban' => 'SK2222000000002222222222']);

        // No detection evidence, no IBANs on the legs: legacy pair that cannot
        // satisfy the bidirectional recheck.
        $debit = $this->makeTransfer($accountA, ['amount' => -50.00]);
        $credit = $this->makeTransfer($accountB, ['amount' => 50.00]);
        $this->pairUp($debit, $credit, null);

        $this->runFixCommand(['--user' => $user->id, '--fix-pairs' => true])->assertSuccessful();

        $debit->refresh();
        $credit->refresh();
        $this->assertSame(Transaction::TYPE_PAYMENT, $debit->type);
        $this->assertSame(Transaction::TYPE_DEPOSIT, $credit->type);
        $this->assertNull($debit->transfer_pair_transaction_id);
    }

    public function test_one_sided_pair_passing_its_recheck_survives(): void
    {
        $user = User::factory()->create();
        $accountA = Account::factory()->create(['user_id' => $user->id, 'iban' => 'SK1111000000001111111111']);
        $accountB = Account::factory()->create(['user_id' => $user->id, 'iban' => 'LT600000000000000001']);

        $debit = $this->makeTransfer($accountA, [
            'amount' => -50.00,
            'target_iban' => $accountB->iban,
        ]);
        $credit = $this->makeTransfer($accountB, ['amount' => 50.00]);
        $this->pairUp($debit, $credit, 'iban_one_sided');
        // pairUp overwrote metadata on both; restore evidence with the debit's IBAN intact.
        $debit->update(['target_iban' => $accountB->iban]);

        $this->runFixCommand(['--user' => $user->id, '--fix-pairs' => true])->assertSuccessful();

        $this->assertSame(Transaction::TYPE_TRANSFER, $debit->refresh()->type);
    }

    public function test_one_sided_pair_with_broken_link_is_unpaired(): void
    {
        $user = User::factory()->create();
        $accountA = Account::factory()->create(['user_id' => $user->id, 'iban' => 'SK1111000000001111111111']);
        $accountB = Account::factory()->create(['user_id' => $user->id, 'iban' => 'LT600000000000000001']);

        $debit = $this->makeTransfer($accountA, [
            'amount' => -50.00,
            'target_iban' => 'DE99999999999999999999',
        ]);
        $credit = $this->makeTransfer($accountB, ['amount' => 50.00]);
        $this->pairUp($debit, $credit, 'iban_one_sided');
        $debit->update(['target_iban' => 'DE99999999999999999999']);

        $this->runFixCommand(['--user' => $user->id, '--fix-pairs' => true])->assertSuccessful();

        $this->assertSame(Transaction::TYPE_PAYMENT, $debit->refresh()->type);
        $this->assertNull($debit->transfer_pair_transaction_id);
    }

    public function test_include_heuristic_unpairs_only_hard_violations(): void
    {
        $user = User::factory()->create();
        $accountA = Account::factory()->create(['user_id' => $user->id, 'iban' => null]);
        $accountB = Account::factory()->create(['user_id' => $user->id, 'iban' => null]);

        // Valid heuristic pair: different accounts, amounts within tolerance.
        $validDebit = $this->makeTransfer($accountA, ['amount' => -50.00]);
        $validCredit = $this->makeTransfer($accountB, ['amount' => 50.00]);
        $this->pairUp($validDebit, $validCredit, 'heuristic');

        // Hard violation: both legs on the same account.
        $badDebit = $this->makeTransfer($accountA, ['amount' => -70.00]);
        $badCredit = $this->makeTransfer($accountA, ['amount' => 70.00]);
        $this->pairUp($badDebit, $badCredit, 'heuristic');

        $this->runFixCommand([
            '--user' => $user->id,
            '--fix-pairs' => true,
            '--include-heuristic' => true,
        ])->assertSuccessful();

        $this->assertSame(Transaction::TYPE_TRANSFER, $validDebit->refresh()->type);
        $this->assertSame(Transaction::TYPE_PAYMENT, $badDebit->refresh()->type);
        $this->assertNull($badDebit->transfer_pair_transaction_id);
    }
}
