<?php

namespace Tests\Feature\Imports;

use App\Models\Account;
use App\Models\Import;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportsScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_imports_index(): void
    {
        $this->get('/imports')->assertRedirect('/login');
    }

    public function test_user_can_view_their_imports(): void
    {
        $user = User::factory()->create();
        $imports = Import::factory()->count(3)->for($user)->create();
        Import::factory()->count(2)->create();

        $response = $this->actingAs($user)
            ->inertia('GET', '/imports');

        $response->assertOk();
        $response->assertJsonFragment(['component' => 'import/index']);
        $payload = $response->json('props.imports');
        $this->assertCount(3, $payload);
        $this->assertEqualsCanonicalizing(
            $imports->pluck('id')->toArray(),
            array_column($payload, 'id')
        );
    }

    public function test_user_can_revert_import(): void
    {
        $user = User::factory()->create();
        $account = Account::create([
            'user_id' => $user->id,
            'name' => 'Main',
            'bank_name' => 'Demo',
            'iban' => 'DE89370400440532013000',
            'type' => 'checking',
            'currency' => 'EUR',
            'balance' => 0,
        ]);

        $import = Import::factory()->for($user)->create([
            'status' => Import::STATUS_COMPLETED,
        ]);

        $tx1 = Transaction::factory()->create([
            'account_id' => $account->id,
            'metadata' => ['import_id' => $import->id],
        ]);
        $tx2 = Transaction::factory()->create([
            'account_id' => $account->id,
            'metadata' => ['import_id' => $import->id],
        ]);

        $response = $this->actingAs($user)->post('/imports/revert/'.$import->id);

        $response->assertOk();
        $import->refresh();
        $this->assertSame(Import::STATUS_REVERTED, $import->status);
        $this->assertModelMissing($tx1);
        $this->assertModelMissing($tx2);
    }

    public function test_user_cannot_revert_someone_elses_import(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $import = Import::factory()->for($other)->create([
            'status' => Import::STATUS_COMPLETED,
        ]);

        $this->actingAs($user)
            ->post('/imports/revert/'.$import->id)
            ->assertForbidden();
    }
}
