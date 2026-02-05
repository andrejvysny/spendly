<?php

declare(strict_types=1);

namespace Tests\Unit\Services\TransactionImport;

use App\Services\TransactionImport\FieldDetection\HeaderAnalyzer;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('import')]
class HeaderAnalyzerTest extends TestCase
{
    private HeaderAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new HeaderAnalyzer;
    }

    public function test_analyze_matches_amount(): void
    {
        $result = $this->analyzer->analyze('Amount');
        $this->assertTrue($result->hasMatch());
        $this->assertSame('amount', $result->field);
    }

    public function test_analyze_matches_date(): void
    {
        $result = $this->analyzer->analyze('Dátum');
        $this->assertTrue($result->hasMatch());
        $this->assertSame('booked_date', $result->field);
    }

    public function test_analyze_no_match(): void
    {
        $result = $this->analyzer->analyze('Random Column XYZ');
        $this->assertFalse($result->hasMatch());
        $this->assertNull($result->field);
    }

    public function test_jaro_winkler_exact_match(): void
    {
        $sim = $this->analyzer->jaroWinklerSimilarity('amount', 'amount');
        $this->assertSame(1.0, $sim);
    }
}
