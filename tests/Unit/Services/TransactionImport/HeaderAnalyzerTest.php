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

    public function test_analyze_matches_processed_date_from_datum_valuty(): void
    {
        $result = $this->analyzer->analyze('Dátum valuty');
        $this->assertTrue($result->hasMatch());
        $this->assertSame('processed_date', $result->field);
    }

    public function test_analyze_matches_processed_date_from_valuta(): void
    {
        $result = $this->analyzer->analyze('valuta');
        $this->assertTrue($result->hasMatch());
        $this->assertSame('processed_date', $result->field);
    }

    public function test_analyze_matches_booked_date_from_datum_splatnosti(): void
    {
        $result = $this->analyzer->analyze('Dátum splatnosti');
        $this->assertTrue($result->hasMatch());
        $this->assertSame('booked_date', $result->field);
    }

    public function test_datum_valuty_does_not_match_booked_date(): void
    {
        $result = $this->analyzer->analyze('Dátum valuty');
        $this->assertTrue($result->hasMatch());
        $this->assertNotSame('booked_date', $result->field);
    }

    public function test_jaro_winkler_utf8_similarity(): void
    {
        // Multi-byte strings should produce valid similarity scores
        $sim = $this->analyzer->jaroWinklerSimilarity('dátum splatnosti', 'dátum splatnosti');
        $this->assertSame(1.0, $sim);

        // Close but not identical
        $sim = $this->analyzer->jaroWinklerSimilarity('dátum valuty', 'dátum splatnosti');
        $this->assertGreaterThan(0.0, $sim);
        $this->assertLessThan(0.85, $sim); // Should NOT be similar enough to match
    }

    public function test_analyze_matches_currency(): void
    {
        $result = $this->analyzer->analyze('Mena');
        $this->assertTrue($result->hasMatch());
        $this->assertSame('currency', $result->field);
    }

    public function test_analyze_matches_balance(): void
    {
        $result = $this->analyzer->analyze('Zostatok');
        $this->assertTrue($result->hasMatch());
        $this->assertSame('balance_after_transaction', $result->field);
    }
}
