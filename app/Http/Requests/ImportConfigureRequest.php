<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportConfigureRequest extends FormRequest
{
    /**
     * Returns the validation rules for the import configuration request.
     *
     * @return array The array of validation rules for required input fields.
     */
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

    /**
     * Retrieves the column mapping from the request input.
     *
     * @return array The column mapping array, or an empty array if not provided.
     */
    public function getColumnMapping(): array
    {
        return $this->input('column_mapping', []);
    }

    /**
     * Retrieves the date format specified in the request input.
     *
     * @return string The date format string from the request.
     */
    public function getDateFormat(): string
    {
        return $this->input('date_format');
    }

    /**
     * Retrieves the amount format value from the request input.
     *
     * @return string The amount format specified in the request.
     */
    public function getAmountFormat(): string
    {
        return $this->input('amount_format');
    }

    /**
     * Retrieves the amount type strategy from the request input.
     *
     * @return string The value of the 'amount_type_strategy' field.
     */
    public function getAmountTypeStrategy(): string
    {
        return $this->input('amount_type_strategy');
    }

    /**
     * Retrieves the currency code from the request input.
     *
     * @return string The three-character currency code.
     */
    public function getCurrency(): string
    {
        return $this->input('currency');
    }
}
