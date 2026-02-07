<?php

namespace Tests\Unit\Services\TransactionImport;

use App\Services\Csv\CsvProcessResult;
use App\Services\DuplicateTransactionService;
use App\Services\TransactionImport\TransactionDataParser;
use App\Services\TransactionImport\TransactionDto;
use App\Services\TransactionImport\TransactionRowProcessor;
use App\Services\TransactionImport\TransactionValidator;
use App\Services\TransactionImport\ValidationResult;
use Illuminate\Support\Facades\Auth;
use Tests\Unit\UnitTestCase;

class TransactionRowProcessorTest extends UnitTestCase
{
    private TransactionRowProcessor $processor;

    private TransactionDataParser $parser;

    private TransactionValidator $validator;

    private DuplicateTransactionService $duplicateService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = $this->createMock(TransactionDataParser::class);
        $this->validator = $this->createMock(TransactionValidator::class);
        $this->duplicateService = $this->createMock(DuplicateTransactionService::class);

        $this->processor = new TransactionRowProcessor(
            $this->parser,
            $this->validator,
            $this->duplicateService
        );
    }

    public function test_configure_with_valid_configuration(): void
    {
        $configuration = [
            'column_mapping' => ['date' => 0, 'amount' => 1],
            'date_format' => 'Y-m-d',
            'amount_format' => '1,234.56',
        ];

        $this->processor->configure($configuration);

        // No exception should be thrown
        $this->assertTrue(true);
    }

    public function test_configure_with_invalid_configuration(): void
    {
        $configuration = [
            'column_mapping' => ['date' => 0], // Missing required fields
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid configuration for TransactionRowProcessor');

        $this->processor->configure($configuration);
    }

    public function test_process_empty_row(): void
    {
        $row = ['', '', ''];
        $metadata = ['row_number' => 1];

        $configuration = [
            'column_mapping' => ['date' => 0, 'amount' => 1],
            'date_format' => 'Y-m-d',
            'amount_format' => '1,234.56',
        ];

        $this->processor->configure($configuration);

        $result = $this->processor->processRow($row, $metadata);

        $this->assertInstanceOf(CsvProcessResult::class, $result);
        $this->assertTrue($result->isSkipped());
        $this->assertEquals('Empty row 1', $result->getMessage());
    }

    public function test_process_valid_row(): void
    {
        $row = ['2023-12-25', '100.00', 'John Doe'];
        $metadata = ['row_number' => 1];

        $parsedData = [
            'booked_date' => '2023-12-25 00:00:00',
            'amount' => 100.00,
            'partner' => 'John Doe',
            'transaction_id' => 'TX123',
        ];

        $configuration = [
            'column_mapping' => ['date' => 0, 'amount' => 1, 'partner' => 2],
            'date_format' => 'Y-m-d',
            'amount_format' => '1,234.56',
        ];

        $this->processor->configure($configuration);

        $this->parser->expects($this->once())
            ->method('parse')
            ->with($row, $configuration)
            ->willReturn($parsedData);

        $validationResult = new ValidationResult(true, []);
        $this->validator->expects($this->once())
            ->method('validate')
            ->with($parsedData, $configuration)
            ->willReturn($validationResult);

        // Mock Auth facade
        Auth::shouldReceive('id')->andReturn(1);

        $this->duplicateService->expects($this->once())
            ->method('isDuplicate')
            ->with($parsedData, 1)
            ->willReturn(false);

        $result = $this->processor->processRow($row, $metadata);

        $this->assertInstanceOf(CsvProcessResult::class, $result);
        $this->assertTrue($result->isSuccess());
        $this->assertEquals('Transaction imported', $result->getMessage());
        $this->assertInstanceOf(TransactionDto::class, $result->getData());
    }

    public function test_process_invalid_row(): void
    {
        $row = ['2023-12-25', 'invalid-amount', 'John Doe'];
        $metadata = ['row_number' => 2];

        $parsedData = [
            'booked_date' => '2023-12-25 00:00:00',
            'amount' => null,
            'partner' => 'John Doe',
        ];

        $configuration = [
            'column_mapping' => ['date' => 0, 'amount' => 1, 'partner' => 2],
            'date_format' => 'Y-m-d',
            'amount_format' => '1,234.56',
        ];

        $this->processor->configure($configuration);

        $this->parser->expects($this->once())
            ->method('parse')
            ->with($row, $configuration)
            ->willReturn($parsedData);

        $validationResult = new ValidationResult(false, ['Amount is required']);
        $this->validator->expects($this->once())
            ->method('validate')
            ->with($parsedData, $configuration)
            ->willReturn($validationResult);

        $result = $this->processor->processRow($row, $metadata);

        $this->assertInstanceOf(CsvProcessResult::class, $result);
        $this->assertFalse($result->isSuccess());
        $this->assertFalse($result->isSkipped());
        $this->assertEquals('Validation failed for row 2', $result->getMessage());
        $this->assertEquals(['Amount is required'], $result->getErrors());
    }

    public function test_process_duplicate_row(): void
    {
        $row = ['2023-12-25', '100.00', 'John Doe'];
        $metadata = ['row_number' => 3];

        $parsedData = [
            'booked_date' => '2023-12-25 00:00:00',
            'amount' => 100.00,
            'partner' => 'John Doe',
            'transaction_id' => 'TX123',
        ];

        $configuration = [
            'column_mapping' => ['date' => 0, 'amount' => 1, 'partner' => 2],
            'date_format' => 'Y-m-d',
            'amount_format' => '1,234.56',
        ];

        $this->processor->configure($configuration);

        $this->parser->expects($this->once())
            ->method('parse')
            ->with($row, $configuration)
            ->willReturn($parsedData);

        $validationResult = new ValidationResult(true, []);
        $this->validator->expects($this->once())
            ->method('validate')
            ->with($parsedData, $configuration)
            ->willReturn($validationResult);

        // Mock Auth facade
        Auth::shouldReceive('id')->andReturn(1);

        $this->duplicateService->expects($this->once())
            ->method('isDuplicate')
            ->with($parsedData, 1)
            ->willReturn(true);

        $result = $this->processor->processRow($row, $metadata);

        $this->assertInstanceOf(CsvProcessResult::class, $result);
        $this->assertTrue($result->isSkipped());
        $this->assertEquals('Duplicate transaction', $result->getMessage());
    }

    public function test_process_preview_mode(): void
    {
        $row = ['2023-12-25', '100.00', 'John Doe'];
        $metadata = ['row_number' => 1];

        $parsedData = [
            'booked_date' => '2023-12-25 00:00:00',
            'amount' => 100.00,
            'partner' => 'John Doe',
        ];

        $configuration = [
            'column_mapping' => ['date' => 0, 'amount' => 1, 'partner' => 2],
            'date_format' => 'Y-m-d',
            'amount_format' => '1,234.56',
            'preview_mode' => true,
        ];

        $this->processor->configure($configuration);

        $this->parser->expects($this->once())
            ->method('parse')
            ->with($row, $configuration)
            ->willReturn($parsedData);

        $validationResult = new ValidationResult(true, []);
        $this->validator->expects($this->once())
            ->method('validate')
            ->with($parsedData, $configuration)
            ->willReturn($validationResult);

        // In preview mode, duplicate check should not be called
        $this->duplicateService->expects($this->never())
            ->method('isDuplicate');

        $result = $this->processor->processRow($row, $metadata);

        $this->assertInstanceOf(CsvProcessResult::class, $result);
        $this->assertTrue($result->isSuccess());
        $this->assertEquals('Preview data', $result->getMessage());
    }

    public function test_process_row_with_exception(): void
    {
        $row = ['2023-12-25', '100.00', 'John Doe'];
        $metadata = ['row_number' => 1];

        $configuration = [
            'column_mapping' => ['date' => 0, 'amount' => 1, 'partner' => 2],
            'date_format' => 'Y-m-d',
            'amount_format' => '1,234.56',
        ];

        $this->processor->configure($configuration);

        $this->parser->expects($this->once())
            ->method('parse')
            ->with($row, $configuration)
            ->willThrowException(new \Exception('Parsing error'));

        $result = $this->processor->processRow($row, $metadata);

        $this->assertInstanceOf(CsvProcessResult::class, $result);
        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('Processing error: Parsing error', $result->getMessage());
    }

    public function test_invoke_method(): void
    {
        $row = ['', '', ''];
        $metadata = ['row_number' => 1];

        $configuration = [
            'column_mapping' => ['date' => 0, 'amount' => 1],
            'date_format' => 'Y-m-d',
            'amount_format' => '1,234.56',
        ];

        $this->processor->configure($configuration);

        // Test the __invoke method
        $result = $this->processor->__invoke($row, $metadata);

        $this->assertInstanceOf(CsvProcessResult::class, $result);
        $this->assertTrue($result->isSkipped());
    }

    public function test_can_process_with_valid_configuration(): void
    {
        $configuration = [
            'column_mapping' => ['date' => 0, 'amount' => 1],
            'date_format' => 'Y-m-d',
            'amount_format' => '1,234.56',
        ];

        $canProcess = $this->processor->canProcess($configuration);

        $this->assertTrue($canProcess);
    }

    public function test_can_process_with_missing_required_fields(): void
    {
        $testCases = [
            ['date_format' => 'Y-m-d', 'amount_format' => '1,234.56'], // Missing column_mapping
            ['column_mapping' => [], 'amount_format' => '1,234.56'], // Missing date_format
            ['column_mapping' => [], 'date_format' => 'Y-m-d'], // Missing amount_format
        ];

        foreach ($testCases as $configuration) {
            $canProcess = $this->processor->canProcess($configuration);
            $this->assertFalse($canProcess);
        }
    }
}
