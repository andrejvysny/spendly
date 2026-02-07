<?php

declare(strict_types=1);

namespace Tests\Unit\Services\TransactionImport;

use App\Services\TransactionImport\Parsers\DateParser;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('import')]
class DateParserTest extends TestCase
{
    private DateParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new DateParser;
    }

    public function test_parses_iso_date(): void
    {
        $result = $this->parser->parse('2026-02-05');
        $this->assertTrue($result->isValid());
        $this->assertInstanceOf(Carbon::class, $result->date);
        $this->assertSame('2026-02-05', $result->date->format('Y-m-d'));
    }

    public function test_parses_dmy_with_slashes(): void
    {
        $result = $this->parser->parse('05/02/2026', null, 'sk');
        $this->assertTrue($result->isValid());
        $this->assertSame(5, $result->date->day);
        $this->assertSame(2, $result->date->month);
    }

    public function test_detect_format_from_samples_iso(): void
    {
        $samples = ['2026-02-05', '2026-01-01'];
        $format = $this->parser->detectFormatFromSamples($samples);
        $this->assertSame(DateParser::FORMAT_ISO, $format);
    }

    public function test_detect_format_from_samples_impossible_day_reveals_dmy(): void
    {
        $samples = ['31/12/2025', '15/06/2025'];
        $format = $this->parser->detectFormatFromSamples($samples);
        $this->assertSame(DateParser::FORMAT_DMY, $format);
    }
}
