<?php

declare(strict_types=1);

namespace App\Services\TransactionImport\FieldDetection;

readonly class HeaderAnalysisResult
{
    public function __construct(
        public ?string $field,
        public float $similarity,
        public string $level,
    ) {}

    public static function noMatch(): self
    {
        return new self(null, 0.0, 'none');
    }

    public function hasMatch(): bool
    {
        return $this->field !== null && $this->similarity > 0;
    }
}
