<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransferDetectionService;
use Carbon\Carbon;
use Tests\TestCase;

class TransferDetectionTest extends TestCase
{
    public function test_returns_zero_when_user_has_fewer_than_two_accounts(): void
    {
        $user = User::factory()->create();
        Account::factory()->create(['user_id' => $user->id]);

        $service = $this->app->make(TransferDetectionService::class);
        $updated = $service->detectAndMarkTransfersForUser((int) $user->id);

        $this->assertSame(0, $updated);
    }

    public function test_marks_same_day_debit_credit_pair_as_transfer(): void
    {
        $user = User::factory()->create();
        $accountA = Account::factory()->create(['user_id' => $user->id, 'iban' => 'SK1111000000001111111111']);
        $accountB = Account::factory()->create(['user_id' => $user->id, 'iban' => 'SK2222000000002222222222']);

        $date = Carbon::parse('2025-01-15');
        $debit = Transaction::factory()->create([
            'account_id' => $accountA->id,
            'transaction_id' => 'T-DEBIT-1',
            'amount' => -100.00,
            'currency' => 'EUR',
            'booked_date' => $date,
            'processed_date' => $date,
            'partner' => 'Transfer',
            'description' => 'To savings',
            'type' => 'PAYMENT',
            'transfer_pair_transaction_id' => null,
            'target_iban' => $accountB->iban,
            'source_iban' => null,
        ]);
        $credit = Transaction::factory()->create([
            'account_id' => $accountB->id,
            'transaction_id' => 'T-CREDIT-1',
            'amount' => 100.00,
            'currency' => 'EUR',
            'booked_date' => $date,
            'processed_date' => $date,
            'partner' => 'From current',
            'description' => 'From current',
            'type' => 'DEPOSIT',
            'transfer_pair_transaction_id' => null,
            'source_iban' => $accountA->iban,
            'target_iban' => null,
        ]);

        $service = $this->app->make(TransferDetectionService::class);
        $updated = $service->detectAndMarkTransfersForUser((int) $user->id);

        $this->assertSame(2, $updated);

        $debit->refresh();
        $credit->refresh();
        $this->assertSame(Transaction::TYPE_TRANSFER, $debit->type);
        $this->assertSame(Transaction::TYPE_TRANSFER, $credit->type);
        $this->assertSame($credit->id, $debit->transfer_pair_transaction_id);
        $this->assertSame($debit->id, $credit->transfer_pair_transaction_id);
    }

    public function test_does_not_pair_transactions_on_same_account(): void
    {
        $user = User::factory()->create();
        $accountA = Account::factory()->create(['user_id' => $user->id]);
        $accountB = Account::factory()->create(['user_id' => $user->id]);

        $date = Carbon::parse('2025-01-15');
        Transaction::factory()->create([
            'account_id' => $accountA->id,
            'transaction_id' => 'T-D1',
            'amount' => -50.00,
            'currency' => 'EUR',
            'booked_date' => $date,
            'processed_date' => $date,
            'partner' => 'Merchant',
            'description' => 'Payment',
            'type' => 'PAYMENT',
            'transfer_pair_transaction_id' => null,
        ]);
        Transaction::factory()->create([
            'account_id' => $accountA->id,
            'transaction_id' => 'T-C1',
            'amount' => 50.00,
            'currency' => 'EUR',
            'booked_date' => $date,
            'processed_date' => $date,
            'partner' => 'Refund',
            'description' => 'Refund',
            'type' => 'DEPOSIT',
            'transfer_pair_transaction_id' => null,
        ]);

        $service = $this->app->make(TransferDetectionService::class);
        $updated = $service->detectAndMarkTransfersForUser((int) $user->id);

        $this->assertSame(0, $updated);
    }

    public function test_uses_amount_tolerance_for_matching(): void
    {
        $user = User::factory()->create();
        $accountA = Account::factory()->create(['user_id' => $user->id, 'iban' => 'SK3333000000003333333333']);
        $accountB = Account::factory()->create(['user_id' => $user->id, 'iban' => 'SK4444000000004444444444']);

        $date = Carbon::parse('2025-01-15');
        $debit = Transaction::factory()->create([
            'account_id' => $accountA->id,
            'transaction_id' => 'T-D2',
            'amount' => -10.01,
            'currency' => 'EUR',
            'booked_date' => $date,
            'processed_date' => $date,
            'partner' => 'X',
            'description' => 'Out',
            'type' => 'PAYMENT',
            'transfer_pair_transaction_id' => null,
            'target_iban' => $accountB->iban,
            'source_iban' => null,
        ]);
        $credit = Transaction::factory()->create([
            'account_id' => $accountB->id,
            'transaction_id' => 'T-C2',
            'amount' => 10.00,
            'currency' => 'EUR',
            'booked_date' => $date,
            'processed_date' => $date,
            'partner' => 'Y',
            'description' => 'In',
            'type' => 'DEPOSIT',
            'transfer_pair_transaction_id' => null,
            'source_iban' => $accountA->iban,
            'target_iban' => null,
        ]);

        $service = $this->app->make(TransferDetectionService::class);
        $updated = $service->detectAndMarkTransfersForUser((int) $user->id);

        $this->assertSame(2, $updated);
        $debit->refresh();
        $credit->refresh();
        $this->assertSame(Transaction::TYPE_TRANSFER, $debit->type);
        $this->assertSame(Transaction::TYPE_TRANSFER, $credit->type);
    }

