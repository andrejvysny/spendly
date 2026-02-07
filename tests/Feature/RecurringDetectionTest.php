<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\Repositories\TransactionRepositoryInterface;
use App\Jobs\RecurringDetectionJob;
use App\Models\Account;
use App\Models\RecurringGroup;
use App\Models\RecurringDetectionSetting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\RecurringDetectionService;
use Carbon\Carbon;
use Tests\TestCase;

class RecurringDetectionTest extends TestCase
{
    public function test_repository_returns_transactions_for_detection(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $dates = [Carbon::parse('2025-11-01'), Carbon::parse('2025-12-01'), Carbon::parse('2026-01-01')];
        foreach ($dates as $i => $date) {
            Transaction::factory()->create([
                'account_id' => $account->id,
                'transaction_id' => 'REPO-TEST-'.$user->id.'-'.$i,
                'description' => 'Netflix Subscription',
                'amount' => -12.99,
                'booked_date' => $date,
                'processed_date' => $date,
                'type' => 'PAYMENT',
            ]);
        }

        $repo = $this->app->make(TransactionRepositoryInterface::class);
        $from = Carbon::now()->subMonths(12);
        $to = Carbon::now();
        $txs = $repo->getForRecurringDetection((int) $user->id, $from, $to, (int) $account->id);

        $this->assertSame(3, $txs->count(), 'Repository should return all 3 Netflix transactions');

        $txs = $txs->sortBy('booked_date')->values();
        $this->assertSame(30, (int) abs($txs->get(1)->booked_date->startOfDay()->diffInDays($txs->get(0)->booked_date->startOfDay())), 'Delta between first and second should be 30 days');
        $this->assertSame(31, (int) abs($txs->get(2)->booked_date->startOfDay()->diffInDays($txs->get(1)->booked_date->startOfDay())), 'Delta between second and third should be 31 days');
        $this->assertSame(-12.99, (float) $txs->get(0)->amount, 'Amount should be -12.99');
    }

    public function test_detects_netflix_subscription_as_suggested_recurring_group(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);

        $amount = -12.99;
        $dates = [
            Carbon::parse('2025-11-01')->startOfDay(),
            Carbon::parse('2025-12-01')->startOfDay(),
            Carbon::parse('2026-01-01')->startOfDay(),
        ];

        foreach ($dates as $i => $date) {
            Transaction::factory()->create([
                'account_id' => $account->id,
                'transaction_id' => 'NETFLIX-TEST-'.$user->id.'-'.$i,
                'description' => 'Netflix Subscription',
                'amount' => $amount,
                'booked_date' => $date,
                'processed_date' => $date,
                'type' => 'PAYMENT',
            ]);
        }

        $service = $this->app->make(RecurringDetectionService::class);
        $service->runForUser((int) $user->id, null);

        $suggested = RecurringGroup::where('user_id', $user->id)
            ->where('status', RecurringGroup::STATUS_SUGGESTED)
            ->get();

