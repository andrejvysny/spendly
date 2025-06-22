<?php

namespace Tests\Feature\Import;

use App\Models\Import;
use App\Models\ImportFailure;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportFailureTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Import $import;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->import = Import::factory()->create(['user_id' => $this->user->id]);
    }

    public function test_user_can_view_import_failures()
    {
        // Create some failures for the import
        ImportFailure::factory()->count(5)->create(['import_id' => $this->import->id]);
        ImportFailure::factory()->duplicate()->count(2)->create(['import_id' => $this->import->id]);
        ImportFailure::factory()->validationFailed()->count(3)->create(['import_id' => $this->import->id]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/imports/{$this->import->id}/failures");

        $response->assertOk()
            ->assertJsonStructure([
                'failures' => [
                    'data' => [
                        '*' => [
                            'id',
                            'import_id',
                            'row_number',
                            'error_type',
                            'error_message',
                            'status',
                            'created_at',
                        ],
                    ],
                    'meta',
                ],
                'stats' => [
                    'total',
                    'pending',
                    'reviewed',
                    'by_type',
                ],
                'import',
            ]);

        $this->assertEquals(10, $response->json('stats.total'));
        $this->assertEquals(10, $response->json('stats.pending'));
    }

    public function test_user_cannot_view_other_users_import_failures()
    {
        $otherUser = User::factory()->create();
        $otherImport = Import::factory()->create(['user_id' => $otherUser->id]);
        ImportFailure::factory()->count(3)->create(['import_id' => $otherImport->id]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/imports/{$otherImport->id}/failures");

        $response->assertForbidden();
    }

    public function test_user_can_filter_failures_by_error_type()
    {
        ImportFailure::factory()->duplicate()->count(3)->create(['import_id' => $this->import->id]);
        ImportFailure::factory()->validationFailed()->count(2)->create(['import_id' => $this->import->id]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/imports/{$this->import->id}/failures?error_type=duplicate");

        $response->assertOk();
        $this->assertEquals(3, count($response->json('failures.data')));

        foreach ($response->json('failures.data') as $failure) {
            $this->assertEquals('duplicate', $failure['error_type']);
        }
    }

    public function test_user_can_mark_failure_as_reviewed()
    {
        $failure = ImportFailure::factory()->create(['import_id' => $this->import->id]);

        $response = $this->actingAs($this->user)
            ->patchJson("/api/imports/{$this->import->id}/failures/{$failure->id}/reviewed", [
                'notes' => 'Reviewed and noted',
            ]);

        $response->assertOk()
            ->assertJson([
                'message' => 'Failure marked as reviewed',
            ]);

        $failure->refresh();
        $this->assertEquals(ImportFailure::STATUS_REVIEWED, $failure->status);
        $this->assertEquals('Reviewed and noted', $failure->review_notes);
        $this->assertEquals($this->user->id, $failure->reviewed_by);
        $this->assertNotNull($failure->reviewed_at);
    }

    public function test_user_can_mark_failure_as_resolved()
    {
        $failure = ImportFailure::factory()->create(['import_id' => $this->import->id]);

        $response = $this->actingAs($this->user)
            ->patchJson("/api/imports/{$this->import->id}/failures/{$failure->id}/resolved", [
                'notes' => 'Fixed the issue',
            ]);

        $response->assertOk()
            ->assertJson([
                'message' => 'Failure marked as resolved',
            ]);

        $failure->refresh();
        $this->assertEquals(ImportFailure::STATUS_RESOLVED, $failure->status);
        $this->assertEquals('Fixed the issue', $failure->review_notes);
    }

    public function test_user_can_mark_failure_as_ignored()
    {
        $failure = ImportFailure::factory()->create(['import_id' => $this->import->id]);

        $response = $this->actingAs($this->user)
            ->patchJson("/api/imports/{$this->import->id}/failures/{$failure->id}/ignored", [
                'notes' => 'Not important',
            ]);

        $response->assertOk()
            ->assertJson([
                'message' => 'Failure marked as ignored',
            ]);

        $failure->refresh();
        $this->assertEquals(ImportFailure::STATUS_IGNORED, $failure->status);
        $this->assertEquals('Not important', $failure->review_notes);
    }

    public function test_user_can_unmark_failure_to_pending()
    {
        // Create a reviewed failure
        $failure = ImportFailure::factory()->reviewed()->create(['import_id' => $this->import->id]);
        
        $this->assertEquals(ImportFailure::STATUS_REVIEWED, $failure->status);
        $this->assertNotNull($failure->reviewed_at);
        $this->assertNotNull($failure->reviewed_by);

        $response = $this->actingAs($this->user)
            ->patchJson("/api/imports/{$this->import->id}/failures/{$failure->id}/pending", [
                'notes' => 'Reverted for re-review',
            ]);

        $response->assertOk()
            ->assertJson([
                'message' => 'Failure unmarked and reverted to pending',
            ]);

        $failure->refresh();
        $this->assertEquals(ImportFailure::STATUS_PENDING, $failure->status);
        $this->assertEquals('Reverted for re-review', $failure->review_notes);
        $this->assertNull($failure->reviewed_at);
        $this->assertNull($failure->reviewed_by);
    }

    public function test_user_can_bulk_unmark_failures()
    {
        $reviewedFailures = ImportFailure::factory()->reviewed()->count(2)->create(['import_id' => $this->import->id]);
        $ignoredFailures = ImportFailure::factory()->ignored()->count(1)->create(['import_id' => $this->import->id]);
        
        $allFailures = $reviewedFailures->merge($ignoredFailures);
        $failureIds = $allFailures->pluck('id')->toArray();

        // Verify they are not pending
        foreach ($allFailures as $failure) {
            $this->assertNotEquals(ImportFailure::STATUS_PENDING, $failure->status);
        }

        $response = $this->actingAs($this->user)
            ->patchJson("/api/imports/{$this->import->id}/failures/bulk", [
                'failure_ids' => $failureIds,
                'action' => 'pending',
                'notes' => 'Bulk unmarked for re-review',
            ]);

        $response->assertOk()
            ->assertJson([
                'updated' => 3,
                'total' => 3,
            ]);

        // Verify all failures are now pending
        foreach ($allFailures as $failure) {
            $failure->refresh();
            $this->assertEquals(ImportFailure::STATUS_PENDING, $failure->status);
            $this->assertEquals('Bulk unmarked for re-review', $failure->review_notes);
            $this->assertNull($failure->reviewed_at);
            $this->assertNull($failure->reviewed_by);
        }
    }

    public function test_user_can_bulk_update_failures()
    {
        $failures = ImportFailure::factory()->count(3)->create(['import_id' => $this->import->id]);
        $failureIds = $failures->pluck('id')->toArray();

        $response = $this->actingAs($this->user)
            ->patchJson("/api/imports/{$this->import->id}/failures/bulk", [
                'failure_ids' => $failureIds,
                'action' => 'reviewed',
                'notes' => 'Bulk reviewed',
            ]);

        $response->assertOk()
            ->assertJson([
                'updated' => 3,
                'total' => 3,
            ]);

        foreach ($failures as $failure) {
            $failure->refresh();
            $this->assertEquals(ImportFailure::STATUS_REVIEWED, $failure->status);
            $this->assertEquals('Bulk reviewed', $failure->review_notes);
        }
    }

    public function test_user_can_get_failure_statistics()
    {
        ImportFailure::factory()->count(2)->create(['import_id' => $this->import->id]);
        ImportFailure::factory()->reviewed()->count(1)->create(['import_id' => $this->import->id]);
        ImportFailure::factory()->duplicate()->count(3)->create(['import_id' => $this->import->id]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/imports/{$this->import->id}/failures/stats");

        $response->assertOk()
            ->assertJsonStructure([
                'stats' => [
                    'total_failures',
                    'pending_review',
                    'reviewed',
                    'by_type',
                ],
                'import',
            ]);

        $stats = $response->json('stats');
        $this->assertEquals(6, $stats['total_failures']);
        $this->assertEquals(5, $stats['pending_review']);
        $this->assertEquals(1, $stats['reviewed']);
    }

    public function test_user_can_export_failures_as_csv()
    {
        ImportFailure::factory()->count(5)->create(['import_id' => $this->import->id]);

        $response = $this->actingAs($this->user)
            ->get("/api/imports/{$this->import->id}/failures/export");

        $response->assertOk();
        $this->assertEquals('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContains('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContains("import-{$this->import->id}-failures", $response->headers->get('Content-Disposition'));
    }

    public function test_user_cannot_update_failure_from_different_import()
    {
        $otherImport = Import::factory()->create(['user_id' => $this->user->id]);
        $failure = ImportFailure::factory()->create(['import_id' => $otherImport->id]);

        $response = $this->actingAs($this->user)
            ->patchJson("/api/imports/{$this->import->id}/failures/{$failure->id}/reviewed", [
                'notes' => 'Should not work',
            ]);

        $response->assertNotFound();
    }

    public function test_unauthenticated_user_cannot_access_failures()
    {
        $response = $this->getJson("/api/imports/{$this->import->id}/failures");
        $response->assertUnauthorized();
    }

    public function test_failure_validation_requires_valid_notes()
    {
        $failure = ImportFailure::factory()->create(['import_id' => $this->import->id]);

        // Test with notes too long
        $response = $this->actingAs($this->user)
            ->patchJson("/api/imports/{$this->import->id}/failures/{$failure->id}/reviewed", [
                'notes' => str_repeat('a', 1001), // Too long
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['notes']);
    }

    public function test_bulk_update_validation()
    {
        $failure = ImportFailure::factory()->create(['import_id' => $this->import->id]);

        // Test with invalid action
        $response = $this->actingAs($this->user)
            ->patchJson("/api/imports/{$this->import->id}/failures/bulk", [
                'failure_ids' => [$failure->id],
                'action' => 'invalid_action',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['action']);

        // Test with missing failure_ids
        $response = $this->actingAs($this->user)
            ->patchJson("/api/imports/{$this->import->id}/failures/bulk", [
                'action' => 'reviewed',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['failure_ids']);
    }
}
