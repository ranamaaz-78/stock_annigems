<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\RejectsAttributionInput;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ForgotPasswordRequest
 *
 * Validates the email for sending a password reset link.
 */
class ForgotPasswordRequest extends FormRequest
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
     * Validation rules.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
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
        ];
    }
}
