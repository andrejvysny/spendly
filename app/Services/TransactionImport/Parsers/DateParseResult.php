<?php

declare(strict_types=1);

namespace App\Services\TransactionImport\Parsers;

use Carbon\Carbon;

readonly class DateParseResult
{
    public function __construct(
        public ?Carbon $date,
        public string $format,
        public float $confidence,
        /** @var list<string> */
        public array $warnings = [],
    ) {}

    public static function success(Carbon $date, string $format, float $confidence = 1.0, array $warnings = []): self
    {
        return new self($date, $format, $confidence, $warnings);
    }

    public static function failure(string $format = '', float $confidence = 0.0, array $warnings = []): self
    {
        return new self(null, $format, $confidence, $warnings);
    }

    public function isValid(): bool
    {
        return $this->date !== null;
    }
}
