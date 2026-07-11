<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\RecurringDetectionSetting;
use App\Models\RecurringGroup;
use App\Models\Transaction;
use App\Models\User;
use App\Services\RecurringDetectionService;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Algorithm v2: robust interval fit (quorum, k x interval for skipped
 * occurrences), price plateaus, amount clustering, currency-aware grouping,
 * confidence scoring, and the amount-independent v2 fingerprint.
 */
class RecurringDetectionV2Test extends TestCase
{
    private User $user;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-01'));
        $this->user = User::factory()->create();
        $this->account = Account::factory()->create(['user_id' => $this->user->id, 'currency' => 'EUR']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  array<int, array{0: string, 1: float}>  $rows  [date, amount] pairs
     * @param  array<string, mixed>  $overrides
     */
    private function makeSeries(Account $account, string $description, array $rows, array $overrides = []): void
    {
        foreach ($rows as $i => [$date, $amount]) {
            Transaction::factory()->create(array_merge([
                'account_id' => $account->id,
                'transaction_id' => 'V2-'.$account->id.'-'.md5($description).'-'.$i.'-'.uniqid('', true),
                'description' => $description,
                'partner' => $description,
                'amount' => $amount,
                'currency' => 'EUR',
                'booked_date' => Carbon::parse($date),
                'processed_date' => Carbon::parse($date),
                'type' => 'PAYMENT',
                'counterparty_id' => null,
                'metadata' => null,
            ], $overrides));
        }
    }

    private function detect(): void
    {
        $this->app->make(RecurringDetectionService::class)->runForUser((int) $this->user->id, null);
    }

    /**
     * @return \Illuminate\Support\Collection<int, RecurringGroup>
     */
    private function suggestedGroups(): \Illuminate\Support\Collection
    {
        return RecurringGroup::where('user_id', $this->user->id)
            ->where('status', RecurringGroup::STATUS_SUGGESTED)
            ->get();
    }

    /**
     * @return array<mixed, mixed>
     */
    private function snapshot(RecurringGroup $group): array
    {
        return $group->detection_config_snapshot ?? [];
    }

    /**
     * @return array<int, mixed>
     */
    private function snapshotList(RecurringGroup $group, string $key): array
    {
        $value = $this->snapshot($group)[$key] ?? null;
        $this->assertIsArray($value);

        return array_values($value);
    }

    public function test_price_increase_stays_one_series_with_updated_current_amount(): void
    {
        $rows = [];
        foreach (['2025-09-05', '2025-10-05', '2025-11-05', '2025-12-05', '2026-01-05', '2026-02-05'] as $date) {
            $rows[] = [$date, -12.99];
        }
        foreach (['2026-03-05', '2026-04-05', '2026-05-05', '2026-06-05'] as $date) {
            $rows[] = [$date, -15.99];
        }
        $this->makeSeries($this->account, 'Streaming Service', $rows);

        $this->detect();

        $groups = $this->suggestedGroups();
        $this->assertCount(1, $groups, 'A +23% price step must not split or reject the series');
        $group = $groups->firstOrFail();
        $this->assertSame(RecurringGroup::INTERVAL_MONTHLY, $group->interval);
        $this->assertEqualsWithDelta(-15.99, (float) $group->amount_current, 0.001);
        $this->assertLessThanOrEqual(-15.99, (float) $group->amount_min);
        $this->assertGreaterThanOrEqual(-12.99, (float) $group->amount_max);
        $this->assertGreaterThanOrEqual(90, (int) $group->confidence);
        $this->assertSame([-12.99, -15.99], $this->snapshotList($group, 'plateaus'));
    }

    public function test_billing_date_drift_does_not_break_monthly_series(): void
    {
        // Billing day slides 28th -> month-end -> 2nd/3rd (weekend + February effects).
        $dates = ['2025-10-28', '2025-11-28', '2025-12-29', '2026-02-02', '2026-03-03', '2026-04-03', '2026-05-04', '2026-06-04'];
        $this->makeSeries($this->account, 'Gym Membership', array_map(fn ($d) => [$d, -29.90], $dates));

        $this->detect();

        $groups = $this->suggestedGroups();
        $this->assertCount(1, $groups);
        $this->assertSame(RecurringGroup::INTERVAL_MONTHLY, $groups->firstOrFail()->interval);
    }

    public function test_skipped_month_is_tolerated_as_missed_occurrence(): void
    {
        // January missing: 62-day gap = 2 x monthly.
        $dates = ['2025-10-01', '2025-11-01', '2025-12-01', '2026-02-01', '2026-03-01', '2026-04-01', '2026-05-01', '2026-06-01'];
        $this->makeSeries($this->account, 'Cloud Storage', array_map(fn ($d) => [$d, -4.99], $dates));

        $this->detect();

        $groups = $this->suggestedGroups();
        $this->assertCount(1, $groups);
        $group = $groups->firstOrFail();
        $this->assertSame(RecurringGroup::INTERVAL_MONTHLY, $group->interval);
        $this->assertSame(1, $this->snapshot($group)['missed_count'] ?? null);
        $this->assertGreaterThanOrEqual(90, (int) $group->confidence);
    }

    public function test_single_refund_outlier_does_not_reject_series(): void
    {
        $rows = [];
        foreach (['2025-11-10', '2025-12-10', '2026-01-10', '2026-02-10', '2026-03-10', '2026-04-10', '2026-05-10', '2026-06-10'] as $date) {
            $rows[] = [$date, -15.99];
        }
        $rows[] = ['2026-03-12', 15.99]; // refund two days after a charge
        usort($rows, fn ($a, $b) => strcmp($a[0], $b[0]));
        $this->makeSeries($this->account, 'Music Service', $rows);

        $this->detect();

        $groups = $this->suggestedGroups();
        $this->assertCount(1, $groups);
        $group = $groups->firstOrFail();
        $refundId = Transaction::where('account_id', $this->account->id)->where('amount', 15.99)->value('id');
        $this->assertContains($refundId, $this->snapshotList($group, 'amount_outlier_transaction_ids'));
    }

    public function test_biweekly_series_detected_as_biweekly_not_weekly(): void
    {
        $rows = [];
        $date = Carbon::parse('2026-03-20');
        for ($i = 0; $i < 8; $i++) {
            $rows[] = [$date->toDateString(), -19.99];
            $date = $date->copy()->addDays(14);
        }
        $this->makeSeries($this->account, 'Cleaning Service', $rows);

        $this->detect();

        $groups = $this->suggestedGroups();
        $this->assertCount(1, $groups);
        $this->assertSame(RecurringGroup::INTERVAL_BIWEEKLY, $groups->firstOrFail()->interval);
    }

    public function test_clean_weekly_series_survives_high_frequency_guard(): void
    {
        $rows = [];
        $date = Carbon::parse('2026-04-27');
        for ($i = 0; $i < 10; $i++) {
            $rows[] = [$date->toDateString(), -12.50];
            $date = $date->copy()->addDays(7);
        }
        $this->makeSeries($this->account, 'Weekly Box', $rows);

        $this->detect();

        $groups = $this->suggestedGroups();
        $this->assertCount(1, $groups);
        $this->assertSame(RecurringGroup::INTERVAL_WEEKLY, $groups->firstOrFail()->interval);
    }

    public function test_semiannual_series_detected(): void
    {
        $this->makeSeries($this->account, 'Insurance Premium', [
            ['2025-01-15', -180.00],
            ['2025-07-15', -180.00],
            ['2026-01-15', -180.00],
        ]);

        $this->detect();

        $groups = $this->suggestedGroups();
        $this->assertCount(1, $groups);
        $this->assertSame(RecurringGroup::INTERVAL_SEMIANNUAL, $groups->firstOrFail()->interval);
    }

    public function test_yearly_detected_with_two_occurrences_despite_higher_user_minimum(): void
    {
        // Default user min_occurrences = 3; yearly uses the per-interval floor of 2.
        $this->makeSeries($this->account, 'Domain Renewal', [
            ['2025-03-15', -59.99],
            ['2026-03-14', -59.99],
        ]);

        $this->detect();

        $groups = $this->suggestedGroups();
        $this->assertCount(1, $groups);
        $this->assertSame(RecurringGroup::INTERVAL_YEARLY, $groups->firstOrFail()->interval);
    }

    public function test_single_transaction_yields_nothing(): void
    {
        $this->makeSeries($this->account, 'One Off', [['2026-03-15', -59.99]]);

        $this->detect();

        $this->assertCount(0, $this->suggestedGroups());
    }

    public function test_currencies_split_into_separate_series(): void
    {
        $rows = ['2026-01-03', '2026-02-03', '2026-03-03', '2026-04-03', '2026-05-03', '2026-06-03'];
        $this->makeSeries($this->account, 'Dual Currency Sub', array_map(fn ($d) => [$d, -9.99], $rows));
        $this->makeSeries($this->account, 'Dual Currency Sub', array_map(fn ($d) => [Carbon::parse($d)->addDay()->toDateString(), -9.99], $rows), ['currency' => 'USD']);

        $this->detect();

        $groups = $this->suggestedGroups();
        $this->assertCount(2, $groups);
        $this->assertEqualsCanonicalizing(['EUR', 'USD'], $groups->pluck('currency')->all());
    }

    public function test_two_same_payee_subscriptions_split_by_amount_clustering(): void
    {
        $rows = [];
        foreach (['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06'] as $month) {
            $rows[] = [$month.'-03', -7.99];
            $rows[] = [$month.'-17', -49.00];
        }
        $this->makeSeries($this->account, 'Cloud Provider', $rows);

        $this->detect();

        $groups = $this->suggestedGroups();
        $this->assertCount(2, $groups, 'Interleaved differently-priced subscriptions must split into two series');
        $currents = $groups->map(fn (RecurringGroup $g) => (float) $g->amount_current)->sort()->values()->all();
        $this->assertEqualsWithDelta(-49.00, $currents[0], 0.001);
        $this->assertEqualsWithDelta(-7.99, $currents[1], 0.001);
        $this->assertCount(2, $groups->pluck('dismissal_fingerprint')->unique());
    }

    public function test_habitual_merchant_is_not_suggested(): void
    {
        $rows = [];
        $date = Carbon::parse('2026-05-01');
        foreach ([2, 3, 5, 2, 6, 3, 2, 4, 3, 2, 5, 3, 2, 4, 6, 2, 3, 4, 2] as $i => $gap) {
            $rows[] = [$date->toDateString(), -8.0 - ($i % 5)];
            $date = $date->copy()->addDays($gap);
        }
        $this->makeSeries($this->account, 'Grocery Store', $rows);

        $this->detect();

        $this->assertCount(0, $this->suggestedGroups());
    }

    public function test_fixed_variance_setting_controls_amount_tolerance(): void
    {
        $settings = RecurringDetectionSetting::forUser((int) $this->user->id);
        $settings->update(['amount_variance_type' => RecurringDetectionSetting::AMOUNT_VARIANCE_FIXED, 'amount_variance_value' => 1.00]);

        $rows = [
            ['2026-01-10', -10.00], ['2026-02-10', -10.80], ['2026-03-10', -10.20],
            ['2026-04-10', -10.50], ['2026-05-10', -10.10], ['2026-06-10', -10.90],
        ];
        $this->makeSeries($this->account, 'Utility Bill', $rows);

        $this->detect();
        $this->assertCount(1, $this->suggestedGroups());

        // Tighten tolerance below the observed variance: series no longer accepted.
        $settings->update(['amount_variance_value' => 0.10]);
        $this->detect();
        $this->assertCount(0, $this->suggestedGroups());
    }

    public function test_dismissal_survives_price_change(): void
    {
        $rows = array_map(fn ($d) => [$d, -9.99], ['2026-01-05', '2026-02-05', '2026-03-05', '2026-04-05', '2026-05-05']);
        $this->makeSeries($this->account, 'News Site', $rows);

        $service = $this->app->make(RecurringDetectionService::class);
        $service->runForUser((int) $this->user->id, null);

        $group = $this->suggestedGroups()->firstOrFail();
        $service->dismissGroup($group);

        // Price hike of +25% arrives after dismissal.
        $this->makeSeries($this->account, 'News Site', [['2026-06-05', -12.49]]);
        $service->runForUser((int) $this->user->id, null);

        $this->assertCount(0, $this->suggestedGroups(), 'v2 fingerprint has no amounts: dismissal must survive a price change');
    }

    public function test_confirmed_group_not_re_suggested_after_price_change(): void
    {
        $rows = array_map(fn ($d) => [$d, -9.99], ['2026-01-05', '2026-02-05', '2026-03-05', '2026-04-05', '2026-05-05']);
        $this->makeSeries($this->account, 'Podcast App', $rows);

        $service = $this->app->make(RecurringDetectionService::class);
        $service->runForUser((int) $this->user->id, null);
        $service->confirmGroup($this->suggestedGroups()->firstOrFail());

        $this->makeSeries($this->account, 'Podcast App', [['2026-06-05', -12.49]]);
        $service->runForUser((int) $this->user->id, null);

        $this->assertCount(0, $this->suggestedGroups());
        $this->assertSame(1, RecurringGroup::where('user_id', $this->user->id)->count());
    }

    public function test_suggestions_upsert_in_place_across_runs(): void
    {
        $rows = array_map(fn ($d) => [$d, -9.99], ['2026-01-05', '2026-02-05', '2026-03-05', '2026-04-05']);
        $this->makeSeries($this->account, 'VPN Service', $rows);

        $this->detect();
        $group = $this->suggestedGroups()->firstOrFail();
        $originalId = $group->id;

        $this->makeSeries($this->account, 'VPN Service', [['2026-05-05', -9.99]]);
        $this->detect();

        $groups = $this->suggestedGroups();
        $this->assertCount(1, $groups);
        $this->assertSame($originalId, $groups->firstOrFail()->id, 'Re-runs must update the same row, not delete/recreate');
        $this->assertSame('2026-05-05', $groups->firstOrFail()->last_date?->toDateString());
    }

    public function test_stale_suggestions_are_reconciled(): void
    {
        $rows = array_map(fn ($d) => [$d, -9.99], ['2026-01-05', '2026-02-05', '2026-03-05', '2026-04-05']);
        $this->makeSeries($this->account, 'Old Service', $rows);

        $this->detect();
        $this->assertCount(1, $this->suggestedGroups());

        Transaction::where('account_id', $this->account->id)->delete();
        $this->detect();

        $this->assertCount(0, $this->suggestedGroups());
    }

    public function test_confidence_reflects_series_strength(): void
    {
        $longRows = [];
        $date = Carbon::parse('2025-07-05');
        for ($i = 0; $i < 12; $i++) {
            $longRows[] = [$date->toDateString(), -9.99];
            $date = $date->copy()->addMonthNoOverflow();
        }
        $this->makeSeries($this->account, 'Long Series', $longRows);
        $this->makeSeries($this->account, 'Short Series', array_map(fn ($d) => [$d, -5.99], ['2026-04-02', '2026-05-02', '2026-06-02']));

        $this->detect();

        $groups = $this->suggestedGroups();
        $long = $groups->firstWhere('name', 'Long Series');
        $short = $groups->firstWhere('name', 'Short Series');
        $this->assertNotNull($long);
        $this->assertNotNull($short);
        $this->assertGreaterThan((int) $short->confidence, (int) $long->confidence);
    }

    public function test_per_user_scope_groups_across_accounts(): void
    {
        $settings = RecurringDetectionSetting::forUser((int) $this->user->id);
        $settings->update(['scope' => RecurringDetectionSetting::SCOPE_PER_USER]);
        $secondAccount = Account::factory()->create(['user_id' => $this->user->id, 'currency' => 'EUR']);

        // Subscription paid alternately from two accounts.
        $this->makeSeries($this->account, 'Family Plan', [['2026-01-05', -14.99], ['2026-03-05', -14.99], ['2026-05-05', -14.99]]);
        $this->makeSeries($secondAccount, 'Family Plan', [['2026-02-05', -14.99], ['2026-04-05', -14.99], ['2026-06-05', -14.99]]);

        $this->detect();

        $groups = $this->suggestedGroups();
        $this->assertCount(1, $groups);
        $this->assertSame(RecurringGroup::SCOPE_PER_USER, $groups->firstOrFail()->scope);
        $this->assertSame(RecurringGroup::INTERVAL_MONTHLY, $groups->firstOrFail()->interval);
        $this->assertCount(6, $this->snapshotList($groups->firstOrFail(), 'transaction_ids'));
    }

    public function test_counterparty_only_mode_skips_transactions_without_counterparty(): void
    {
        $settings = RecurringDetectionSetting::forUser((int) $this->user->id);
        $settings->update(['group_by' => RecurringDetectionSetting::GROUP_BY_COUNTERPARTY_ONLY]);

        $rows = array_map(fn ($d) => [$d, -9.99], ['2026-01-05', '2026-02-05', '2026-03-05', '2026-04-05']);
        $this->makeSeries($this->account, 'No Counterparty Sub', $rows);

        $this->detect();

        $this->assertCount(0, $this->suggestedGroups(), 'counterparty_only must not fall back to description grouping');
    }
}
