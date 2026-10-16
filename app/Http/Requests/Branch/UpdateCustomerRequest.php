<?php

namespace App\Http\Requests\Branch;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * UpdateCustomerRequest
 *
 * Validates customer edits with system-wide phone and CNIC uniqueness.
 */
class UpdateCustomerRequest extends FormRequest
{
    use \App\Http\Requests\Concerns\RejectsAttributionInput;

    /**
     * Branch staff with customers.edit may update any customer.
     */
    public function authorize(): bool
    {
        $customer = $this->route('customer');

        return $customer instanceof Customer
            && ($this->user()?->can('update', $customer) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Customer $customer */
        $customer = $this->route('customer');

        return [
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{7,30}$/', Rule::unique('customers', 'phone')->ignore($customer->id)],
            'alternate_phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{7,30}$/'],
            'email' => ['nullable', 'email', 'max:150'],
            'cnic' => ['nullable', 'string', 'max:20', 'regex:/^[0-9]{5}-[0-9]{7}-[0-9]$/', Rule::unique('customers', 'cnic')->ignore($customer->id)],
            'nie' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-\s.\/]{5,20}$/'],
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
        return (new StoreCustomerRequest)->messages();
    }
}
