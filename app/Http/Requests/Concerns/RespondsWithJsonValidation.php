<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * RespondsWithJsonValidation
 *
 * When an AJAX client sends Accept: application/json, validation failures
 * use the shared { success, message, data, errors } envelope instead of
 * Laravel's default MessageBag JSON.
 */
trait RespondsWithJsonValidation
{
    /**
     * Convert a failed validation attempt into the project JSON envelope.
     */
    protected function failedValidation(Validator $validator): void
    {
        if ($this->expectsJson()) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => __('Please correct the highlighted fields.'),
                'data' => null,
                'errors' => $validator->errors()->toArray(),
            ], 422));
        }

        parent::failedValidation($validator);
    }
}
