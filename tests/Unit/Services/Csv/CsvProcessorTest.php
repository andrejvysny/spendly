<?php

namespace Tests\Unit\Services\Csv;

use App\Services\Csv\CsvData;
use App\Services\Csv\CsvProcessor;
use Illuminate\Support\Facades\Storage;
use Tests\Unit\UnitTestCase;

class CsvProcessorTest extends UnitTestCase
{
    private CsvProcessor $csvProcessor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->csvProcessor = new CsvProcessor;
    }

    public function test_get_rows_returns_csv_data_object()
    {
        // Create a test CSV file
        $csvContent = "name,email,age\nJohn Doe,john@example.com,30\nJane Smith,jane@example.com,25";
        $path = 'test.csv';
        Storage::put($path, $csvContent);

        $csvData = $this->csvProcessor->getRows($path, ',', '"', null);

        $this->assertInstanceOf(CsvData::class, $csvData);
        $this->assertEquals(['name', 'email', 'age'], $csvData->getHeaders());
        $this->assertEquals(2, $csvData->count());
        $this->assertEquals(['John Doe', 'john@example.com', '30'], $csvData->getRow(0));
        $this->assertEquals(['Jane Smith', 'jane@example.com', '25'], $csvData->getRow(1));

        // Clean up
        Storage::delete($path);
    }

    public function test_get_rows_without_headers_returns_csv_data_object()
    {
        // Create a test CSV file without headers
        $csvContent = "John Doe,john@example.com,30\nJane Smith,jane@example.com,25";
        $path = 'test.csv';
        Storage::put($path, $csvContent);

        $csvData = $this->csvProcessor->getRows($path, ',', '"', null);

        $this->assertInstanceOf(CsvData::class, $csvData);
        // The current implementation always treats the first row as headers
        // So we expect the first data row to be treated as headers
        $this->assertEquals(['John Doe', 'john@example.com', '30'], $csvData->getHeaders());
        $this->assertEquals(1, $csvData->count());
        $this->assertEquals(['Jane Smith', 'jane@example.com', '25'], $csvData->getRow(0));

        // Clean up
        Storage::delete($path);
    }

    public function test_get_rows_with_limit_returns_limited_csv_data()
    {
        // Create a test CSV file
        $csvContent = "name,email,age\nJohn Doe,john@example.com,30\nJane Smith,jane@example.com,25\nBob Johnson,bob@example.com,35";
        $path = 'test.csv';
        Storage::put($path, $csvContent);

        $csvData = $this->csvProcessor->getRows($path, ',', '"', 2);

        $this->assertInstanceOf(CsvData::class, $csvData);
        $this->assertEquals(['name', 'email', 'age'], $csvData->getHeaders());
        $this->assertEquals(2, $csvData->count());
        $this->assertEquals(['John Doe', 'john@example.com', '30'], $csvData->getRow(0));
        $this->assertEquals(['Jane Smith', 'jane@example.com', '25'], $csvData->getRow(1));

        // Clean up
        Storage::delete($path);
    }

    public function test_get_rows_throws_exception_for_nonexistent_file()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CSV file not found: nonexistent.csv');

        $this->csvProcessor->getRows('nonexistent.csv', ',', '"');
    }
}
