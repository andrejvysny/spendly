<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AccountRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'bank_name' => 'nullable|string|max:255',
            'iban' => 'nullable|string|max:255',
            'type' => 'required|string|max:255',
            'currency' => 'required|string|max:3',
            'balance' => 'required|numeric',
            'is_gocardless_synced' => 'boolean',
            'gocardless_account_id' => 'nullable|string|max:255',
        ];
    }
}
