<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Thrown when something tries to move an order toward dispatch while one of its
 * prescription-only lines is still unapproved.
 *
 * This is the backstop behind Order's updating hook rather than a user-facing
 * validation error: the controllers check first and answer politely, so seeing
 * this means a code path forgot to.
 */
class PrescriptionNotClearedException extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'code' => 'prescription_not_cleared',
        ], 422);
    }
}
