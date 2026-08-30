<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Enums\GoCardlessRequisitionStatus;
use App\Models\Account;
use App\Models\GoCardlessRequisition;
use App\Models\User;
use App\Providers\GoCardlessServiceProvider;
use App\Services\GoCardless\ClientFactory\GoCardlessClientFactoryInterface;
use App\Services\GoCardless\GoCardlessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The bank redirect is unauthenticated and cross-site: nothing in the session can be
 * trusted, so the signed URL + the stored requisition row are the whole trust chain.
 */
class GoCardlessCallbackTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gocardless.use_mock' => true]);

        $this->app->forgetInstance(GoCardlessClientFactoryInterface::class);
        $this->app->forgetInstance(GoCardlessService::class);
        (new GoCardlessServiceProvider($this->app))->register();

        $this->user = User::factory()->create();
    }

    /**
     * @param  array<string, string|int>  $appended  Params the bank tacks on *after* signing.
     */
    private function signedCallback(string $reference, array $appended = []): string
    {
        $url = URL::temporarySignedRoute(
            'bank_data.gocardless.callback',
            now()->addHours(2),
            ['reference' => $reference]
        );

        return $appended === [] ? $url : $url.'&'.http_build_query($appended);
    }

    private function pendingRequisition(?User $owner = null, string $requisitionId = 'req_test_1'): GoCardlessRequisition
    {
        return GoCardlessRequisition::factory()
            ->pending()
            ->for($owner ?? $this->user)
            ->create([
                'requisition_id' => $requisitionId,
                'institution_id' => 'MOCK_INSTITUTION',
                'agreement_id' => null,
            ]);
    }

    private function reference(GoCardlessRequisition $requisition): string
    {
        $reference = $requisition->getAttribute('reference');
        $this->assertIsString($reference);

        return $reference;
    }

    // ── happy path ────────────────────────────────────────────────────────

    public function test_valid_callback_survives_query_params_appended_after_signing(): void
    {
        $requisition = $this->pendingRequisition();
        $reference = $this->reference($requisition);

        // GoCardless (and the mock client) append these to the signed redirect. They are
        // not covered by the signature, so verification must ignore them — otherwise
        // every real callback 302s to the generic error.
        $url = $this->signedCallback($reference, [
            'ref' => $reference,
            'mock' => 1,
            'requisition_id' => 'req_test_1',
        ]);

        $response = $this->get($url);

        $response->assertRedirect(route('bank_data.edit'));
        $response->assertSessionHas('success');

        $requisition->refresh();
        $this->assertSame(GoCardlessRequisitionStatus::LINKED, $requisition->status);
        $this->assertSame('LN', $requisition->getAttribute('gocardless_status'));
        $this->assertNotNull($requisition->callback_completed_at);
        $this->assertContains('mock_account_1', $requisition->accounts ?? []);
        $this->assertNotNull($requisition->access_valid_until);
    }

    public function test_return_to_accounts_redirects_to_accounts_and_opens_modal(): void
    {
        $requisition = $this->pendingRequisition();
        $requisition->update(['return_to' => 'accounts']);

        $response = $this->get($this->signedCallback($this->reference($requisition)));

        $response->assertRedirect(route('accounts.index'));
        $response->assertSessionHas('success');
        $response->assertSessionHas('open_go_cardless_modal', true);
    }

    // ── signature enforcement ─────────────────────────────────────────────

    public function test_tampered_reference_is_rejected_without_calling_gocardless(): void
    {
        $requisition = $this->pendingRequisition();
        $other = $this->pendingRequisition(null, 'req_test_2');

        $this->mock(GoCardlessService::class, function ($mock): void {
            $mock->shouldNotReceive('getAccounts');
        });

        // Signature was issued for $requisition; swapping the reference must invalidate it.
        $url = str_replace(
            'reference='.$this->reference($requisition),
            'reference='.$this->reference($other),
            $this->signedCallback($this->reference($requisition))
        );

        $response = $this->get($url);

        $response->assertRedirect(route('bank_data.edit'));
        $response->assertSessionHas('error');

        $other->refresh();
        $this->assertSame(GoCardlessRequisitionStatus::PENDING, $other->status);
        $this->assertNull($other->callback_completed_at);
    }

    public function test_stripped_signature_is_rejected_without_calling_gocardless(): void
    {
        $requisition = $this->pendingRequisition();

        $this->mock(GoCardlessService::class, function ($mock): void {
            $mock->shouldNotReceive('getAccounts');
        });

        $response = $this->get(route('bank_data.gocardless.callback', ['reference' => $this->reference($requisition)]));

        $response->assertRedirect(route('bank_data.edit'));
        $response->assertSessionHas('error');

        $requisition->refresh();
        $this->assertSame(GoCardlessRequisitionStatus::PENDING, $requisition->status);
    }

    public function test_expired_signature_is_rejected(): void
    {
        $requisition = $this->pendingRequisition();
        $url = $this->signedCallback($this->reference($requisition));

        $this->travel(3)->hours();

        $response = $this->get($url);

        $response->assertRedirect(route('bank_data.edit'));
        $response->assertSessionHas('error');

        $requisition->refresh();
        $this->assertSame(GoCardlessRequisitionStatus::PENDING, $requisition->status);
        $this->assertNull($requisition->callback_completed_at);
    }

    public function test_unknown_reference_is_rejected(): void
    {
        $response = $this->get($this->signedCallback((string) \Illuminate\Support\Str::uuid()));

        $response->assertRedirect(route('bank_data.edit'));
        $response->assertSessionHas('error');
    }

    // ── single use ────────────────────────────────────────────────────────

    public function test_replayed_callback_does_not_hit_gocardless_twice(): void
    {
        $requisition = $this->pendingRequisition();
        $url = $this->signedCallback($this->reference($requisition), ['ref' => $this->reference($requisition)]);

        $this->mock(GoCardlessService::class, function ($mock): void {
            $mock->shouldReceive('getAccounts')->once()->andReturn(['mock_account_1']);
        });

        $this->get($url)->assertRedirect(route('bank_data.edit'));

        $replay = $this->get($url);
        $replay->assertRedirect(route('bank_data.edit'));
        $replay->assertSessionHas('success', 'This bank connection was already processed.');
    }

    // ── GoCardless-reported errors ────────────────────────────────────────

    public function test_user_cancelled_session_marks_requisition_cancelled(): void
    {
        $requisition = $this->pendingRequisition();

        $this->mock(GoCardlessService::class, function ($mock): void {
            $mock->shouldNotReceive('getAccounts');
        });

        $response = $this->get($this->signedCallback($this->reference($requisition), [
            'ref' => $this->reference($requisition),
            'error' => 'UserCancelledSession',
            'details' => 'The user cancelled',
        ]));

        $response->assertRedirect(route('bank_data.edit'));
        $response->assertSessionHas('error');

        $requisition->refresh();
        $this->assertSame(GoCardlessRequisitionStatus::CANCELLED, $requisition->status);
        $this->assertNotNull($requisition->callback_completed_at);
    }

    public function test_consent_link_reused_marks_requisition_errored(): void
    {
        $requisition = $this->pendingRequisition();

        $this->mock(GoCardlessService::class, function ($mock): void {
            $mock->shouldNotReceive('getAccounts');
        });

        $response = $this->get($this->signedCallback($this->reference($requisition), [
            'ref' => $this->reference($requisition),
            'error' => 'ConsentLinkReused',
            'details' => 'Link already used',
        ]));

        $response->assertRedirect(route('bank_data.edit'));
        $response->assertSessionHas('error');

        $requisition->refresh();
        $this->assertSame(GoCardlessRequisitionStatus::ERROR, $requisition->status);
        $this->assertNotNull($requisition->getAttribute('last_error'));
    }

    // ── ownership comes from the row, never the session ───────────────────

    public function test_callback_is_processed_against_the_row_owner_not_the_logged_in_user(): void
    {
        $owner = $this->user;
        $intruder = User::factory()->create();

        $requisition = $this->pendingRequisition($owner);

        $ownerAccount = Account::factory()->create([
            'user_id' => $owner->id,
            'gocardless_account_id' => 'mock_account_1',
            'gocardless_needs_reconnect' => true,
        ]);
        $intruderAccount = Account::factory()->create([
            'user_id' => $intruder->id,
            'gocardless_account_id' => 'mock_account_1',
            'gocardless_needs_reconnect' => true,
        ]);

        $this->actingAs($intruder)
            ->get($this->signedCallback($this->reference($requisition), ['ref' => $this->reference($requisition)]))
            ->assertRedirect(route('bank_data.edit'));

        $requisition->refresh();
        $ownerAccount->refresh();
        $intruderAccount->refresh();

        $this->assertSame($owner->id, $requisition->getAttribute('user_id'));
        $this->assertSame($requisition->id, $ownerAccount->gocardless_requisition_id);
        $this->assertFalse((bool) $ownerAccount->gocardless_needs_reconnect);

        $this->assertNull($intruderAccount->gocardless_requisition_id);
        $this->assertTrue((bool) $intruderAccount->gocardless_needs_reconnect);
    }

    // ── reconnect repairs the existing account instead of duplicating ─────

    public function test_existing_account_is_relinked_not_duplicated(): void
    {
        $requisition = $this->pendingRequisition();

        $account = Account::factory()->create([
            'user_id' => $this->user->id,
            'gocardless_account_id' => 'mock_account_1',
            'gocardless_needs_reconnect' => true,
            'gocardless_requisition_id' => null,
        ]);

        $this->get($this->signedCallback($this->reference($requisition), ['ref' => $this->reference($requisition)]))
            ->assertRedirect(route('bank_data.edit'));

        $requisition->refresh();
        $account->refresh();

        $this->assertSame($requisition->id, $account->gocardless_requisition_id);
        $this->assertFalse((bool) $account->gocardless_needs_reconnect);
        $this->assertSame(1, Account::where('user_id', $this->user->id)
            ->where('gocardless_account_id', 'mock_account_1')
            ->count());
    }

    public function test_newer_requisition_replaces_the_previous_one_for_the_same_institution(): void
    {
        $old = GoCardlessRequisition::factory()
            ->linked()
            ->for($this->user)
            ->create(['institution_id' => 'MOCK_INSTITUTION', 'requisition_id' => 'req_old']);

        $fresh = $this->pendingRequisition(null, 'req_fresh');

        $this->get($this->signedCallback($this->reference($fresh), ['ref' => $this->reference($fresh)]))
            ->assertRedirect(route('bank_data.edit'));

        $old->refresh();
        $fresh->refresh();

        $this->assertSame(GoCardlessRequisitionStatus::REPLACED, $old->status);
        $this->assertSame(GoCardlessRequisitionStatus::LINKED, $fresh->status);
    }
}