    public function test_idempotent_does_not_overwrite_already_marked_transfers(): void
    {
        $user = User::factory()->create();
        $accountA = Account::factory()->create(['user_id' => $user->id]);
        $accountB = Account::factory()->create(['user_id' => $user->id]);

        $date = Carbon::parse('2025-01-15');
        $debit = Transaction::factory()->create([
            'account_id' => $accountA->id,
            'transaction_id' => 'T-D3',
            'amount' => -25.00,
            'currency' => 'EUR',
            'booked_date' => $date,
            'processed_date' => $date,
            'partner' => 'A',
            'description' => 'Out',
            'type' => Transaction::TYPE_TRANSFER,
            'transfer_pair_transaction_id' => null,
        ]);
        $credit = Transaction::factory()->create([
            'account_id' => $accountB->id,
            'transaction_id' => 'T-C3',
            'amount' => 25.00,
            'currency' => 'EUR',
            'booked_date' => $date,
            'processed_date' => $date,
            'partner' => 'B',
            'description' => 'In',
            'type' => Transaction::TYPE_TRANSFER,
            'transfer_pair_transaction_id' => null,
        ]);
        $debit->update(['transfer_pair_transaction_id' => $credit->id]);
        $credit->update(['transfer_pair_transaction_id' => $debit->id]);

        $service = $this->app->make(TransferDetectionService::class);
        $updated = $service->detectAndMarkTransfersForUser((int) $user->id);

        $this->assertSame(0, $updated);
        $debit->refresh();
        $credit->refresh();
        $this->assertSame($credit->id, $debit->transfer_pair_transaction_id);
        $this->assertSame($debit->id, $credit->transfer_pair_transaction_id);
    }

    public function test_does_not_pair_when_credit_source_iban_is_not_debit_account(): void
    {
        $user = User::factory()->create();
        $accountA = Account::factory()->create(['user_id' => $user->id, 'iban' => 'SK6809000000005183172536']);
        $accountB = Account::factory()->create(['user_id' => $user->id, 'iban' => 'SK9009000000005124514591']);

        $date = Carbon::parse('2026-01-29');
        Transaction::factory()->create([
            'account_id' => $accountA->id,
            'transaction_id' => 'T-IN-EXT',
            'amount' => 10.00,
            'currency' => 'EUR',
            'booked_date' => $date,
            'processed_date' => $date,
            'partner' => 'Lejková Rebeka',
            'description' => 'From external',
            'type' => 'DEPOSIT',
            'transfer_pair_transaction_id' => null,
            'source_iban' => 'SK9109000000005182762200',
            'target_iban' => null,
        ]);
        Transaction::factory()->create([
            'account_id' => $accountB->id,
            'transaction_id' => 'T-OUT-CARD',
            'amount' => -10.00,
            'currency' => 'EUR',
            'booked_date' => $date,
            'processed_date' => $date,
            'partner' => 'TP636911114',
            'description' => 'Card payment',
            'type' => 'PAYMENT',
            'transfer_pair_transaction_id' => null,
            'target_iban' => null,
            'source_iban' => null,
        ]);

        $service = $this->app->make(TransferDetectionService::class);
        $updated = $service->detectAndMarkTransfersForUser((int) $user->id);

        $this->assertSame(0, $updated, 'Must not pair when credit is from external (source_iban) and debit has no target_iban');
    }

    public function test_respects_date_range_filter(): void
    {
        $user = User::factory()->create();
        $accountA = Account::factory()->create(['user_id' => $user->id]);
        $accountB = Account::factory()->create(['user_id' => $user->id]);

        $insideDate = Carbon::parse('2025-01-20');
        $outsideDate = Carbon::parse('2025-02-20');
        Transaction::factory()->create([
            'account_id' => $accountA->id,
            'transaction_id' => 'T-D4',
            'amount' => -30.00,
            'currency' => 'EUR',
            'booked_date' => $outsideDate,
            'processed_date' => $outsideDate,
            'partner' => 'X',
            'description' => 'Out',
            'type' => 'PAYMENT',
            'transfer_pair_transaction_id' => null,
        ]);
        Transaction::factory()->create([
            'account_id' => $accountB->id,
            'transaction_id' => 'T-C4',
            'amount' => 30.00,
            'currency' => 'EUR',
            'booked_date' => $outsideDate,
            'processed_date' => $outsideDate,
            'partner' => 'Y',
            'description' => 'In',
            'type' => 'DEPOSIT',
            'transfer_pair_transaction_id' => null,
        ]);

        $service = $this->app->make(TransferDetectionService::class);
        $from = Carbon::parse('2025-01-01');
        $to = Carbon::parse('2025-01-31');
        $updated = $service->detectAndMarkTransfersForUser((int) $user->id, $from, $to);

        $this->assertSame(0, $updated);
    }

