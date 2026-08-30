<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class CreateGoCardlessRequisitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'institution_id' => 'required|string|max:128',
            'return_to' => 'nullable|string|in:accounts,bank_data',
        ];
    }

    public function institutionId(): string
    {
        $value = $this->input('institution_id');

        return is_string($value) ? $value : '';
    }

    /**
     * Only 'accounts' changes the post-callback destination; anything else
     * (including the explicit 'bank_data') means the default settings page.
     */
    public function returnTo(): ?string
    {
        return $this->input('return_to') === 'accounts' ? 'accounts' : null;
    }
}
