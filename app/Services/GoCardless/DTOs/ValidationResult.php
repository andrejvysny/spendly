<?php

declare(strict_types=1);

namespace App\Services\GoCardless\DTOs;

readonly class ValidationResult
{
    public function __construct(
        public array $data,
        /** @var list<string> */
        public array $errors,
        /** @var list<string> */
        public array $warnings,
        public bool $needsReview,
        /** @var list<string> */
        public array $reviewReasons,
    ) {}

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function isValid(): bool
    {
        return ! $this->hasErrors();
    }
}
