<?php

namespace App\Services\TransactionImport;

class ImportFailureDto
{
    public function __construct(
        private readonly int $import_id,
        private readonly ?int $row_number = null,
        private readonly string $raw_data = '',
        private readonly ImportFailureType $error_type = ImportFailureType::UNKNOWN_ERROR,
        private readonly string $error_message = '',
        private array $error_details = [],
        private readonly array $parsed_data = [],
        private array $metadata = [],
        private readonly ImportFailureStatus $status = ImportFailureStatus::PENDING,
        private readonly ?string $review_notes = null,
        private readonly ?\DateTime $reviewed_at = null,
        private readonly ?int $reviewed_by = null
    ) {}

    public function pushMetadata(string $key, mixed $value): void
    {
        $this->metadata[$key] = $value;
    }

    public function pushErrorDetail(string $key, mixed $value): void
    {
        $this->error_details[$key] = $value;
    }

    public function getImportId(): int
    {
        return $this->import_id;
    }

    public function getRowNumber(): ?int
    {
        return $this->row_number;
    }

    public function getRawData(): string
    {
        return $this->raw_data;
    }

    public function getErrorType(): ImportFailureType
    {
        return $this->error_type;
    }

    public function getErrorMessage(): string
    {
        return $this->error_message;
    }

    public function getErrorDetails(): array
    {
        return $this->error_details;
    }

    public function getParsedData(): array
    {
        return $this->parsed_data;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getStatus(): ImportFailureStatus
    {
        return $this->status;
    }

    public function getReviewNotes(): ?string
    {
        return $this->review_notes;
    }

    public function getReviewedAt(): ?\DateTime
    {
        return $this->reviewed_at;
    }

    public function getReviewedBy(): ?int
    {
        return $this->reviewed_by;
    }
}
