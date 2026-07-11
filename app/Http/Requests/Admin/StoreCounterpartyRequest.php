<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\CounterpartyType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCounterpartyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_superadmin === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['nullable', 'string', Rule::enum(CounterpartyType::class)],
            'target_user_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }
}
