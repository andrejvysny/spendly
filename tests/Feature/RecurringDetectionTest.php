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
}
