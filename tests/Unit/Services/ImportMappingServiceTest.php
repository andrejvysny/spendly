<?php

namespace Tests\Unit\Services;

use App\Services\ImportMappingService;
use Tests\Unit\UnitTestCase;

class ImportMappingServiceTest extends UnitTestCase
{
    private ImportMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ImportMappingService;
    }

    public function test_convert_index_mapping_to_headers()
    {
        $columnMapping = [
            'booked_date' => 0,
            'amount' => 1,
            'partner' => 2,
            'description' => null,
        ];
        $headers = ['Transaction Date', 'Amount', 'Partner Name', 'Notes'];

        $result = $this->service->convertIndexMappingToHeaders($columnMapping, $headers);

        $this->assertEquals([
            'booked_date' => 'Transaction Date',
            'amount' => 'Amount',
            'partner' => 'Partner Name',
            'description' => null,
        ], $result);
    }

    public function test_convert_header_mapping_to_indices()
    {
        $headerMapping = [
            'booked_date' => 'Transaction Date',
            'amount' => 'Amount',
            'partner' => 'Partner Name',
            'description' => null,
        ];
        $currentHeaders = ['Transaction Date', 'Amount', 'Partner Name', 'Notes'];

        $result = $this->service->convertHeaderMappingToIndices($headerMapping, $currentHeaders);

        $this->assertEquals([
            'booked_date' => 0,
            'amount' => 1,
            'partner' => 2,
            'description' => null,
        ], $result);
    }

    public function test_fuzzy_matching_for_header_conversion()
    {
        $headerMapping = [
            'booked_date' => 'Date',
            'amount' => 'Transaction Amount',
            'partner' => 'Merchant',
        ];
        $currentHeaders = ['Transaction Date', 'Amount USD', 'Merchant Name'];

        $result = $this->service->convertHeaderMappingToIndices($headerMapping, $currentHeaders);

        // Should find fuzzy matches
        $this->assertEquals(0, $result['booked_date']); // 'Date' matches 'Transaction Date'
        $this->assertEquals(1, $result['amount']); // 'Transaction Amount' matches 'Amount USD'
        $this->assertEquals(2, $result['partner']); // 'Merchant' matches 'Merchant Name'
    }

    public function test_apply_saved_mapping_with_index_based_legacy()
    {
        $savedMapping = [
            'booked_date' => 0,
            'amount' => 1,
            'partner' => 2,
        ];
        $currentHeaders = ['Date', 'Amount', 'Partner', 'Description'];

        $result = $this->service->applySavedMapping($savedMapping, $currentHeaders);

        $this->assertEquals([
            'booked_date' => 0,
            'amount' => 1,
            'partner' => 2,
        ], $result);
    }

    public function test_apply_saved_mapping_with_header_based()
    {
        $savedMapping = [
            'booked_date' => 'Transaction Date',
            'amount' => 'Amount',
            'partner' => 'Partner Name',
        ];
        $currentHeaders = ['Transaction Date', 'Amount', 'Partner Name', 'Description'];

        $result = $this->service->applySavedMapping($savedMapping, $currentHeaders);

        $this->assertEquals([
            'booked_date' => 0,
            'amount' => 1,
            'partner' => 2,
        ], $result);
    }

    public function test_apply_saved_mapping_handles_mismatched_headers()
    {
        // Saved mapping for original CSV structure
        $savedMapping = [
            'booked_date' => 'Date',
            'amount' => 'Amount',
            'partner' => 'Partner',
        ];
        // New CSV has different structure
        $currentHeaders = ['Partner', 'Date', 'Amount', 'Description'];

        $result = $this->service->applySavedMapping($savedMapping, $currentHeaders);

        // Should correctly map to new positions
        $this->assertEquals(1, $result['booked_date']); // 'Date' is now at index 1
        $this->assertEquals(2, $result['amount']); // 'Amount' is now at index 2
        $this->assertEquals(0, $result['partner']); // 'Partner' is now at index 0
    }

    public function test_auto_detect_mapping()
    {
        $headers = ['Transaction Date', 'Amount', 'Partner Name', 'Description', 'Transaction ID'];

        $result = $this->service->autoDetectMapping($headers);

        $this->assertEquals(0, $result['booked_date']);
        $this->assertEquals(1, $result['amount']);
        $this->assertEquals(2, $result['partner']);
        $this->assertEquals(3, $result['description']);
        $this->assertEquals(4, $result['transaction_id']);
    }

    public function test_auto_detect_mapping_with_multilingual_headers()
    {
        $headers = ['Datum', 'Suma', 'Partner', 'Popis'];

        $result = $this->service->autoDetectMapping($headers);

        $this->assertEquals(0, $result['booked_date']); // 'Datum' should match date
        $this->assertEquals(1, $result['amount']); // 'Suma' should match amount
        $this->assertEquals(2, $result['partner']); // 'Partner' should match partner
        $this->assertEquals(3, $result['description']); // 'Popis' should match description
    }

    public function test_validate_mapping_success()
    {
        $columnMapping = [
            'booked_date' => 0,
            'amount' => 1,
            'partner' => 2,
        ];
        $headers = ['Date', 'Amount', 'Partner', 'Description'];

        $result = $this->service->validateMapping($columnMapping, $headers);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function test_validate_mapping_missing_required_fields()
    {
        $columnMapping = [
            'booked_date' => 0,
            'amount' => null, // Missing required field
            'partner' => 2,
        ];
        $headers = ['Date', 'Amount', 'Partner', 'Description'];

        $result = $this->service->validateMapping($columnMapping, $headers);

        $this->assertFalse($result['valid']);
        $this->assertContains('Missing required field mapping: amount', $result['errors']);
    }

    public function test_validate_mapping_invalid_indices()
    {
        $columnMapping = [
            'booked_date' => 0,
            'amount' => 1,
            'partner' => 10, // Invalid index
        ];
        $headers = ['Date', 'Amount', 'Description'];

        $result = $this->service->validateMapping($columnMapping, $headers);

        $this->assertFalse($result['valid']);
        $this->assertContains('Invalid column index for field partner: 10', $result['errors']);
    }

    public function test_find_best_header_match()
    {
        $availableHeaders = ['Transaction Date', 'Amount USD', 'Merchant Name', 'Details'];

        // Test exact match
        $result = $this->service->findBestHeaderMatch('Amount USD', $availableHeaders);
        $this->assertEquals(1, $result);

        // Test fuzzy match
        $result = $this->service->findBestHeaderMatch('Date', $availableHeaders);
        $this->assertEquals(0, $result); // Should match 'Transaction Date'

        // Test contains match
        $result = $this->service->findBestHeaderMatch('Merchant', $availableHeaders);
        $this->assertEquals(2, $result); // Should match 'Merchant Name'

        // Test no match
        $result = $this->service->findBestHeaderMatch('Unknown Field', $availableHeaders);
        $this->assertNull($result);
    }

    public function test_is_index_based_mapping()
    {
        // Index-based mapping
        $indexMapping = [
            'booked_date' => 0,
            'amount' => 1,
            'partner' => null,
        ];
        $this->assertTrue($this->service->isIndexBasedMapping($indexMapping));

        // Header-based mapping
        $headerMapping = [
            'booked_date' => 'Date',
            'amount' => 'Amount',
            'partner' => null,
        ];
        $this->assertFalse($this->service->isIndexBasedMapping($headerMapping));

        // Mixed mapping (should be considered header-based)
        $mixedMapping = [
            'booked_date' => 0,
            'amount' => 'Amount',
            'partner' => null,
        ];
        $this->assertFalse($this->service->isIndexBasedMapping($mixedMapping));
    }

    public function test_real_world_scenario_fixing_mismatched_mapping()
    {
        // Scenario: User saves mapping for CSV with headers ['Date', 'Amount', 'Partner']
        $originalHeaders = ['Date', 'Amount', 'Partner'];
        $originalIndexMapping = [
            'booked_date' => 0,
            'amount' => 1,
            'partner' => 2,
        ];

        // Convert to header-based for storage (this would happen during save)
        $headerMapping = $this->service->convertIndexMappingToHeaders($originalIndexMapping, $originalHeaders);

        // Later, user uploads CSV with different structure ['Partner', 'Date', 'Amount']
        $newHeaders = ['Partner', 'Date', 'Amount'];

        // Apply the saved header-based mapping to new structure
        $appliedMapping = $this->service->convertHeaderMappingToIndices($headerMapping, $newHeaders);

        // Verify that mapping is correctly applied to new positions
        $this->assertEquals(1, $appliedMapping['booked_date']); // 'Date' moved from index 0 to 1
        $this->assertEquals(2, $appliedMapping['amount']); // 'Amount' moved from index 1 to 2
        $this->assertEquals(0, $appliedMapping['partner']); // 'Partner' moved from index 2 to 0

        // Validate the applied mapping
        $validation = $this->service->validateMapping($appliedMapping, $newHeaders);
        $this->assertTrue($validation['valid']);
    }
}
