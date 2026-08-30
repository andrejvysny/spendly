<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGoCardlessCredentialsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Both halves of the pair, or neither.
     *
     * `required_with` passes when every listed field is empty (the "leave blank to keep current"
     * case) and fails the moment one half arrives without the other. Rejecting a half-filled pair
     * here is what keeps CredentialsResolver from ever meeting one, so a typo surfaces as a
     * validation error on the field instead of a broken sync later.
     *
     * The 255 cap applies to the plaintext GoCardless secrets, which are UUID-shaped; the columns
     * are TEXT because the values are encrypted at rest and the ciphertext is far longer.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'gocardless_secret_id' => ['nullable', 'string', 'max:255', 'required_with:gocardless_secret_key'],
            'gocardless_secret_key' => ['nullable', 'string', 'max:255', 'required_with:gocardless_secret_id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'gocardless_secret_id.required_with' => 'Enter the Secret ID as well — GoCardless needs both halves of the pair.',
            'gocardless_secret_key.required_with' => 'Enter the Secret Key as well — GoCardless needs both halves of the pair.',
        ];
    }
}
