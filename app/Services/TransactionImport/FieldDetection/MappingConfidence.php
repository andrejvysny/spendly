<?php

declare(strict_types=1);

namespace App\Services\TransactionImport\FieldDetection;

readonly class MappingConfidence
{
    public function __construct(
        public ?string $suggestedField,
        public float $confidence,
        /** @var array<string, float> */
        public array $signals = [],
    ) {}

    public static function none(): self
    {
        return new self(null, 0.0, []);
    }
}
