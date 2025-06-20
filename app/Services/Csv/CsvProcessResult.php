<?php

namespace App\Services\Csv;

use App\Contracts\Import\ProcessResultInterface;

/**
 * Generic implementation of ProcessResultInterface for CSV processing.
 */
readonly class CsvProcessResult implements ProcessResultInterface
{
    public function __construct(
        private bool              $success = true,
        private string            $message,
        private array|object|null $data = null,
        private array             $errors = [],
        private bool              $skipped = false,
        private ?array            $metadata = null
    ) {
        if (empty($message)) {
            throw new \InvalidArgumentException('Message cannot be empty');
        }
    }

    public static function success(string $message, array|object $data,array $metadata = []): self
    {
        return new self(true, $message, $data, metadata: $metadata);
    }

    public static function failure(string $message,array $data,array $metadata = [], array $errors = []): self
    {
        return new self(false, $message, $data, $errors);
    }

    public static function skipped(string $message, array $data, array $metadata): self
    {
        return new self(false, $message, $data, [], true, $metadata);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function isSkipped(): bool
    {
        return $this->skipped;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getData(): array|object|null
    {
        return $this->data;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }
}
