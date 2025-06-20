<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Returns the validation rules for the import file upload request.
     *
     * @return array The validation rules for file, account ID, delimiter, quote character, and optional sample rows count.
     */
    public function rules(): array
    {
        return [
            'file' => 'required|file|mimes:csv,txt|max:2048',
            'account_id' => 'required|exists:accounts,id',
            'delimiter' => 'required|string|size:1',
            'quote_char' => 'required|string|size:1',
            'sample_rows_count' => 'nullable|integer|min:1|max:1000', // Optional, default to 10
        ];
    }

    public function getFile(): ?\Illuminate\Http\UploadedFile
    {
        return $this->file('file');
    }

    public function getAccountId(): ?int
    {
        return $this->input('account_id');
    }

    public function getDelimiter(): ?string
    {
        return $this->input('delimiter');
    }

    /**
     * Retrieves the quote character specified in the import upload request.
     *
     * @return string|null The quote character, or null if not provided.
     */
    public function getQuoteChar(): ?string
    {
        return $this->input('quote_char');
    }

    /**
     * Retrieves the number of sample rows to process from the request input.
     *
     * Returns the value of 'sample_rows_count' as an integer, or 10 if not provided.
     *
     * @return int|null The number of sample rows to process.
     */
    public function getSampleRowsCount(): ?int
    {
        return $this->input('sample_rows_count', 10); // Default to 10 if not provided
    }
}
