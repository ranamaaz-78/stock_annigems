<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\RejectsAttributionInput;
use Illuminate\Foundation\Http\FormRequest;

/**
 * LoginRequest
 *
 * Validates login credentials. Failure messages stay generic so attackers
 * cannot distinguish invalid email vs invalid password.
 */
class LoginRequest extends FormRequest
{
    use RejectsAttributionInput;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validation rules for the login form.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Email is required.',
            'email.email' => 'Enter a valid email address.',
            'password.required' => 'Password is required.',
        ];
    }
}
