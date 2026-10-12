<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;

/**
 * RespondsWithJson
 *
 * Shared AJAX envelope used by stone (and later POS) JSON endpoints:
 * { success, message, data, errors }.
 */
trait RespondsWithJson
{
    /**
     * Successful JSON payload.
     */
    protected function jsonSuccess(string $message, mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'errors' => (object) [],
        ], $status);
    }

    /**
     * Failed JSON payload. Never include stack traces or SQL.
     *
     * @param  array<string, mixed>  $errors
     */
    protected function jsonFailure(string $message, array $errors = [], int $status = 422): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
            'errors' => $errors,
        ], $status);
    }
}
