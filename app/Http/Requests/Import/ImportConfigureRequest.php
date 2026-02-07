<?php

namespace App\Http\Requests\Import;

use Illuminate\Foundation\Http\FormRequest;

class ImportConfigureRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'column_mapping' => 'required|array',
            'date_format' => 'required|string',
            'amount_format' => 'required|string',
            'amount_type_strategy' => 'required|string',
            'currency' => 'required|string|size:3',
        ];
    }

    public function getColumnMapping(): array
    {
        return (array) $this->input('column_mapping', []);
    }

    public function getDateFormat(): string
    {
        return (string) $this->input('date_format');
    }

    public function getAmountFormat(): string
    {
        return (string) $this->input('amount_format');
    }

    public function getAmountTypeStrategy(): string
    {
        return (string) $this->input('amount_type_strategy');
    }

    public function getCurrency(): string
    {
        return (string) $this->input('currency');
    }
}
