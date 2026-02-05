<?php

declare(strict_types=1);

namespace App\Services\TransactionImport\Parsers;

readonly class AmountParseResult
{
    public function __construct(
        public float $amount,
        public string $format,
        public float $confidence,
        /** @var list<string> */
        public array $warnings = [],
    ) {}

    public static function success(float $amount, string $format, float $confidence = 1.0, array $warnings = []): self
    {
        return new self($amount, $format, $confidence, $warnings);
    }
}
