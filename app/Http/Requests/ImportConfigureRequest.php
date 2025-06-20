<?php

namespace App\Http\Requests;

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
        return $this->input('column_mapping', []);
    }

    public function getDateFormat(): string
    {
        return $this->input('date_format');
    }

    public function getAmountFormat(): string
    {
        return $this->input('amount_format');
    }

    public function getAmountTypeStrategy(): string
    {
        return $this->input('amount_type_strategy');
    }

    public function getCurrency(): string
    {
        return $this->input('currency');
    }
}
