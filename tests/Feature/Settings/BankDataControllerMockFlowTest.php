<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankDataControllerMockFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        config(['services.gocardless.use_mock' => true]);
    }

    public function test_full_mock_flow_create_requisition_callback_list_import_sync(): void
    {
        $callbackUrl = url('/api/bank-data/gocardless/requisition/callback');

        // 1. Create requisition
        $createResponse = $this->actingAs($this->user)
            ->postJson('/api/bank-data/gocardless/requisitions', [
                'institution_id' => 'MOCK_INSTITUTION',
            ]);

        $createResponse->assertOk();
        $createResponse->assertJsonStructure(['link']);
        $link = $createResponse->json('link');
        $this->assertStringContainsString($callbackUrl, $link);
        $this->assertStringContainsString('mock=1', $link);

        // 2. Simulate user following the link (callback) – same session so requisition_id is in session. No auto-import.
        $callbackResponse = $this->get($link);
        $callbackResponse->assertRedirect(route('bank_data.edit'));
        $callbackResponse->assertSessionHas('success');

        // 3. List requisitions to get linked account ids (accounts are not auto-imported)
        $listResponse = $this->actingAs($this->user)
            ->getJson('/api/bank-data/gocardless/requisitions');
        $listResponse->assertOk();
        $listResponse->assertJsonPath('count', 1);
        $results = $listResponse->json('results');
        $this->assertIsArray($results);
        $this->assertCount(1, $results);
        $this->assertArrayHasKey('accounts', $results[0]);
        $accountList = $results[0]['accounts'];
        $this->assertNotEmpty($accountList);
        $firstAccountId = is_array($accountList[0]) ? ($accountList[0]['id'] ?? null) : $accountList[0];
        $this->assertNotNull($firstAccountId);

        // 4. Import first account via "Import" button (no auto-import in callback)
        $importResponse = $this->actingAs($this->user)
            ->postJson('/api/bank-data/gocardless/import/account', ['account_id' => $firstAccountId]);
        $importResponse->assertOk();
        $account = Account::where('user_id', $this->user->id)
            ->where('gocardless_account_id', $firstAccountId)
            ->first();
        $this->assertNotNull($account, 'Account should exist after manual import');

        // 5. Sync transactions for the imported account
        $syncResponse = $this->actingAs($this->user)
            ->postJson("/api/bank-data/gocardless/accounts/{$account->id}/sync-transactions", [
                'update_existing' => true,
                'force_max_date_range' => false,
            ]);
        $syncResponse->assertOk();
        $syncResponse->assertJsonPath('success', true);
    }

    public function test_revolut_fixture_flow_import_and_sync_uses_fixture_data(): void
    {
        $basePath = config('services.gocardless.mock_data_path', base_path('gocardless_bank_account_data'));
        $revolutDir = $basePath . '/Revolut';
        if (! is_dir($revolutDir)) {
            $this->markTestSkipped('Revolut fixture data not present at ' . $revolutDir);
        }

        config(['services.gocardless.use_mock' => true]);

        $callbackUrl = url('/api/bank-data/gocardless/requisition/callback');

        $createResponse = $this->actingAs($this->user)
            ->postJson('/api/bank-data/gocardless/requisitions', [
                'institution_id' => 'Revolut',
            ]);

        $createResponse->assertOk();
        $link = $createResponse->json('link');

        $callbackResponse = $this->get($link);
        $callbackResponse->assertRedirect(route('bank_data.edit'));
        $callbackResponse->assertSessionHas('success');

        // Accounts are not auto-imported; get first linked account id and import it
        $listResponse = $this->actingAs($this->user)->getJson('/api/bank-data/gocardless/requisitions');
        $listResponse->assertOk();
        $results = $listResponse->json('results');
        $this->assertNotEmpty($results);
        $accountList = $results[0]['accounts'] ?? [];
        $this->assertNotEmpty($accountList);
        $firstAccountId = is_array($accountList[0]) ? ($accountList[0]['id'] ?? null) : $accountList[0];
        $this->assertNotNull($firstAccountId);

        $importResponse = $this->actingAs($this->user)
            ->postJson('/api/bank-data/gocardless/import/account', ['account_id' => $firstAccountId]);
        $importResponse->assertOk();

        $account = Account::where('user_id', $this->user->id)->where('gocardless_account_id', $firstAccountId)->first();
        $this->assertNotNull($account, 'Revolut account should exist after manual import');
        $this->assertSame('Revolut', $account->gocardless_institution_id);

        $syncResponse = $this->actingAs($this->user)
            ->postJson("/api/bank-data/gocardless/accounts/{$account->id}/sync-transactions", [
                'update_existing' => true,
                'force_max_date_range' => false,
            ]);

        $syncResponse->assertOk();
        $syncResponse->assertJsonPath('success', true);
        $stats = $syncResponse->json('stats');
        $this->assertIsArray($stats);
        $this->assertGreaterThan(0, $stats['total'] ?? 0, 'Fixture transactions should be synced');
    }
}
