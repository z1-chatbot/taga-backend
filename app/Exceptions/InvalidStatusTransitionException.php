<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Thrown when a status change would move an order backwards through
 * fulfilment, or revive one that was cancelled or refunded.
 *
 * Fulfilment is forward-only: the customer's tracking timeline and the audit
 * trail are both built from this column, and rewriting it retroactively makes
 * both of them lie. A genuine correction goes through
 * Order::allowStatusRegression(), which logs the fact.
 */
class InvalidStatusTransitionException extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'code' => 'invalid_status_transition',
        ], 422);
    }
}
