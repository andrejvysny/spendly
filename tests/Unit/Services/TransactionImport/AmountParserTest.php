<?php

declare(strict_types=1);

namespace Tests\Unit\Services\TransactionImport;

use App\Services\TransactionImport\Parsers\AmountParser;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('import')]
class AmountParserTest extends TestCase
{
    private AmountParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new AmountParser;
    }

    public function test_parses_eu_format(): void
    {
        $result = $this->parser->parse('1.234,56');
        $this->assertSame(1234.56, $result->amount);
        $this->assertSame(AmountParser::FORMAT_EU, $result->format);
    }

    public function test_parses_us_format(): void
    {
        $result = $this->parser->parse('1,234.56');
        $this->assertSame(1234.56, $result->amount);
        $this->assertSame(AmountParser::FORMAT_US, $result->format);
    }

    public function test_parentheses_negative(): void
    {
        $result = $this->parser->parse('(100.00)');
        $this->assertSame(-100.0, $result->amount);
    }

    public function test_detect_format_from_samples(): void
    {
        $samples = ['1.234,56', '2.000,00', '99,99'];
        $format = $this->parser->detectFormatFromSamples($samples);
        $this->assertSame(AmountParser::FORMAT_EU, $format);
    }

    public function test_simple_format(): void
    {
        $result = $this->parser->parse('42.5');
        $this->assertSame(42.5, $result->amount);
    }
}
