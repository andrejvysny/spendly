<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AnalyticsRequest extends FormRequest
{
    public function rules(): array
    {
        return [

            'account_ids' => ['nullable', 'array'],
            'account_ids.*' => ['integer', 'exists:accounts,id'],
        ];
    }

    public function getAccountIds(): array
    {
        return $this->input('account_ids', []);
    }

    public function getPeriod()
    {
        return $this->input('period', 'last_month');
    }

    public function getSpecificMonth()
    {
        return $this->input('specific_month', null);  // Format: YYYY-MM
    }

    public function getStartDate()
    {
        return $this->input('start_date', null);
    }

    public function getEndDate()
    {
        return $this->input('end_date', null);
    }
}
