<?php

declare(strict_types=1);

namespace Tests\Unit\Listeners;

use App\Events\TransactionUpdated;
use App\Jobs\RetrainMlModelJob;
use App\Listeners\TrackManualCategorization;
use App\Models\Account;
use App\Models\MlPersonalizationSetting;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TrackManualCategorizationTest extends TestCase
{
    use RefreshDatabase;

    // ── helpers ───────────────────────────────────────────────────────────

    private function makeUserWithTransaction(): array
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $tx = Transaction::factory()->for($account, 'account')->create();

        return [$user, $tx];
    }

    // ── no category_id in changedAttributes ───────────────────────────────

    public function test_does_nothing_when_category_id_not_in_changed_attributes(): void
    {
        Bus::fake();
        [$user, $tx] = $this->makeUserWithTransaction();

        $event = new TransactionUpdated($tx, ['description' => 'new']);
        (new TrackManualCategorization)->handle($event);

        Bus::assertNotDispatched(RetrainMlModelJob::class);
        $this->assertNull(Cache::get(TrackManualCategorization::counterKey($user->id)));
    }

    public function test_does_nothing_when_changed_attributes_is_empty(): void
    {
        Bus::fake();
        [$user, $tx] = $this->makeUserWithTransaction();

        $event = new TransactionUpdated($tx, []);
        (new TrackManualCategorization)->handle($event);

        Bus::assertNotDispatched(RetrainMlModelJob::class);
    }

    // ── counter increments ────────────────────────────────────────────────

    public function test_increments_counter_when_category_id_changes(): void
    {
        Cache::flush();
        [$user, $tx] = $this->makeUserWithTransaction();
        MlPersonalizationSetting::create([
            'user_id' => $user->id,
            'auto_retrain' => false,
            'retrain_threshold' => 10,
        ]);

        $event = new TransactionUpdated($tx, ['category_id' => 5]);
        (new TrackManualCategorization)->handle($event);

        $this->assertEquals(1, Cache::get(TrackManualCategorization::counterKey($user->id)));
    }

    public function test_counter_accumulates_across_multiple_calls(): void
    {
        Cache::flush();
        [$user, $tx] = $this->makeUserWithTransaction();
        MlPersonalizationSetting::create([
            'user_id' => $user->id,
            'auto_retrain' => false,
            'retrain_threshold' => 100,
        ]);

        $listener = new TrackManualCategorization;
        $event = new TransactionUpdated($tx, ['category_id' => 5]);

        $listener->handle($event);
        $listener->handle($event);
        $listener->handle($event);

        $this->assertEquals(3, Cache::get(TrackManualCategorization::counterKey($user->id)));
    }

    // ── job dispatch / threshold logic ────────────────────────────────────

    public function test_does_not_dispatch_job_below_threshold(): void
    {
        Bus::fake();
        Cache::flush();
        [$user, $tx] = $this->makeUserWithTransaction();
        MlPersonalizationSetting::create([
            'user_id' => $user->id,
            'auto_retrain' => true,
            'retrain_threshold' => 5,
        ]);

        $listener = new TrackManualCategorization;
        $event = new TransactionUpdated($tx, ['category_id' => 5]);

        // 4 calls — one below threshold
        $listener->handle($event);
        $listener->handle($event);
        $listener->handle($event);
        $listener->handle($event);

        Bus::assertNotDispatched(RetrainMlModelJob::class);
    }

    public function test_dispatches_job_when_threshold_reached_and_auto_retrain_true(): void
    {
        Bus::fake();
        Cache::flush();
        [$user, $tx] = $this->makeUserWithTransaction();
        MlPersonalizationSetting::create([
            'user_id' => $user->id,
            'auto_retrain' => true,
            'retrain_threshold' => 3,
        ]);

        $listener = new TrackManualCategorization;
        $event = new TransactionUpdated($tx, ['category_id' => 5]);

        $listener->handle($event);
        $listener->handle($event);
        $listener->handle($event); // hits threshold

        Bus::assertDispatched(RetrainMlModelJob::class);
    }

    public function test_does_not_dispatch_when_auto_retrain_false(): void
    {
        Bus::fake();
        Cache::flush();
        [$user, $tx] = $this->makeUserWithTransaction();
        MlPersonalizationSetting::create([
            'user_id' => $user->id,
            'auto_retrain' => false,
            'retrain_threshold' => 1,
        ]);

        $event = new TransactionUpdated($tx, ['category_id' => 5]);
        (new TrackManualCategorization)->handle($event);

        Bus::assertNotDispatched(RetrainMlModelJob::class);
    }

    public function test_auto_creates_settings_with_defaults_when_none_exist(): void
    {
        Cache::flush();
        [$user, $tx] = $this->makeUserWithTransaction();
        // No MlPersonalizationSetting created — forUser() should create with defaults

        $event = new TransactionUpdated($tx, ['category_id' => 5]);
        (new TrackManualCategorization)->handle($event);

        $this->assertDatabaseHas('ml_personalization_settings', [
            'user_id' => $user->id,
            'auto_retrain' => false,    // default from forUser()
            'retrain_threshold' => 10,  // default from forUser()
        ]);
    }

    // ── counterKey helper ─────────────────────────────────────────────────

    public function test_counter_key_format(): void
    {
        $this->assertEquals('ml:manual_categorization_count:42', TrackManualCategorization::counterKey(42));
    }

    public function test_counter_keys_are_user_isolated(): void
    {
        Cache::flush();
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $account1 = Account::factory()->for($user1)->create();
        $account2 = Account::factory()->for($user2)->create();
        $tx1 = Transaction::factory()->for($account1, 'account')->create();
        $tx2 = Transaction::factory()->for($account2, 'account')->create();

        MlPersonalizationSetting::create(['user_id' => $user1->id, 'auto_retrain' => false, 'retrain_threshold' => 100]);
        MlPersonalizationSetting::create(['user_id' => $user2->id, 'auto_retrain' => false, 'retrain_threshold' => 100]);

        $listener = new TrackManualCategorization;
        $listener->handle(new TransactionUpdated($tx1, ['category_id' => 1]));
        $listener->handle(new TransactionUpdated($tx1, ['category_id' => 1]));
        $listener->handle(new TransactionUpdated($tx2, ['category_id' => 1]));

        $this->assertEquals(2, Cache::get(TrackManualCategorization::counterKey($user1->id)));
        $this->assertEquals(1, Cache::get(TrackManualCategorization::counterKey($user2->id)));
    }
}
