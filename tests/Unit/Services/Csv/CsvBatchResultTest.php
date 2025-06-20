<?php

namespace Tests\Unit\Services\Csv;

use App\Services\Csv\CsvBatchResult;
use App\Services\Csv\CsvProcessResult;
use Tests\Unit\UnitTestCase;

class CsvBatchResultTest extends UnitTestCase
{
    private CsvBatchResult $batchResult;

    protected function setUp(): void
    {
        parent::setUp();
        $this->batchResult = new CsvBatchResult();
    }

    public function test_initial_state()
    {
        $this->assertEquals(0, $this->batchResult->getTotalProcessed());
        $this->assertEquals(0, $this->batchResult->getSuccessCount());
        $this->assertEquals(0, $this->batchResult->getFailedCount());
        $this->assertEquals(0, $this->batchResult->getSkippedCount());
        $this->assertEmpty($this->batchResult->getResults());
        $this->assertFalse($this->batchResult->isCompleteSuccess());
    }

    public function test_add_success_result()
    {
        $result = CsvProcessResult::success('Success', ['data']);
        
        $this->batchResult->addResult($result);
        
        $this->assertEquals(1, $this->batchResult->getTotalProcessed());
        $this->assertEquals(1, $this->batchResult->getSuccessCount());
        $this->assertEquals(0, $this->batchResult->getFailedCount());
        $this->assertEquals(0, $this->batchResult->getSkippedCount());
        $this->assertTrue($this->batchResult->isCompleteSuccess());
    }

    public function test_add_failure_result()
    {
        $result = CsvProcessResult::failure('Failed', ['data']);
        
        $this->batchResult->addResult($result);
        
        $this->assertEquals(1, $this->batchResult->getTotalProcessed());
        $this->assertEquals(0, $this->batchResult->getSuccessCount());
        $this->assertEquals(1, $this->batchResult->getFailedCount());
        $this->assertEquals(0, $this->batchResult->getSkippedCount());
        $this->assertFalse($this->batchResult->isCompleteSuccess());
    }

    public function test_add_skipped_result()
    {
        $result = CsvProcessResult::skipped('Skipped', ['data'], []);
        
        $this->batchResult->addResult($result);
        
        $this->assertEquals(1, $this->batchResult->getTotalProcessed());
        $this->assertEquals(0, $this->batchResult->getSuccessCount());
        $this->assertEquals(0, $this->batchResult->getFailedCount());
        $this->assertEquals(1, $this->batchResult->getSkippedCount());
        $this->assertTrue($this->batchResult->isCompleteSuccess());
    }

    public function test_mixed_results()
    {
        $success1 = CsvProcessResult::success('Success 1', ['data1']);
        $success2 = CsvProcessResult::success('Success 2', ['data2']);
        $failure = CsvProcessResult::failure('Failed', ['data3']);
        $skipped = CsvProcessResult::skipped('Skipped', ['data4'], []);
        
        $this->batchResult->addResult($success1);
        $this->batchResult->addResult($success2);
        $this->batchResult->addResult($failure);
        $this->batchResult->addResult($skipped);
        
        $this->assertEquals(4, $this->batchResult->getTotalProcessed());
        $this->assertEquals(2, $this->batchResult->getSuccessCount());
        $this->assertEquals(1, $this->batchResult->getFailedCount());
        $this->assertEquals(1, $this->batchResult->getSkippedCount());
        $this->assertFalse($this->batchResult->isCompleteSuccess());
    }

    public function test_get_failed_results()
    {
        $success = CsvProcessResult::success('Success', ['data1']);
        $failure1 = CsvProcessResult::failure('Failed 1', ['data2']);
        $failure2 = CsvProcessResult::failure('Failed 2', ['data3']);
        $skipped = CsvProcessResult::skipped('Skipped', ['data4'], []);
        
        $this->batchResult->addResult($success);
        $this->batchResult->addResult($failure1);
        $this->batchResult->addResult($failure2);
        $this->batchResult->addResult($skipped);
        
        $failedResults = $this->batchResult->getFailedResults();
        
        $this->assertCount(2, $failedResults);
        $this->assertEquals('Failed 1', $failedResults[1]->getMessage());
        $this->assertEquals('Failed 2', $failedResults[2]->getMessage());
    }

    public function test_get_success_results()
    {
        $success1 = CsvProcessResult::success('Success 1', ['data1']);
        $success2 = CsvProcessResult::success('Success 2', ['data2']);
        $failure = CsvProcessResult::failure('Failed', ['data3']);
        $skipped = CsvProcessResult::skipped('Skipped', ['data4'], []);
        
        $this->batchResult->addResult($success1);
        $this->batchResult->addResult($success2);
        $this->batchResult->addResult($failure);
        $this->batchResult->addResult($skipped);
        
        $successResults = $this->batchResult->getSuccessResults();
        
        $this->assertCount(2, $successResults);
        $this->assertEquals('Success 1', $successResults[0]->getMessage());
        $this->assertEquals('Success 2', $successResults[1]->getMessage());
    }

    public function test_get_skipped_results()
    {
        $success = CsvProcessResult::success('Success', ['data1']);
        $failure = CsvProcessResult::failure('Failed', ['data2']);
        $skipped1 = CsvProcessResult::skipped('Skipped 1', ['data3'], []);
        $skipped2 = CsvProcessResult::skipped('Skipped 2', ['data4'], []);
        
        $this->batchResult->addResult($success);
        $this->batchResult->addResult($failure);
        $this->batchResult->addResult($skipped1);
        $this->batchResult->addResult($skipped2);
        
        $skippedResults = $this->batchResult->getSkippedResults();
        
        $this->assertCount(2, $skippedResults);
        $this->assertEquals('Skipped 1', $skippedResults[2]->getMessage());
        $this->assertEquals('Skipped 2', $skippedResults[3]->getMessage());
    }

    public function test_iterator_interface()
    {
        $result1 = CsvProcessResult::success('Success 1', ['data1']);
        $result2 = CsvProcessResult::success('Success 2', ['data2']);
        $result3 = CsvProcessResult::success('Success 3', ['data3']);
        
        $this->batchResult->addResult($result1);
        $this->batchResult->addResult($result2);
        $this->batchResult->addResult($result3);
        
        $messages = [];
        foreach ($this->batchResult as $key => $result) {
            if ($result !== null) {
                $messages[$key] = $result->getMessage();
            }
        }
        
        $this->assertEquals([
            0 => 'Success 1',
            1 => 'Success 2',
            2 => 'Success 3',
        ], $messages);
    }

    public function test_empty_batch_is_not_complete_success()
    {
        $this->assertFalse($this->batchResult->isCompleteSuccess());
    }

    public function test_batch_with_only_skipped_is_complete_success()
    {
        $skipped1 = CsvProcessResult::skipped('Skipped 1', ['data1'], []);
        $skipped2 = CsvProcessResult::skipped('Skipped 2', ['data2'], []);
        
        $this->batchResult->addResult($skipped1);
        $this->batchResult->addResult($skipped2);
        
        $this->assertTrue($this->batchResult->isCompleteSuccess());
    }
} 