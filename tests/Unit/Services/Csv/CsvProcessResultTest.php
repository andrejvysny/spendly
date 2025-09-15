<?php

namespace Tests\Unit\Services\Csv;

use App\Services\Csv\CsvProcessResult;
use Tests\Unit\UnitTestCase;

class CsvProcessResultTest extends UnitTestCase
{
    public function test_success_result_creation(): void
    {
        $data = ['id' => 1, 'name' => 'Test'];
        $metadata = ['row_number' => 1];

        $result = CsvProcessResult::success('Success message', $data, $metadata);

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->isSkipped());
        $this->assertEquals('Success message', $result->getMessage());
        $this->assertEquals($data, $result->getData());
        $this->assertEquals($metadata, $result->getMetadata());
        $this->assertEmpty($result->getErrors());
    }

    public function test_failure_result_creation(): void
    {
        $data = ['id' => 1, 'name' => 'Test'];
        $errors = ['ConditionField validation failed'];
        $metadata = ['row_number' => 1];

        $result = CsvProcessResult::failure('Failure message', $data, $metadata, $errors);

        $this->assertFalse($result->isSuccess());
        $this->assertFalse($result->isSkipped());
        $this->assertEquals('Failure message', $result->getMessage());
        $this->assertEquals($data, $result->getData());
        $this->assertEquals($errors, $result->getErrors());
    }

    public function test_skipped_result_creation(): void
    {
        $data = ['id' => 1, 'name' => 'Test'];
        $metadata = ['row_number' => 1];

        $result = CsvProcessResult::skipped('Skipped message', $data, $metadata);

        $this->assertFalse($result->isSuccess());
        $this->assertTrue($result->isSkipped());
        $this->assertEquals('Skipped message', $result->getMessage());
        $this->assertEquals($data, $result->getData());
        $this->assertEquals($metadata, $result->getMetadata());
        $this->assertEmpty($result->getErrors());
    }

    public function test_empty_message_throws_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Message cannot be empty');

        new CsvProcessResult(true, '', ['data']);
    }

    public function test_constructor_with_all_parameters(): void
    {
        $result = new CsvProcessResult(
            success: true,
            message: 'Test message',
            data: ['test' => 'data'],
            errors: ['error1', 'error2'],
            skipped: false,
            metadata: ['meta' => 'data']
        );

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->isSkipped());
        $this->assertEquals('Test message', $result->getMessage());
        $this->assertEquals(['test' => 'data'], $result->getData());
        $this->assertEquals(['error1', 'error2'], $result->getErrors());
        $this->assertEquals(['meta' => 'data'], $result->getMetadata());
    }

    public function test_result_with_object_data(): void
    {
        $object = (object) ['id' => 1, 'name' => 'Test'];

        $result = CsvProcessResult::success('Success with object', $object);

        $this->assertEquals($object, $result->getData());
        $this->assertIsObject($result->getData());
    }
}
