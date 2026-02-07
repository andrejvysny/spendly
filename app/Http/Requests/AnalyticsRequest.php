<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AnalyticsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [

            'account_ids' => ['nullable', 'array'],
            'account_ids.*' => ['integer', 'exists:accounts,id'],
        ];
    }

    /**
     * @return array<int>
     */
    public function getAccountIds(): array
    {
        return $this->input('account_ids', []);
    }

    public function getPeriod(): mixed
    {
        return $this->input('period', 'last_month');
    }

    public function getSpecificMonth(): mixed
    {
        return $this->input('specific_month', null);  // Format: YYYY-MM
    }

    public function getStartDate(): mixed
    {
        return $this->input('start_date', null);
    }

    public function getEndDate(): mixed
    {
        return $this->input('end_date', null);
    }
}
