<?php

namespace App\Http\Requests\Branch;

use App\Http\Requests\Concerns\RejectsAttributionInput;
use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * StoreCustomerRequest
 *
 * Validates new global customer registration. Phone uniqueness is system-wide.
 */
class StoreCustomerRequest extends FormRequest
{
    use RejectsAttributionInput;

    /**
     * Branch staff with customers.create may register customers.
     */
    public function authorize(): bool
    {
        return ($this->user()?->can('create', Customer::class) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{7,30}$/', Rule::unique('customers', 'phone')],
            'alternate_phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{7,30}$/'],
            'email' => ['nullable', 'email', 'max:150'],
            'cnic' => ['nullable', 'string', 'max:20', 'regex:/^[0-9]{5}-[0-9]{7}-[0-9]$/', Rule::unique('customers', 'cnic')],
            'address' => ['nullable', 'string', 'max:2000'],
            'city' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Please enter the customer name.',
            'phone.required' => 'Phone number is required.',
            'phone.unique' => 'This phone number is already registered to another customer.',
            'phone.regex' => 'Enter a valid phone number.',
            'cnic.regex' => 'CNIC must be in the format 12345-1234567-1.',
            'cnic.unique' => 'This CNIC is already registered to another customer.',
        ];
    }
}
