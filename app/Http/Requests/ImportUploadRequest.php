<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => 'required|file|mimes:csv,txt|max:2048',
            'account_id' => 'required|exists:accounts,id',
            'delimiter' => 'required|string|size:1',
            'quote_char' => 'required|string|size:1',
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

    public function getQuoteChar(): ?string
    {
        return $this->input('quote_char');
    }
}
