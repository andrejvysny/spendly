<?php

namespace Tests\Unit\Services\Csv;

use App\Services\Csv\CsvBatchResult;
use App\Services\Csv\CsvProcessor;
use App\Services\Csv\CsvProcessResult;
use App\Services\Csv\CsvRowProcessor;
use Illuminate\Support\Facades\Storage;
use Tests\Unit\UnitTestCase;

class CsvProcessorProcessRowsTest extends UnitTestCase
{
    private CsvProcessor $csvProcessor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->csvProcessor = new CsvProcessor;
    }

    public function test_process_rows_with_headers()
    {
        // Create a test CSV file
        $csvContent = "name,email,age\nJohn Doe,john@example.com,30\nJane Smith,jane@example.com,25";
        $path = 'test.csv';
        Storage::put($path, $csvContent);

        // Create a mock processor
        $processor = new class implements CsvRowProcessor
        {
            public function __invoke(array $row, array $metadata = []): CsvProcessResult
            {
                return CsvProcessResult::success('Row processed', $row, $metadata);
            }
        };

        $result = $this->csvProcessor->processRows($path, ',', '"', $processor, true);
        $this->assertNotNull($result);
        $this->assertInstanceOf(CsvBatchResult::class, $result);
        $this->assertEquals(2, $result->getTotalProcessed());
        $this->assertEquals(2, $result->getSuccessCount());
        $this->assertEquals(0, $result->getFailedCount());
        $this->assertTrue($result->isCompleteSuccess());

        // Clean up
        Storage::delete($path);
    }

    public function test_process_rows_without_headers()
    {
        // Create a test CSV file
        $csvContent = "John Doe,john@example.com,30\nJane Smith,jane@example.com,25";
        $path = 'test.csv';
        Storage::put($path, $csvContent);

        // Create a mock processor that verifies no headers are passed
        $processor = new class implements CsvRowProcessor
        {
            public function __invoke(array $row, array $metadata = []): CsvProcessResult
            {
                // When skip_header is false, headers should be null in metadata
                if (isset($metadata['headers'])) {
                    throw new \Exception('Headers should not be set when skip_header is false');
                }

                return CsvProcessResult::success('Row processed', $row, $metadata);
            }
        };

        $result = $this->csvProcessor->processRows($path, ',', '"', $processor, false);
        $this->assertNotNull($result);
        $this->assertInstanceOf(CsvBatchResult::class, $result);
        $this->assertEquals(2, $result->getTotalProcessed());
        $this->assertEquals(2, $result->getSuccessCount());

        // Clean up
        Storage::delete($path);
    }

    public function test_process_rows_with_limit()
    {
        // Create a test CSV file
        $csvContent = "name,email,age\nJohn Doe,john@example.com,30\nJane Smith,jane@example.com,25\nBob Johnson,bob@example.com,35";
        $path = 'test.csv';
        Storage::put($path, $csvContent);

        $processor = new class implements CsvRowProcessor
        {
            public function __invoke(array $row, array $metadata = []): CsvProcessResult
            {
                return CsvProcessResult::success('Row processed', $row, $metadata);
            }
        };

        $result = $this->csvProcessor->processRows($path, ',', '"', $processor, true, 2);

        $this->assertNotNull($result);
        $this->assertEquals(2, $result->getTotalProcessed());
        $this->assertEquals(2, $result->getSuccessCount());

        // Clean up
        Storage::delete($path);
    }

    public function test_process_rows_with_failing_processor()
    {
        $csvContent = "name,email,age\nJohn Doe,john@example.com,30\nJane Smith,jane@example.com,25";
        $path = 'test.csv';
        Storage::put($path, $csvContent);

        $processor = new class implements CsvRowProcessor
        {
            private int $count = 0;

            public function __invoke(array $row, array $metadata = []): CsvProcessResult
            {
                $this->count++;
                if ($this->count === 1) {
                    return CsvProcessResult::success('First row success', $row, $metadata);
                }

                return CsvProcessResult::failure('Second row failed', $row, $metadata);
            }
        };

        $result = $this->csvProcessor->processRows($path, ',', '"', $processor, true);
        $this->assertNotNull($result);
        $this->assertEquals(2, $result->getTotalProcessed());
        $this->assertEquals(1, $result->getSuccessCount());
        $this->assertEquals(1, $result->getFailedCount());
        $this->assertFalse($result->isCompleteSuccess());

        // Clean up
        Storage::delete($path);
    }

    public function test_process_rows_with_skipping_processor()
    {
        $csvContent = "name,email,age\nJohn Doe,john@example.com,30\n,,\nJane Smith,jane@example.com,25";
        $path = 'test.csv';
        Storage::put($path, $csvContent);

        $processor = new class implements CsvRowProcessor
        {
            public function __invoke(array $row, array $metadata = []): CsvProcessResult
            {
                // Skip empty rows
                if (empty(array_filter($row))) {
                    return CsvProcessResult::skipped('Empty row', $row, $metadata);
                }

                return CsvProcessResult::success('Row processed', $row, $metadata);
            }
        };

        $result = $this->csvProcessor->processRows($path, ',', '"', $processor, true);
        $this->assertNotNull($result);
        $this->assertEquals(3, $result->getTotalProcessed());
        $this->assertEquals(2, $result->getSuccessCount());
        $this->assertEquals(0, $result->getFailedCount());
        $this->assertEquals(1, $result->getSkippedCount());
        $this->assertTrue($result->isCompleteSuccess());

        // Clean up
        Storage::delete($path);
    }

    public function test_process_rows_with_exception_in_processor()
    {
        $csvContent = "name,email,age\nJohn Doe,john@example.com,30";
        $path = 'test.csv';
        Storage::put($path, $csvContent);

        $processor = new class implements CsvRowProcessor
        {
            public function __invoke(array $row, array $metadata = []): CsvProcessResult
            {
                throw new \Exception('Processing error');
            }
        };

        $result = $this->csvProcessor->processRows($path, ',', '"', $processor, true);
        $this->assertNotNull($result);
        $this->assertEquals(1, $result->getTotalProcessed());
        $this->assertEquals(0, $result->getSuccessCount());
        $this->assertEquals(1, $result->getFailedCount());
        $this->assertFalse($result->isCompleteSuccess());

        $failedResults = $result->getFailedResults();
        $this->assertCount(1, $failedResults);
        $this->assertStringContainsString('Processing error', $failedResults[0]->getMessage());

        // Clean up
        Storage::delete($path);
    }

    public function test_process_rows_metadata_includes_correct_info()
    {
        $csvContent = "name,email,age\nJohn Doe,john@example.com,30";
        $path = 'test.csv';
        Storage::put($path, $csvContent);

        $capturedMetadata = null;
        $processor = new class($capturedMetadata) implements CsvRowProcessor
        {
            private $capturedMetadata;

            public function __construct(&$capturedMetadata)
            {
                $this->capturedMetadata = &$capturedMetadata;
            }

            public function __invoke(array $row, array $metadata = []): CsvProcessResult
            {
                $this->capturedMetadata = $metadata;

                return CsvProcessResult::success('Row processed', $row, $metadata);
            }
        };

        $this->csvProcessor->processRows($path, ',', '"', $processor, true);

        $this->assertNotNull($capturedMetadata);
        $this->assertEquals(1, $capturedMetadata['row_number']); // First data row is 1 when skip_header=true
        $this->assertEquals(['name', 'email', 'age'], $capturedMetadata['headers']);
        $this->assertEquals(',', $capturedMetadata['delimiter']);
        $this->assertEquals('"', $capturedMetadata['quote_char']);
        $this->assertEquals($path, $capturedMetadata['file_path']);

        // Clean up
        Storage::delete($path);
    }

    public function test_process_rows_throws_exception_for_nonexistent_file()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CSV file not found: nonexistent.csv');

        $processor = new class implements CsvRowProcessor
        {
            public function __invoke(array $row, array $metadata = []): CsvProcessResult
            {
                return CsvProcessResult::success('Row processed', $row);
            }
        };

        $this->csvProcessor->processRows('nonexistent.csv', ',', '"', $processor);
    }
}
