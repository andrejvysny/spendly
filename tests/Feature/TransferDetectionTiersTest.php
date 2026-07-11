<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransferDetectionService;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Tiered transfer detection: relaxed tier-1 gates, one-sided IBAN (tier 2),
 * heuristic scoring (tier 3), cross-currency, and single-leg pocket moves.
 * All fixtures are synthetic.
 */
class TransferDetectionTiersTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeTransaction(Account $account, array $overrides = []): Transaction
    {
        /** @var Transaction $transaction */
        $transaction = Transaction::factory()->create(array_merge([
            'account_id' => $account->id,
            'currency' => 'EUR',
            'type' => 'PAYMENT',
            'transfer_pair_transaction_id' => null,
            'target_iban' => null,
            'source_iban' => null,
            'metadata' => null,
            'partner' => 'Some Partner',
            'description' => 'Some description',
            'bank_transaction_code' => null,
            'original_currency' => null,
            'original_amount' => null,
            'exchange_rate' => null,
            'native_amount' => null,
        ], $overrides));

        return $transaction;
    }

    private function detect(User $user): int
    {
        return $this->app->make(TransferDetectionService::class)
            ->detectAndMarkTransfersForUser((int) $user->id);
    }

    // ---------------------------------------------------------------- Tier 1

    public function test_tier1_pairs_with_three_day_gap(): void
    {
        $user = User::factory()->create();
        $accountA = Account::factory()->create(['user_id' => $user->id, 'iban' => 'SK1111000000001111111111']);
        $accountB = Account::factory()->create(['user_id' => $user->id, 'iban' => 'SK2222000000002222222222']);

        $debit = $this->makeTransaction($accountA, [
            'amount' => -250.00,
            'booked_date' => Carbon::parse('2025-03-10'),
            'target_iban' => $accountB->iban,
        ]);
        $credit = $this->makeTransaction($accountB, [
            'amount' => 250.00,
            'type' => 'DEPOSIT',
            'booked_date' => Carbon::parse('2025-03-13'),
            'source_iban' => $accountA->iban,
        ]);

        $this->assertSame(2, $this->detect($user));
        $debit->refresh();
        $credit->refresh();
        $this->assertSame(Transaction::TYPE_TRANSFER, $debit->type);
        $this->assertSame($credit->id, $debit->transfer_pair_transaction_id);
        $this->assertSame('iban_bidirectional', $debit->metadata['transfer_detection']['method'] ?? null);
        $this->assertSame($debit->id, $credit->metadata['transfer_detection']['pair_id'] ?? null);
    }

    public function test_tier1_does_not_pair_beyond_date_gap(): void
    {
        $user = User::factory()->create();
        $accountA = Account::factory()->create(['user_id' => $user->id, 'iban' => 'SK1111000000001111111111']);
        $accountB = Account::factory()->create(['user_id' => $user->id, 'iban' => 'SK2222000000002222222222']);

        $this->makeTransaction($accountA, [
            'amount' => -250.00,
            'booked_date' => Carbon::parse('2025-03-10'),
            'target_iban' => $accountB->iban,
        ]);
        $this->makeTransaction($accountB, [
            'amount' => 250.00,
            'type' => 'DEPOSIT',
            'booked_date' => Carbon::parse('2025-03-14'),
            'source_iban' => $accountA->iban,
        ]);

        $this->assertSame(0, $this->detect($user));
    }

    public function test_legacy_strictness_restorable_via_config(): void
    {
        config(['transfers.date_gap_days' => 1]);

        $user = User::factory()->create();
        $accountA = Account::factory()->create(['user_id' => $user->id, 'iban' => 'SK1111000000001111111111']);
        $accountB = Account::factory()->create(['user_id' => $user->id, 'iban' => 'SK2222000000002222222222']);

        $this->makeTransaction($accountA, [
            'amount' => -250.00,
            'booked_date' => Carbon::parse('2025-03-10'),
            'target_iban' => $accountB->iban,
        ]);
        $this->makeTransaction($accountB, [
            'amount' => 250.00,
            'type' => 'DEPOSIT',
            'booked_date' => Carbon::parse('2025-03-12'),
            'source_iban' => $accountA->iban,
        ]);

        $this->assertSame(0, $this->detect($user));
    }

    public function test_percent_tolerance_allows_fee_skimmed_pair(): void
    {
        config(['transfers.amount_tolerance_percent' => 1.0]);

        $user = User::factory()->create();
        $accountA = Account::factory()->create(['user_id' => $user->id, 'iban' => 'SK1111000000001111111111']);
        $accountB = Account::factory()->create(['user_id' => $user->id, 'iban' => 'SK2222000000002222222222']);

        $debit = $this->makeTransaction($accountA, [
            'amount' => -100.00,
            'booked_date' => Carbon::parse('2025-03-10'),
            'target_iban' => $accountB->iban,
        ]);
        $credit = $this->makeTransaction($accountB, [
            'amount' => 99.50,
            'type' => 'DEPOSIT',
            'booked_date' => Carbon::parse('2025-03-10'),
            'source_iban' => $accountA->iban,
        ]);

        $this->assertSame(2, $this->detect($user));
        $this->assertSame($credit->id, $debit->refresh()->transfer_pair_transaction_id);
    }

    // ---------------------------------------------------------------- Tier 2

    public function test_tier2_one_sided_debit_target_iban_pairs(): void
    {
        $user = User::factory()->create();
        // Classic bank -> e-money top-up: the debit leg carries the target IBAN,
        // the credit leg has no counterparty IBAN at all.
        $bank = Account::factory()->create(['user_id' => $user->id, 'iban' => 'SK1111000000001111111111']);
        $emoney = Account::factory()->create(['user_id' => $user->id, 'iban' => 'LT600000000000000001']);

        $debit = $this->makeTransaction($bank, [
            'amount' => -150.00,
            'booked_date' => Carbon::parse('2025-04-01'),
            'target_iban' => $emoney->iban,
        ]);
        $credit = $this->makeTransaction($emoney, [
            'amount' => 150.00,
            'type' => 'DEPOSIT',
            'booked_date' => Carbon::parse('2025-04-02'),
            'source_iban' => null,
        ]);

        $this->assertSame(2, $this->detect($user));
        $debit->refresh();
        $credit->refresh();
        $this->assertSame(Transaction::TYPE_TRANSFER, $credit->type);
        $this->assertSame('iban_one_sided', $debit->metadata['transfer_detection']['method'] ?? null);
    }

    public function test_tier2_one_sided_credit_source_iban_pairs(): void
    {
        $user = User::factory()->create();
        $accountA = Account::factory()->create(['user_id' => $user->id, 'iban' => 'SK1111000000001111111111']);
        $accountB = Account::factory()->create(['user_id' => $user->id, 'iban' => 'SK2222000000002222222222']);

        $debit = $this->makeTransaction($accountA, [
            'amount' => -75.00,
            'booked_date' => Carbon::parse('2025-04-05'),
            'target_iban' => null,
        ]);
        $credit = $this->makeTransaction($accountB, [
            'amount' => 75.00,
            'type' => 'DEPOSIT',
            'booked_date' => Carbon::parse('2025-04-05'),
            'source_iban' => $accountA->iban,
        ]);

        $this->assertSame(2, $this->detect($user));
        $this->assertSame($credit->id, $debit->refresh()->transfer_pair_transaction_id);
        $this->assertSame('iban_one_sided', $debit->metadata['transfer_detection']['method'] ?? null);
    }

    public function test_contradicting_counter_iban_rejects_pair(): void
    {
        $user = User::factory()->create();
        $accountA = Account::factory()->create(['user_id' => $user->id, 'iban' => 'SK1111000000001111111111']);
        $accountB = Account::factory()->create(['user_id' => $user->id, 'iban' => 'SK2222000000002222222222']);

        $this->makeTransaction($accountA, [
            'amount' => -75.00,
            'booked_date' => Carbon::parse('2025-04-05'),
            'target_iban' => $accountB->iban,
        ]);
        // Credit says the money came from an external account - contradiction.
        $this->makeTransaction($accountB, [
            'amount' => 75.00,
            'type' => 'DEPOSIT',
            'booked_date' => Carbon::parse('2025-04-05'),
            'source_iban' => 'DE99999999999999999999',
        ]);

        $this->assertSame(0, $this->detect($user));
    }

    // ---------------------------------------------------------------- Tier 3

    public function test_tier3_auto_pairs_high_scoring_no_iban_candidates(): void
    {
        $user = User::factory()->create(['name' => 'Jána Kováč']);
        $bank = Account::factory()->create([
            'user_id' => $user->id,
            'iban' => 'SK1111000000001111111111',
            'name' => 'Main Checking',
            'bank_name' => 'Test Bank',
        ]);
        $emoney = Account::factory()->create([
            'user_id' => $user->id,
            'iban' => null,
            'name' => 'Neobank Current',
            'bank_name' => 'Neobank',
        ]);

        $debit = $this->makeTransaction($bank, [
            'amount' => -200.00,
            'booked_date' => Carbon::parse('2025-05-02'),
            'partner' => 'Neobank Top-Up',
            'description' => 'Card top-up',
        ]);
        // Diacritics fold: partner carries the ASCII variant of the user's name.
        $credit = $this->makeTransaction($emoney, [
            'amount' => 200.00,
            'type' => 'Topup',
            'booked_date' => Carbon::parse('2025-05-02'),
            'partner' => 'Payment from JANA KOVAC',
            'description' => 'Top-Up by *1234',
        ]);

        $this->assertSame(2, $this->detect($user));
        $debit->refresh();
        $credit->refresh();
        $this->assertSame(Transaction::TYPE_TRANSFER, $debit->type);
        $this->assertSame($credit->id, $debit->transfer_pair_transaction_id);
        $this->assertSame('heuristic', $debit->metadata['transfer_detection']['method'] ?? null);
        $this->assertGreaterThanOrEqual(0.60, $debit->metadata['transfer_detection']['score'] ?? 0);
        $signals = $debit->metadata['transfer_detection']['signals'] ?? null;
        $this->assertIsArray($signals);
        $this->assertContains('own_name_match', $signals);
        $this->assertContains('counterparty_account_match', $signals);
    }

    public function test_tier3_review_band_flags_without_pairing(): void
    {
        $user = User::factory()->create(['name' => 'Jána Kováč']);
        $accountA = Account::factory()->create(['user_id' => $user->id, 'iban' => null, 'name' => 'Account One', 'bank_name' => 'Bank One']);
        $accountB = Account::factory()->create(['user_id' => $user->id, 'iban' => null, 'name' => 'Account Two', 'bank_name' => 'Bank Two']);

        // Signals: own name (0.20) + same day (0.10) + exact amount (0.05) = 0.35.
        $debit = $this->makeTransaction($accountA, [
            'amount' => -80.00,
            'booked_date' => Carbon::parse('2025-05-10'),
            'partner' => 'JANA KOVAC',
            'description' => 'Outgoing payment',
        ]);
        $credit = $this->makeTransaction($accountB, [
            'amount' => 80.00,
            'type' => 'DEPOSIT',
            'booked_date' => Carbon::parse('2025-05-10'),
            'partner' => 'Incoming payment',
            'description' => 'Incoming payment',
        ]);

        $this->assertSame(0, $this->detect($user));
        $debit->refresh();
        $credit->refresh();
        $this->assertTrue($debit->needs_manual_review);
        $this->assertStringContainsString(TransferDetectionService::REVIEW_REASON_TRANSFER_HEURISTIC, (string) $debit->review_reason);
        $this->assertSame($credit->id, $debit->metadata['transfer_detection']['suggested_pair_id'] ?? null);
        $this->assertSame($debit->id, $credit->metadata['transfer_detection']['suggested_pair_id'] ?? null);
        $this->assertNotSame(Transaction::TYPE_TRANSFER, $debit->type);
    }

    public function test_tier3_ignores_low_scoring_coincidences(): void
    {
        $user = User::factory()->create(['name' => 'Jána Kováč']);
        $accountA = Account::factory()->create(['user_id' => $user->id, 'iban' => null, 'name' => 'Account One', 'bank_name' => 'Bank One']);
        $accountB = Account::factory()->create(['user_id' => $user->id, 'iban' => null, 'name' => 'Account Two', 'bank_name' => 'Bank Two']);

        // Same amount, same day, zero other signals: 0.15 < review threshold.
        $debit = $this->makeTransaction($accountA, [
            'amount' => -49.99,
            'booked_date' => Carbon::parse('2025-05-11'),
            'partner' => 'Grocery Store',
            'description' => 'Groceries',
        ]);
        $credit = $this->makeTransaction($accountB, [
            'amount' => 49.99,
            'type' => 'DEPOSIT',
            'booked_date' => Carbon::parse('2025-05-11'),
            'partner' => 'Marketplace refund',
            'description' => 'Refund',
        ]);

        $this->assertSame(0, $this->detect($user));
        $this->assertFalse($debit->refresh()->needs_manual_review);
        $this->assertFalse($credit->refresh()->needs_manual_review);
    }

    public function test_tier3_ambiguous_twins_are_flagged_not_guessed(): void
    {
        $user = User::factory()->create(['name' => 'Jána Kováč']);
        $accountA = Account::factory()->create(['user_id' => $user->id, 'iban' => null, 'name' => 'Account One', 'bank_name' => 'Bank One']);
        $accountB = Account::factory()->create(['user_id' => $user->id, 'iban' => null, 'name' => 'Account Two', 'bank_name' => 'Bank Two']);

        $date = Carbon::parse('2025-05-12');
        $legs = [];
        foreach ([[-60.00, $accountA], [-60.00, $accountA], [60.00, $accountB], [60.00, $accountB]] as $i => [$amount, $account]) {
            $legs[] = $this->makeTransaction($account, [
                'amount' => $amount,
                'type' => $amount < 0 ? 'PAYMENT' : 'DEPOSIT',
                'booked_date' => $date,
                'partner' => 'JANA KOVAC',
                'description' => 'Own transfer '.$i,
                'metadata' => ['transfer_candidate' => true],
            ]);
        }

        $this->assertSame(0, $this->detect($user));
        foreach ($legs as $leg) {
            $leg->refresh();
            $this->assertNotSame(Transaction::TYPE_TRANSFER, $leg->type);
            $this->assertStringContainsString(TransferDetectionService::REVIEW_REASON_TRANSFER_AMBIGUOUS, (string) $leg->review_reason);
        }
    }

    public function test_global_assignment_prefers_stronger_candidate_over_input_order(): void
    {
        $user = User::factory()->create(['name' => 'Jána Kováč']);
        $accountA = Account::factory()->create(['user_id' => $user->id, 'iban' => null, 'name' => 'Account One', 'bank_name' => 'Bank One']);
        $accountB = Account::factory()->create(['user_id' => $user->id, 'iban' => null, 'name' => 'Neobank Current', 'bank_name' => 'Neobank']);

        // Weak debit (earlier date, would win a naive first-come scan).
        $weakDebit = $this->makeTransaction($accountA, [
            'amount' => -120.00,
            'booked_date' => Carbon::parse('2025-05-19'),
            'partner' => 'JANA KOVAC',
            'description' => 'Outgoing',
        ]);
        // Strong debit: mentions the credit account's bank + transfer-ish code.
        $strongDebit = $this->makeTransaction($accountA, [
            'amount' => -120.00,
            'booked_date' => Carbon::parse('2025-05-20'),
            'partner' => 'JANA KOVAC',
            'description' => 'Neobank top-up',
            'bank_transaction_code' => 'TRANSFER',
        ]);
        $credit = $this->makeTransaction($accountB, [
            'amount' => 120.00,
            'type' => 'Topup',
            'booked_date' => Carbon::parse('2025-05-20'),
            'partner' => 'Payment from JANA KOVAC',
            'description' => 'Top-Up',
        ]);

        $this->assertSame(2, $this->detect($user));
        $this->assertSame($credit->id, $strongDebit->refresh()->transfer_pair_transaction_id);
        $this->assertNull($weakDebit->refresh()->transfer_pair_transaction_id);
    }

    // ---------------------------------------------------------- Cross-currency

    public function test_cross_currency_flags_for_review_by_default(): void
    {
        $user = User::factory()->create();
        $eurAccount = Account::factory()->create(['user_id' => $user->id, 'iban' => 'SK1111000000001111111111', 'currency' => 'EUR']);
        $usdAccount = Account::factory()->create(['user_id' => $user->id, 'iban' => null, 'currency' => 'USD']);

        $debit = $this->makeTransaction($eurAccount, [
            'amount' => -100.00,
            'currency' => 'EUR',
            'booked_date' => Carbon::parse('2025-06-02'),
        ]);
        $credit = $this->makeTransaction($usdAccount, [
            'amount' => 108.00,
            'currency' => 'USD',
            'type' => 'DEPOSIT',
            'booked_date' => Carbon::parse('2025-06-03'),
            'original_currency' => 'EUR',
            'original_amount' => 100.00,
        ]);

        $this->assertSame(0, $this->detect($user));
        $debit->refresh();
        $credit->refresh();
        $this->assertStringContainsString(TransferDetectionService::REVIEW_REASON_TRANSFER_CROSS_CURRENCY, (string) $debit->review_reason);
        $this->assertSame($credit->id, $debit->metadata['transfer_detection']['suggested_pair_id'] ?? null);
        $this->assertNotSame(Transaction::TYPE_TRANSFER, $debit->type);
    }

    public function test_cross_currency_auto_marks_when_enabled(): void
    {
        config(['transfers.cross_currency.auto_mark' => true]);

        $user = User::factory()->create();
        $eurAccount = Account::factory()->create(['user_id' => $user->id, 'iban' => 'SK1111000000001111111111', 'currency' => 'EUR']);
        $usdAccount = Account::factory()->create(['user_id' => $user->id, 'iban' => null, 'currency' => 'USD']);

        $debit = $this->makeTransaction($eurAccount, [
            'amount' => -100.00,
            'currency' => 'EUR',
            'booked_date' => Carbon::parse('2025-06-02'),
            'exchange_rate' => 1.08,
        ]);
        $credit = $this->makeTransaction($usdAccount, [
            'amount' => 108.00,
            'currency' => 'USD',
            'type' => 'DEPOSIT',
            'booked_date' => Carbon::parse('2025-06-02'),
        ]);

        $this->assertSame(2, $this->detect($user));
        $debit->refresh();
        $this->assertSame(Transaction::TYPE_TRANSFER, $debit->type);
        $this->assertSame($credit->id, $debit->transfer_pair_transaction_id);
        $this->assertSame('cross_currency', $debit->metadata['transfer_detection']['method'] ?? null);
    }

    public function test_cross_currency_rejects_rate_mismatch(): void
    {
        $user = User::factory()->create();
        $eurAccount = Account::factory()->create(['user_id' => $user->id, 'iban' => null, 'currency' => 'EUR']);
        $usdAccount = Account::factory()->create(['user_id' => $user->id, 'iban' => null, 'currency' => 'USD']);

        $debit = $this->makeTransaction($eurAccount, [
            'amount' => -100.00,
            'currency' => 'EUR',
            'booked_date' => Carbon::parse('2025-06-02'),
        ]);
        $credit = $this->makeTransaction($usdAccount, [
            'amount' => 90.00,
            'currency' => 'USD',
            'type' => 'DEPOSIT',
            'booked_date' => Carbon::parse('2025-06-02'),
            'original_currency' => 'EUR',
            'original_amount' => 82.00,
        ]);

        $this->assertSame(0, $this->detect($user));
        $this->assertFalse($debit->refresh()->needs_manual_review);
        $this->assertFalse($credit->refresh()->needs_manual_review);
    }

    // -------------------------------------------------------------- Single-leg

    public function test_single_leg_pocket_move_marked_even_with_one_account(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'iban' => null]);

        $pocketMove = $this->makeTransaction($account, [
            'amount' => -10.00,
            'booked_date' => Carbon::parse('2025-06-05'),
            'partner' => 'Vault',
            'description' => 'Vault | To EUR RoundUps',
            'metadata' => ['proprietaryBankTransactionCode' => 'TRANSFER'],
        ]);

        $this->assertSame(1, $this->detect($user));
        $pocketMove->refresh();
        $this->assertSame(Transaction::TYPE_TRANSFER, $pocketMove->type);
        $this->assertNull($pocketMove->transfer_pair_transaction_id);
        $this->assertTrue($pocketMove->metadata['single_leg_transfer'] ?? false);
        $this->assertSame('single_leg', $pocketMove->metadata['transfer_detection']['method'] ?? null);
    }

    public function test_single_leg_csv_path_via_transfer_candidate_and_pattern(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'iban' => null]);

        $pocketMove = $this->makeTransaction($account, [
            'amount' => -25.00,
            'booked_date' => Carbon::parse('2025-06-06'),
            'partner' => null,
            'description' => 'To pocket EUR Savings from EUR',
            'metadata' => ['transfer_candidate' => true],
        ]);

        $this->assertSame(1, $this->detect($user));
        $this->assertSame(Transaction::TYPE_TRANSFER, $pocketMove->refresh()->type);
    }

    public function test_single_leg_review_only_when_auto_mark_disabled(): void
    {
        config(['transfers.single_leg.auto_mark' => false]);

        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'iban' => null]);

        $pocketMove = $this->makeTransaction($account, [
            'amount' => -10.00,
            'booked_date' => Carbon::parse('2025-06-05'),
            'description' => 'To pocket EUR Savings from EUR',
            'metadata' => ['proprietaryBankTransactionCode' => 'TRANSFER'],
        ]);

        $this->assertSame(0, $this->detect($user));
        $pocketMove->refresh();
        $this->assertNotSame(Transaction::TYPE_TRANSFER, $pocketMove->type);
        $this->assertStringContainsString(TransferDetectionService::REVIEW_REASON_SINGLE_LEG_CANDIDATE, (string) $pocketMove->review_reason);
    }

    public function test_single_leg_marking_is_idempotent(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'iban' => null]);

        $this->makeTransaction($account, [
            'amount' => -10.00,
            'booked_date' => Carbon::parse('2025-06-05'),
            'description' => 'To pocket EUR Savings from EUR',
            'metadata' => ['proprietaryBankTransactionCode' => 'TRANSFER'],
        ]);

        $this->assertSame(1, $this->detect($user));
        $this->assertSame(0, $this->detect($user));
    }

    public function test_ordinary_expense_is_not_marked_single_leg(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'iban' => null]);

        $expense = $this->makeTransaction($account, [
            'amount' => -10.00,
            'booked_date' => Carbon::parse('2025-06-05'),
            'description' => 'Coffee shop',
            'metadata' => ['proprietaryBankTransactionCode' => 'TRANSFER'],
        ]);

        $this->assertSame(0, $this->detect($user));
        $this->assertNotSame(Transaction::TYPE_TRANSFER, $expense->refresh()->type);
    }
}