        $this->assertNotEmpty($suggested, 'Expected at least one suggested recurring group after detection');
        $netflixGroup = $suggested->first(fn (RecurringGroup $g) => stripos($g->name, 'netflix') !== false);
        $this->assertNotNull($netflixGroup, 'Expected a suggested group whose name contains "netflix"');
        $this->assertSame(RecurringGroup::INTERVAL_MONTHLY, $netflixGroup->interval);
    }

    public function test_groups_variant_descriptions_under_same_payee(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);

        $amount = -9.99;
        $dates = [
            Carbon::parse('2025-11-01'),
            Carbon::parse('2025-12-01'),
            Carbon::parse('2026-01-01'),
        ];
        $descriptions = ['Netflix', 'Netflix Subscription', 'NETFLIX PAYMENT'];

        foreach ($dates as $i => $date) {
            Transaction::factory()->create([
                'account_id' => $account->id,
                'transaction_id' => 'NETFLIX-VAR-'.$user->id.'-'.$i,
                'description' => $descriptions[$i],
                'amount' => $amount,
                'booked_date' => $date,
                'processed_date' => $date,
                'type' => 'PAYMENT',
            ]);
        }

        $service = $this->app->make(RecurringDetectionService::class);
        $service->runForUser((int) $user->id, null);

        $netflixGroup = RecurringGroup::where('user_id', $user->id)
            ->where('status', RecurringGroup::STATUS_SUGGESTED)
            ->whereRaw('LOWER(name) LIKE ?', ['%netflix%'])
            ->first();

        $this->assertNotNull($netflixGroup, 'Expected variant descriptions (Netflix, Netflix Subscription, NETFLIX PAYMENT) to be grouped into one suggested recurring group');
    }

    public function test_detection_runs_after_sync_import_and_recognizes_subscription(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);

        RecurringDetectionSetting::forUser($user->id);

        $amount = -12.99;
        $dates = [
            Carbon::parse('2025-11-01')->startOfDay(),
            Carbon::parse('2025-12-01')->startOfDay(),
            Carbon::parse('2026-01-01')->startOfDay(),
        ];

        foreach ($dates as $i => $date) {
            Transaction::factory()->create([
                'account_id' => $account->id,
                'transaction_id' => 'NETFLIX-JOB-'.$user->id.'-'.$i,
                'description' => 'Netflix Subscription',
                'amount' => $amount,
                'booked_date' => $date,
                'processed_date' => $date,
                'type' => 'PAYMENT',
            ]);
        }

        RecurringDetectionJob::dispatchSync($user->id, $account->id);

        $suggested = RecurringGroup::where('user_id', $user->id)
            ->where('status', RecurringGroup::STATUS_SUGGESTED)
            ->get();

        $this->assertNotEmpty($suggested, 'Expected at least one suggested recurring group after job runs');
        $netflixGroup = $suggested->first(fn (RecurringGroup $g) => stripos($g->name, 'netflix') !== false);
        $this->assertNotNull($netflixGroup, 'Expected a suggested group whose name contains "netflix" so subscriptions are recognized and added to Recurring payments');
    }

    public function test_unlink_detaches_transactions_removes_tag_and_deletes_group(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);

        $group = RecurringGroup::create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'status' => RecurringGroup::STATUS_CONFIRMED,
            'name' => 'Test Subscription',
            'interval' => RecurringGroup::INTERVAL_MONTHLY,
            'scope' => RecurringGroup::SCOPE_PER_ACCOUNT,
            'amount_min' => -9.99,
            'amount_max' => -9.99,
        ]);

        $tx1 = Transaction::factory()->create([
            'account_id' => $account->id,
            'recurring_group_id' => $group->id,
            'description' => 'Test payment',
            'amount' => -9.99,
        ]);
        $tx2 = Transaction::factory()->create([
            'account_id' => $account->id,
            'recurring_group_id' => $group->id,
            'description' => 'Test payment 2',
            'amount' => -9.99,
        ]);

        $groupId = $group->id;
        $service = $this->app->make(RecurringDetectionService::class);
        $service->unlinkGroup($group, true);

        $this->assertNull(RecurringGroup::find($groupId), 'RecurringGroup should be deleted after unlink');
        $this->assertNull($tx1->fresh()->recurring_group_id, 'Transaction 1 should have recurring_group_id cleared');
        $this->assertNull($tx2->fresh()->recurring_group_id, 'Transaction 2 should have recurring_group_id cleared');
    }

    public function test_detach_transactions_removes_only_given_transactions_group_remains(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);

        $group = RecurringGroup::create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'status' => RecurringGroup::STATUS_CONFIRMED,
            'name' => 'Test Subscription',
            'interval' => RecurringGroup::INTERVAL_MONTHLY,
            'scope' => RecurringGroup::SCOPE_PER_ACCOUNT,
            'amount_min' => -9.99,
            'amount_max' => -9.99,
        ]);

        $tx1 = Transaction::factory()->create([
            'account_id' => $account->id,
            'recurring_group_id' => $group->id,
            'description' => 'Payment 1',
            'amount' => -9.99,
        ]);
        $tx2 = Transaction::factory()->create([
            'account_id' => $account->id,
            'recurring_group_id' => $group->id,
            'description' => 'Payment 2',
            'amount' => -9.99,
        ]);
        $tx3 = Transaction::factory()->create([
            'account_id' => $account->id,
            'recurring_group_id' => $group->id,
            'description' => 'Payment 3',
            'amount' => -9.99,
        ]);

        $service = $this->app->make(RecurringDetectionService::class);
        $service->detachTransactionsFromGroup($group, [$tx1->id, $tx3->id], true);

        $this->assertNotNull(RecurringGroup::find($group->id), 'Group should still exist');
        $this->assertNull($tx1->fresh()->recurring_group_id, 'Transaction 1 should be detached');
        $this->assertSame($group->id, $tx2->fresh()->recurring_group_id, 'Transaction 2 should remain linked');
        $this->assertNull($tx3->fresh()->recurring_group_id, 'Transaction 3 should be detached');
    }

    public function test_attach_transactions_links_eligible_transactions_scope_aware(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);

        $group = RecurringGroup::create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'status' => RecurringGroup::STATUS_CONFIRMED,
            'name' => 'Test Subscription',
            'interval' => RecurringGroup::INTERVAL_MONTHLY,
            'scope' => RecurringGroup::SCOPE_PER_ACCOUNT,
            'amount_min' => -9.99,
            'amount_max' => -9.99,
        ]);

        $txUnlinked = Transaction::factory()->create([
            'account_id' => $account->id,
            'recurring_group_id' => null,
            'description' => 'Netflix',
            'amount' => -9.99,
        ]);

        $service = $this->app->make(RecurringDetectionService::class);
        $result = $service->attachTransactionsToGroup($group, [$txUnlinked->id], true);

        $this->assertSame([$txUnlinked->id], $result['attached']);
        $this->assertSame([], $result['ineligible']);
        $this->assertSame($group->id, $txUnlinked->fresh()->recurring_group_id);
    }

    public function test_attach_transactions_reports_ineligible_when_already_in_another_group(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);

        $group1 = RecurringGroup::create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'status' => RecurringGroup::STATUS_CONFIRMED,
            'name' => 'Group 1',
            'interval' => RecurringGroup::INTERVAL_MONTHLY,
            'scope' => RecurringGroup::SCOPE_PER_ACCOUNT,
            'amount_min' => -10,
            'amount_max' => -10,
        ]);
        $group2 = RecurringGroup::create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'status' => RecurringGroup::STATUS_CONFIRMED,
            'name' => 'Group 2',
            'interval' => RecurringGroup::INTERVAL_MONTHLY,
            'scope' => RecurringGroup::SCOPE_PER_ACCOUNT,
            'amount_min' => -5,
            'amount_max' => -5,
        ]);

        $txInGroup1 = Transaction::factory()->create([
            'account_id' => $account->id,
            'recurring_group_id' => $group1->id,
            'description' => 'Payment',
            'amount' => -10,
        ]);
        $txUnlinked = Transaction::factory()->create([
            'account_id' => $account->id,
            'recurring_group_id' => null,
            'description' => 'Other',
            'amount' => -5,
        ]);

        $service = $this->app->make(RecurringDetectionService::class);
        $result = $service->attachTransactionsToGroup($group2, [$txInGroup1->id, $txUnlinked->id], false);

        $this->assertSame([$txUnlinked->id], $result['attached']);
        $this->assertSame([$txInGroup1->id], $result['ineligible']);
        $this->assertSame($group1->id, $txInGroup1->fresh()->recurring_group_id);
        $this->assertSame($group2->id, $txUnlinked->fresh()->recurring_group_id);
    }

    public function test_index_includes_stats_for_confirmed_groups(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);

        $group = RecurringGroup::create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'status' => RecurringGroup::STATUS_CONFIRMED,
            'name' => 'Stats Test',
            'interval' => RecurringGroup::INTERVAL_MONTHLY,
            'scope' => RecurringGroup::SCOPE_PER_ACCOUNT,
            'amount_min' => -10,
            'amount_max' => -10,
        ]);

        Transaction::factory()->create([
            'account_id' => $account->id,
            'recurring_group_id' => $group->id,
            'description' => 'Pay 1',
            'amount' => -10,
            'booked_date' => '2025-01-15',
        ]);
        Transaction::factory()->create([
            'account_id' => $account->id,
            'recurring_group_id' => $group->id,
            'description' => 'Pay 2',
            'amount' => -10,
            'booked_date' => '2025-02-15',
        ]);

        $response = $this->actingAs($user)->getJson('/api/recurring');

        $response->assertOk();
        $confirmed = $response->json('data.confirmed');
        $this->assertNotEmpty($confirmed);
        $first = collect($confirmed)->firstWhere('id', $group->id);
        $this->assertNotNull($first, 'Confirmed group should be in response');
        $this->assertArrayHasKey('stats', $first);
        $stats = $first['stats'];
        $this->assertSame(2, $stats['transactions_count']);
        $this->assertSame(-20.0, (float) $stats['total_paid']);
        $this->assertSame('2025-01-15', $stats['first_payment_date']);
        $this->assertSame('2025-02-15', $stats['last_payment_date']);
        $this->assertSame(-10.0, (float) $stats['average_amount']);
        $this->assertLessThan(0, (float) $stats['projected_yearly_cost'], 'Projected yearly should be negative for expenses');
        $this->assertNotNull($stats['next_expected_payment']);
    }

    public function test_transfers_are_excluded_from_recurring_detection(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);

        $dates = [
            Carbon::parse('2025-11-01'),
            Carbon::parse('2025-12-01'),
            Carbon::parse('2026-01-01'),
        ];
        foreach ($dates as $i => $date) {
            Transaction::factory()->create([
                'account_id' => $account->id,
                'transaction_id' => 'TRANSFER-'.$user->id.'-'.$i,
                'description' => 'To savings',
                'amount' => -100.00,
                'booked_date' => $date,
                'processed_date' => $date,
                'type' => Transaction::TYPE_TRANSFER,
            ]);
        }

        $repo = $this->app->make(TransactionRepositoryInterface::class);
        $from = Carbon::now()->subMonths(12);
        $to = Carbon::now();
        $txs = $repo->getForRecurringDetection((int) $user->id, $from, $to, (int) $account->id);

        $this->assertSame(0, $txs->count(), 'TYPE_TRANSFER transactions must be excluded from recurring detection');

        $service = $this->app->make(RecurringDetectionService::class);
        $service->runForUser((int) $user->id, null);

        $suggested = RecurringGroup::where('user_id', $user->id)->where('status', RecurringGroup::STATUS_SUGGESTED)->get();
        $transferGroup = $suggested->first(fn (RecurringGroup $g) => stripos($g->name, 'savings') !== false || stripos($g->name, 'transfer') !== false);
        $this->assertNull($transferGroup, 'Transfers must not be suggested as recurring');
    }
}