    public function test_matches_verified_own_account_transfer_on_next_day(): void
    {
        $user = User::factory()->create();
        $accountA = Account::factory()->create(['user_id' => $user->id, 'iban' => 'SK5555000000005555555555']);
        $accountB = Account::factory()->create(['user_id' => $user->id, 'iban' => 'SK6666000000006666666666']);

        $debit = Transaction::factory()->create([
            'account_id' => $accountA->id,
            'transaction_id' => 'T-D5',
            'amount' => -75.00,
            'currency' => 'EUR',
            'booked_date' => Carbon::parse('2025-01-15'),
            'processed_date' => Carbon::parse('2025-01-15'),
            'type' => Transaction::TYPE_PAYMENT,
            'transfer_pair_transaction_id' => null,
            'target_iban' => $accountB->iban,
        ]);
        $credit = Transaction::factory()->create([
            'account_id' => $accountB->id,
            'transaction_id' => 'T-C5',
            'amount' => 75.00,
            'currency' => 'EUR',
            'booked_date' => Carbon::parse('2025-01-16'),
            'processed_date' => Carbon::parse('2025-01-16'),
            'type' => Transaction::TYPE_DEPOSIT,
            'transfer_pair_transaction_id' => null,
            'source_iban' => $accountA->iban,
        ]);

        $updated = $this->app->make(TransferDetectionService::class)
            ->detectAndMarkTransfersForUser((int) $user->id);

        $this->assertSame(2, $updated);

        $debit->refresh();
        $credit->refresh();
        $this->assertSame(Transaction::TYPE_TRANSFER, $debit->type);
        $this->assertSame(Transaction::TYPE_TRANSFER, $credit->type);
        $this->assertSame($credit->id, $debit->transfer_pair_transaction_id);
        $this->assertSame($debit->id, $credit->transfer_pair_transaction_id);
    }

    public function test_does_not_pair_cross_currency_transactions(): void
    {
        $user = User::factory()->create();
        $accountA = Account::factory()->create(['user_id' => $user->id, 'iban' => 'SK7777000000007777777777']);
        $accountB = Account::factory()->create(['user_id' => $user->id, 'iban' => 'SK8888000000008888888888']);

        $date = Carbon::parse('2025-01-15');
        $debit = Transaction::factory()->create([
            'account_id' => $accountA->id,
            'transaction_id' => 'T-D6',
            'amount' => -100.00,
            'currency' => 'EUR',
            'booked_date' => $date,
            'processed_date' => $date,
            'type' => Transaction::TYPE_PAYMENT,
            'transfer_pair_transaction_id' => null,
            'target_iban' => $accountB->iban,
        ]);
        $credit = Transaction::factory()->create([
            'account_id' => $accountB->id,
            'transaction_id' => 'T-C6',
            'amount' => 100.00,
            'currency' => 'USD',
            'booked_date' => $date,
            'processed_date' => $date,
            'type' => Transaction::TYPE_DEPOSIT,
            'transfer_pair_transaction_id' => null,
            'source_iban' => $accountA->iban,
        ]);

        $updated = $this->app->make(TransferDetectionService::class)
            ->detectAndMarkTransfersForUser((int) $user->id);

        $this->assertSame(0, $updated);
        $this->assertSame(Transaction::TYPE_PAYMENT, $debit->fresh()->type);
        $this->assertSame(Transaction::TYPE_DEPOSIT, $credit->fresh()->type);
    }

    public function test_marks_unpaired_transfer_candidates_for_review(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'iban' => 'SK9999000000009999999999']);
        Account::factory()->create(['user_id' => $user->id, 'iban' => 'SK0000000000000000000001']);

        $transaction = Transaction::factory()->create([
            'account_id' => $account->id,
            'transaction_id' => 'T-D7',
            'amount' => -42.00,
            'currency' => 'EUR',
            'booked_date' => Carbon::parse('2025-01-22'),
            'processed_date' => Carbon::parse('2025-01-22'),
            'type' => Transaction::TYPE_PAYMENT,
            'transfer_pair_transaction_id' => null,
            'metadata' => ['transfer_candidate' => true],
            'needs_manual_review' => false,
            'review_reason' => null,
        ]);

        $updated = $this->app->make(TransferDetectionService::class)
            ->detectAndMarkTransfersForUser((int) $user->id);

        $this->assertSame(0, $updated);

        $transaction->refresh();
        $this->assertTrue($transaction->needs_manual_review);
        $this->assertSame(TransferDetectionService::REVIEW_REASON_TRANSFER_CANDIDATE_UNPAIRED, $transaction->review_reason);
    }
}
