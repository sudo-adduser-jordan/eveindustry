<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Http\Requests\Api\V1\ApiRequest;
use Illuminate\Validation\Rules\Password;

class AccountUpdateRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$this->user()->id],
            /** Required with `password`. Ignored when no new password is sent. */
            'current_password' => ['required_with:password', 'current_password'],
            /** A new password, at least 8 characters. Leave out to keep the current one. */
            'password' => ['nullable', 'confirmed', Password::min(8)],
            'password_confirmation' => ['nullable', 'string'],
        ];
    }
}
