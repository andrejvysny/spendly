<?php

namespace Tests\Feature\Import;

use App\Models\ImportMapping;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ImportMappingsControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();
    }

    #[Test]
    public function it_requires_authentication_to_list_mappings()
    {
        $response = $this->getJson(route('imports.mappings.get'));

        $response->assertUnauthorized();
    }

    #[Test]
    public function it_lists_user_import_mappings_ordered_by_last_used()
    {
        // Create mappings for the authenticated user
        $oldMapping = ImportMapping::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Old Mapping',
            'last_used_at' => now()->subDays(2),
        ]);

        $recentMapping = ImportMapping::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Recent Mapping',
            'last_used_at' => now()->subDay(),
        ]);

        $newestMapping = ImportMapping::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Newest Mapping',
            'last_used_at' => now(),
        ]);

        // Create mapping for another user (should not be returned)
        ImportMapping::factory()->create([
            'user_id' => $this->otherUser->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson(route('imports.mappings.get'));

        $response->assertOk()
            ->assertJsonCount(3, 'mappings')
            ->assertJsonPath('mappings.0.id', $newestMapping->id)
            ->assertJsonPath('mappings.1.id', $recentMapping->id)
            ->assertJsonPath('mappings.2.id', $oldMapping->id);
    }

    #[Test]
    public function it_requires_authentication_to_store_mapping()
    {
        $response = $this->postJson(route('imports.mappings.save'), [
            'name' => 'Test Mapping',
        ]);

        $response->assertUnauthorized();
    }

    #[Test]
    public function it_stores_a_new_import_mapping()
    {
        $mappingData = [
            'name' => 'My Bank Mapping',
            'bank_name' => 'Test Bank',
            'column_mapping' => [
                'date' => 'Transaction Date',
                'amount' => 'Amount',
                'description' => 'Description',
            ],
            'date_format' => 'Y-m-d',
            'amount_format' => 'decimal',
            'amount_type_strategy' => 'single_column',
            'currency' => 'USD',
        ];

        $response = $this->actingAs($this->user)
            ->postJson(route('imports.mappings.save'), $mappingData);

        $response->assertOk()
            ->assertJsonPath('message', 'Import mapping saved successfully')
            ->assertJsonPath('mapping.name', 'My Bank Mapping')
            ->assertJsonPath('mapping.bank_name', 'Test Bank')
            ->assertJsonPath('mapping.currency', 'USD');

        $this->assertDatabaseHas('import_mappings', [
            'user_id' => $this->user->id,
            'name' => 'My Bank Mapping',
            'bank_name' => 'Test Bank',
            'date_format' => 'Y-m-d',
            'amount_format' => 'decimal',
            'amount_type_strategy' => 'single_column',
            'currency' => 'USD',
        ]);

        // Verify last_used_at is set
        $mapping = ImportMapping::where('user_id', $this->user->id)->first();
        $this->assertNotNull($mapping->last_used_at);
    }

    #[Test]
    public function it_validates_required_fields_when_storing_mapping()
    {
        $response = $this->actingAs($this->user)
            ->postJson(route('imports.mappings.save'), []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors([
                'name',
                'column_mapping',
                'date_format',
                'amount_format',
                'amount_type_strategy',
                'currency',
            ]);
    }

    #[Test]
    public function it_validates_field_formats_when_storing_mapping()
    {
        $invalidData = [
            'name' => str_repeat('a', 256), // Too long
            'bank_name' => str_repeat('b', 256), // Too long
            'column_mapping' => 'not an array',
            'date_format' => '',
            'amount_format' => '',
            'amount_type_strategy' => '',
            'currency' => 'TOOLONG', // Not 3 characters
        ];

        $response = $this->actingAs($this->user)
            ->postJson(route('imports.mappings.save'), $invalidData);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors([
                'name',
                'bank_name',
                'column_mapping',
                'date_format',
                'amount_format',
                'amount_type_strategy',
                'currency',
            ]);
    }

    #[Test]
    public function it_allows_null_bank_name_when_storing_mapping()
    {
        $mappingData = [
            'name' => 'Generic Mapping',
            'bank_name' => null,
            'column_mapping' => ['date' => 'Date'],
            'date_format' => 'Y-m-d',
            'amount_format' => 'decimal',
            'amount_type_strategy' => 'single_column',
            'currency' => 'EUR',
        ];

        $response = $this->actingAs($this->user)
            ->postJson(route('imports.mappings.save'), $mappingData);

        $response->assertOk();

        $this->assertDatabaseHas('import_mappings', [
            'user_id' => $this->user->id,
            'name' => 'Generic Mapping',
            'bank_name' => null,
        ]);
    }

    #[Test]
    public function it_requires_authentication_to_update_last_used()
    {
        $mapping = ImportMapping::factory()->create();

        $response = $this->putJson(route('imports.mappings.usage', $mapping));

        $response->assertUnauthorized();
    }

    #[Test]
    public function it_updates_last_used_timestamp_for_own_mapping()
    {
        $mapping = ImportMapping::factory()->create([
            'user_id' => $this->user->id,
            'last_used_at' => now()->subWeek(),
        ]);

        $oldTimestamp = $mapping->last_used_at;

        $response = $this->actingAs($this->user)
            ->putJson(route('imports.mappings.usage', $mapping));

        $response->assertOk()
            ->assertJsonPath('message', 'Mapping usage updated');

        $mapping->refresh();
        $this->assertGreaterThan($oldTimestamp, $mapping->last_used_at);
        $this->assertTrue($mapping->last_used_at->isToday());
    }

    #[Test]
    public function it_prevents_updating_last_used_for_other_users_mapping()
    {
        $mapping = ImportMapping::factory()->create([
            'user_id' => $this->otherUser->id,
        ]);

        $response = $this->actingAs($this->user)
            ->putJson(route('imports.mappings.usage', $mapping));

        $response->assertForbidden()
            ->assertJson(['message' => 'Unauthorized action']);
    }

    #[Test]
    public function it_requires_authentication_to_delete_mapping()
    {
        $mapping = ImportMapping::factory()->create();

        $response = $this->deleteJson(route('imports.mappings.delete', $mapping));

        $response->assertUnauthorized();
    }

    #[Test]
    public function it_deletes_own_mapping()
    {
        $mapping = ImportMapping::factory()->create([
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson(route('imports.mappings.delete', $mapping));

        $response->assertOk()
            ->assertJsonPath('message', 'Import mapping deleted successfully');

        $this->assertDatabaseMissing('import_mappings', [
            'id' => $mapping->id,
        ]);
    }

    #[Test]
    public function it_prevents_deleting_other_users_mapping()
    {
        $mapping = ImportMapping::factory()->create([
            'user_id' => $this->otherUser->id,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson(route('imports.mappings.delete', $mapping));

        $response->assertForbidden()
            ->assertJson(['message' => 'Unauthorized action']);

        // Ensure mapping still exists
        $this->assertDatabaseHas('import_mappings', [
            'id' => $mapping->id,
        ]);
    }

    #[Test]
    public function it_handles_nonexistent_mapping_gracefully()
    {
        $nonExistentId = 99999;

        $response = $this->actingAs($this->user)
            ->putJson(route('imports.mappings.usage', ['mapping' => $nonExistentId]));

        $response->assertNotFound();

        $response = $this->actingAs($this->user)
            ->deleteJson(route('imports.mappings.delete', ['mapping' => $nonExistentId]));

        $response->assertNotFound();
    }

    #[Test]
    public function it_stores_complex_column_mapping_correctly()
    {
        $complexMapping = [
            'date' => ['column' => 'Date', 'format' => 'd/m/Y'],
            'amount' => ['debit' => 'Debit Amount', 'credit' => 'Credit Amount'],
            'description' => ['primary' => 'Description', 'secondary' => 'Reference'],
            'category' => 'Category',
        ];

        $mappingData = [
            'name' => 'Complex Bank Mapping',
            'bank_name' => 'Complex Bank',
            'column_mapping' => $complexMapping,
            'date_format' => 'd/m/Y',
            'amount_format' => 'decimal',
            'amount_type_strategy' => 'separate_columns',
            'currency' => 'GBP',
        ];

        $response = $this->actingAs($this->user)
            ->postJson(route('imports.mappings.save'), $mappingData);

        $response->assertOk();

        $mapping = ImportMapping::where('name', 'Complex Bank Mapping')->first();
        $this->assertEquals($complexMapping, $mapping->column_mapping);
    }
}
