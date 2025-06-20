<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportMappingRequest extends FormRequest
{
    /**
     * Returns the validation rules for the import mapping request.
     *
     * @return array The array of validation rules for each input field.
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'bank_name' => 'nullable|string|max:255',
            'column_mapping' => 'required|array',
            'date_format' => 'required|string',
            'amount_format' => 'required|string',
            'amount_type_strategy' => 'required|string',
            'currency' => 'required|string|size:3',
        ];
    }

    /**
     * Retrieves the 'name' input value from the request.
     *
     * @return string The name provided in the import mapping form.
     */
    public function getName(): string
    {
        return $this->input('name');
    }

    /**
     * Retrieves the bank name from the request input.
     *
     * @return string|null The bank name, or null if not provided.
     */
    public function getBankName(): ?string
    {
        return $this->input('bank_name');
    }

    /**
     * Retrieves the column mapping input as an array.
     *
     * @return array The column mapping data, or an empty array if not provided.
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
     * Retrieves the value of the 'amount_type_strategy' input as a string.
     *
     * @return string The amount type strategy specified in the request.
     */
    public function getAmountTypeStrategy(): string
    {
        return $this->input('amount_type_strategy');
    }

    /**
     * Retrieves the currency input value as a three-character string.
     *
     * @return string The currency code provided in the request.
     */
    public function getCurrency(): string
    {
        return $this->input('currency');
    }
}
