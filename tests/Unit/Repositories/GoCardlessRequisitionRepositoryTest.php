<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Enums\GoCardlessRequisitionStatus;
use App\Models\Account;
use App\Models\GoCardlessRequisition;
use App\Models\User;
use App\Repositories\GoCardlessRequisitionRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoCardlessRequisitionRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private GoCardlessRequisitionRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new GoCardlessRequisitionRepository(new GoCardlessRequisition);
    }

    public function test_create_for_user_sets_user_id_and_persists_reference(): void
    {
        $user = User::factory()->create();

        $requisition = $this->repository->createForUser($user->id, [
            'requisition_id' => 'req-123',
            'reference' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'institution_id' => 'SANDBOXFINANCE_SFIN0000',
            'status' => GoCardlessRequisitionStatus::PENDING,
        ]);

        $this->assertSame($user->id, $requisition->user_id);
        $this->assertSame('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $requisition->reference);
        $this->assertDatabaseHas('gocardless_requisitions', [
            'id' => $requisition->id,
            'user_id' => $user->id,
            'reference' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        ]);
    }

    public function test_create_for_user_ignores_user_id_in_data_payload(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $requisition = $this->repository->createForUser($owner->id, [
            'requisition_id' => 'req-123',
            'reference' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'institution_id' => 'SANDBOXFINANCE_SFIN0000',
            'status' => GoCardlessRequisitionStatus::PENDING,
            'user_id' => $attacker->id,
        ]);

        $this->assertSame($owner->id, $requisition->user_id);
    }

    public function test_find_by_reference_returns_matching_requisition(): void
    {
        $requisition = GoCardlessRequisition::factory()->create(['reference' => 'ref-find-me']);
        GoCardlessRequisition::factory()->create(['reference' => 'ref-other']);

        $found = $this->repository->findByReference('ref-find-me');

        $this->assertNotNull($found);
        $this->assertSame($requisition->id, $found->id);
        $this->assertNull($this->repository->findByReference('does-not-exist'));
    }

    public function test_upsert_from_remote_creates_a_row_with_a_reference(): void
    {
        $user = User::factory()->create();

        $requisition = $this->repository->upsertFromRemote($user->id, [
            'id' => 'req-remote-1',
            'status' => 'LN',
            'institution_id' => 'SANDBOXFINANCE_SFIN0000',
            'agreement' => 'agr-1',
            'reference' => 'ref-from-remote',
            'accounts' => ['acct-1', 'acct-2'],
            'link' => 'https://ob.gocardless.com/psd2/start/x',
        ]);

        $this->assertSame($user->id, $requisition->user_id);
        $this->assertSame('ref-from-remote', $requisition->reference);
        $this->assertSame(GoCardlessRequisitionStatus::LINKED, $requisition->status);
        $this->assertSame('LN', $requisition->gocardless_status);
        $this->assertSame(['acct-1', 'acct-2'], $requisition->accounts);
        $this->assertSame('agr-1', $requisition->agreement_id);
    }

    public function test_upsert_from_remote_generates_a_reference_when_remote_has_none(): void
    {
        $user = User::factory()->create();

        $requisition = $this->repository->upsertFromRemote($user->id, [
            'id' => 'req-remote-2',
            'status' => 'CR',
            'institution_id' => 'SANDBOXFINANCE_SFIN0000',
            'reference' => '',
        ]);

        $this->assertNotEmpty($requisition->reference);
        $this->assertSame(GoCardlessRequisitionStatus::PENDING, $requisition->status);
    }

    public function test_upsert_from_remote_updates_without_touching_local_only_fields(): void
    {
        $user = User::factory()->create();
        $existing = GoCardlessRequisition::factory()->linked()->for($user)->create([
            'requisition_id' => 'req-remote-3',
            'reference' => 'ref-local',
            'return_to' => 'accounts',
            'access_valid_until' => now()->addDays(30),
        ]);
        $validUntil = $existing->access_valid_until;

        $updated = $this->repository->upsertFromRemote($user->id, [
            'id' => 'req-remote-3',
            'status' => 'EX',
            'institution_id' => 'SANDBOXFINANCE_SFIN0000',
            'accounts' => ['acct-9'],
        ]);

        $this->assertSame($existing->id, $updated->id);
        $this->assertSame('ref-local', $updated->reference);
        $this->assertSame('accounts', $updated->return_to);
        $this->assertSame(GoCardlessRequisitionStatus::EXPIRED, $updated->status);
        $this->assertEquals($validUntil, $updated->access_valid_until);
    }

    public function test_upsert_from_remote_does_not_resurrect_locally_terminal_rows(): void
    {
        $user = User::factory()->create();
        $revoked = GoCardlessRequisition::factory()->for($user)->create([
            'requisition_id' => 'req-remote-4',
            'status' => GoCardlessRequisitionStatus::REVOKED,
        ]);

        // GoCardless still reports superseded/revoked-locally requisitions as linked.
        $updated = $this->repository->upsertFromRemote($user->id, [
            'id' => 'req-remote-4',
            'status' => 'LN',
            'institution_id' => 'SANDBOXFINANCE_SFIN0000',
        ]);

        $this->assertSame($revoked->id, $updated->id);
        $this->assertSame(GoCardlessRequisitionStatus::REVOKED, $updated->status);
        $this->assertSame('LN', $updated->gocardless_status);
    }

    public function test_upsert_from_remote_scopes_to_the_owner(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        GoCardlessRequisition::factory()->for($userA)->create(['requisition_id' => 'req-shared-id']);
        $created = $this->repository->upsertFromRemote($userB->id, [
            'id' => 'req-shared-id',
            'status' => 'LN',
            'institution_id' => 'SANDBOXFINANCE_SFIN0000',
        ]);

        $this->assertSame($userB->id, $created->user_id);
        $this->assertSame(2, GoCardlessRequisition::where('requisition_id', 'req-shared-id')->count());
    }

    public function test_find_by_user_scopes_to_owner_only(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        GoCardlessRequisition::factory()->for($userA)->count(2)->create();
        GoCardlessRequisition::factory()->for($userB)->create();

        $found = $this->repository->findByUser($userA->id);

        $this->assertCount(2, $found);
        $this->assertTrue($found->every(fn (GoCardlessRequisition $r) => $r->user_id === $userA->id));
    }

    public function test_claim_callback_is_single_use(): void
    {
        $requisition = GoCardlessRequisition::factory()->create(['callback_completed_at' => null]);

        $first = $this->repository->claimCallback($requisition);
        $second = $this->repository->claimCallback($requisition);

        $this->assertTrue($first);
        $this->assertFalse($second);

        $fresh = $requisition->fresh();
        $this->assertNotNull($fresh);
        $this->assertNotNull($fresh->callback_completed_at);
    }

    public function test_claim_callback_is_atomic_via_fresh_model_instance(): void
    {
        // Simulate two concurrent requests loading separate model instances of
        // the same row before either writes, to prove the guard is a
        // conditional UPDATE (WHERE callback_completed_at IS NULL) rather than
        // an in-memory/check-then-act race.
        $requisition = GoCardlessRequisition::factory()->create(['callback_completed_at' => null]);
        $instanceA = GoCardlessRequisition::query()->findOrFail($requisition->id);
        $instanceB = GoCardlessRequisition::query()->findOrFail($requisition->id);

        $resultA = $this->repository->claimCallback($instanceA);
        $resultB = $this->repository->claimCallback($instanceB);

        $this->assertTrue($resultA);
        $this->assertFalse($resultB);
    }

    public function test_get_expiring_before_boundary_is_inclusive(): void
    {
        $threshold = now()->addDays(7);

        $before = GoCardlessRequisition::factory()->linked()->create(['access_valid_until' => $threshold->clone()->subDay()]);
        $exact = GoCardlessRequisition::factory()->linked()->create(['access_valid_until' => $threshold->clone()]);
        $after = GoCardlessRequisition::factory()->linked()->create(['access_valid_until' => $threshold->clone()->addDay()]);
        // Not linked: must be excluded even though it expires before the threshold.
        GoCardlessRequisition::factory()->pending()->create(['access_valid_until' => $threshold->clone()->subDay()]);

        $expiring = $this->repository->getExpiringBefore($threshold);
        $ids = $expiring->pluck('id')->all();

        $this->assertContains($before->id, $ids);
        $this->assertContains($exact->id, $ids);
        $this->assertNotContains($after->id, $ids);
    }

    public function test_get_pollable_returns_pending_and_linked_never_or_stale_checked(): void
    {
        $staleBefore = now()->subHour();

        $neverChecked = GoCardlessRequisition::factory()->pending()->create(['last_checked_at' => null]);
        $staleChecked = GoCardlessRequisition::factory()->linked()->create(['last_checked_at' => $staleBefore->clone()->subMinute()]);
        $freshlyChecked = GoCardlessRequisition::factory()->linked()->create(['last_checked_at' => now()]);
        $expired = GoCardlessRequisition::factory()->expired()->create(['last_checked_at' => null]);

        $pollable = $this->repository->getPollable($staleBefore, 10);
        $ids = $pollable->pluck('id')->all();

        $this->assertContains($neverChecked->id, $ids);
        $this->assertContains($staleChecked->id, $ids);
        $this->assertNotContains($freshlyChecked->id, $ids);
        $this->assertNotContains($expired->id, $ids);
    }

    public function test_get_pollable_respects_limit(): void
    {
        GoCardlessRequisition::factory()->pending()->count(3)->create(['last_checked_at' => null]);

        $pollable = $this->repository->getPollable(now(), 2);

        $this->assertCount(2, $pollable);
    }

    public function test_update_status_persists_new_status_and_raw_code(): void
    {
        $requisition = GoCardlessRequisition::factory()->pending()->create();

        $this->repository->updateStatus($requisition, GoCardlessRequisitionStatus::LINKED, 'LN');

        $fresh = $requisition->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(GoCardlessRequisitionStatus::LINKED, $fresh->status);
        $this->assertSame('LN', $fresh->gocardless_status);
    }

    public function test_mark_replaced_for_institution_leaves_except_id_untouched(): void
    {
        $user = User::factory()->create();
        $keep = GoCardlessRequisition::factory()->for($user)->linked()->create(['institution_id' => 'INST_A']);
        $replace1 = GoCardlessRequisition::factory()->for($user)->linked()->create(['institution_id' => 'INST_A']);
        $replace2 = GoCardlessRequisition::factory()->for($user)->linked()->create(['institution_id' => 'INST_A']);
        $otherInstitution = GoCardlessRequisition::factory()->for($user)->linked()->create(['institution_id' => 'INST_B']);

        $affected = $this->repository->markReplacedForInstitution($user->id, 'INST_A', $keep->id);

        $this->assertSame(2, $affected);

        $keepFresh = $keep->fresh();
        $replace1Fresh = $replace1->fresh();
        $replace2Fresh = $replace2->fresh();
        $otherFresh = $otherInstitution->fresh();
        $this->assertNotNull($keepFresh);
        $this->assertNotNull($replace1Fresh);
        $this->assertNotNull($replace2Fresh);
        $this->assertNotNull($otherFresh);

        $this->assertSame(GoCardlessRequisitionStatus::LINKED, $keepFresh->status);
        $this->assertSame(GoCardlessRequisitionStatus::REPLACED, $replace1Fresh->status);
        $this->assertSame(GoCardlessRequisitionStatus::REPLACED, $replace2Fresh->status);
        $this->assertSame(GoCardlessRequisitionStatus::LINKED, $otherFresh->status);
    }

    public function test_user_owns_gocardless_account_true_false_and_other_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        GoCardlessRequisition::factory()->for($owner)->create(['accounts' => ['gc-acct-1', 'gc-acct-2']]);
        GoCardlessRequisition::factory()->for($other)->create(['accounts' => ['gc-acct-owned-by-other']]);

        $this->assertTrue($this->repository->userOwnsGoCardlessAccount($owner->id, 'gc-acct-1'));
        $this->assertFalse($this->repository->userOwnsGoCardlessAccount($owner->id, 'gc-acct-owned-by-other'));
        $this->assertFalse($this->repository->userOwnsGoCardlessAccount($owner->id, 'gc-acct-nonexistent'));
    }

    public function test_days_until_expiry_is_null_without_access_valid_until(): void
    {
        $requisition = GoCardlessRequisition::factory()->pending()->create(['access_valid_until' => null]);

        $this->assertNull($requisition->daysUntilExpiry());
    }

    public function test_days_until_expiry_is_negative_when_already_past(): void
    {
        $requisition = GoCardlessRequisition::factory()->create(['access_valid_until' => now()->subDays(5)]);

        $this->assertLessThan(0, $requisition->daysUntilExpiry());
    }

    public function test_days_until_expiry_is_positive_when_in_future(): void
    {
        $requisition = GoCardlessRequisition::factory()->linked()->create(['access_valid_until' => now()->addDays(10)]);

        $this->assertGreaterThan(0, $requisition->daysUntilExpiry());
    }

    public function test_needs_reconnect_true_for_reconnect_statuses(): void
    {
        $requisition = GoCardlessRequisition::factory()->expired()->create();

        $this->assertTrue($requisition->needsReconnect());
    }

    public function test_needs_reconnect_true_when_access_valid_until_in_past_regardless_of_status(): void
    {
        // Status still says linked but the access window has already lapsed.
        $requisition = GoCardlessRequisition::factory()->linked()->create(['access_valid_until' => now()->subDay()]);

        $this->assertTrue($requisition->needsReconnect());
    }

    public function test_needs_reconnect_false_when_linked_and_within_access_window(): void
    {
        $requisition = GoCardlessRequisition::factory()->linked()->create();

        $this->assertFalse($requisition->needsReconnect());
    }

    public function test_deleting_requisition_nulls_account_link_without_deleting_account(): void
    {
        $user = User::factory()->create();
        $requisition = GoCardlessRequisition::factory()->for($user)->linked()->create();
        $account = Account::factory()->for($user)->create(['gocardless_requisition_id' => $requisition->id]);

        $requisition->delete();

        $account->refresh();
        $this->assertNull($account->gocardless_requisition_id);
        $this->assertDatabaseHas('accounts', ['id' => $account->id]);
    }
}
