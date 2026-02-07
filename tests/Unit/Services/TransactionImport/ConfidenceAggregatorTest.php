<?php

declare(strict_types=1);

namespace Tests\Unit\Services\TransactionImport;

use App\Services\TransactionImport\FieldDetection\ConfidenceAggregator;
use App\Services\TransactionImport\FieldDetection\DataProfiler;
use App\Services\TransactionImport\FieldDetection\HeaderAnalyzer;
use App\Services\TransactionImport\FieldDetection\PatternMatcher;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('import')]
class ConfidenceAggregatorTest extends TestCase
{
    private ConfidenceAggregator $aggregator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->aggregator = new ConfidenceAggregator(
            new HeaderAnalyzer,
            new DataProfiler,
            new PatternMatcher
        );
    }

    public function test_calculate_mapping_confidence_high_for_amount_header_and_values(): void
    {
        $header = 'Amount';
        $values = ['-10.50', '20.00', '1,234.56'];
        $result = $this->aggregator->calculateMappingConfidence($header, $values);
        $this->assertSame('amount', $result->suggestedField);
        $this->assertGreaterThanOrEqual(0.5, $result->confidence);
    }

    public function test_auto_map_threshold(): void
    {
        $this->assertSame(0.75, $this->aggregator->getAutoMapThreshold());
    }
}
