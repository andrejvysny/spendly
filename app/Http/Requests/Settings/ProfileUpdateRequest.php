<?php

namespace App\Http\Requests\Settings;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Returns the validation rules for updating a user's profile.
     *
     * The rules ensure that the 'name' field, if provided, is a string, and that the 'email' field is required, must be a lowercase string in valid email format, has a maximum length of 255 characters, and is unique among users except for the current user.
     *
     * @return array<string, ValidationRule|array<mixed>|string> The validation rules for the profile update request.
     */
    public function rules(): array
    {
        return [
            'name' => ['string'],

            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
        ];
    }
}
