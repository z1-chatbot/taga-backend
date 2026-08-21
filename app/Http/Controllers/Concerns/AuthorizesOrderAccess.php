<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ownership check for the order endpoints that guests can also reach.
 *
 * Checkout works without an account, so payment status, payment initialisation
 * and the order confirmation page cannot demand a token. They previously used
 *
 *     if (auth()->check() && $order->user_id !== auth()->id()) { ... }
 *
 * on routes that had no auth middleware at all — `auth()->check()` was always
 * false, so the guard never ran and every order was readable by walking ids.
 * One of them (the confirmation endpoint) had no check whatsoever and returned
 * the customer's name, phone, and delivery address.
 *
 * The rule here: an order belonging to an account is for that account only; a
 * guest order is for whoever holds the guest id it was placed under.
 */
trait AuthorizesOrderAccess
{
    protected function canAccessOrder(Request $request, ?Order $order): bool
    {
        if (! $order) {
            return false;
        }

        $user = $request->user();

        // Staff resolving a customer's payment problem.
        if ($user && in_array($user->role, ['admin', 'super_admin'], true)) {
            return true;
        }

        if ($order->user_id !== null) {
            return $user !== null && (int) $order->user_id === (int) $user->id;
        }

        // Guest order: the guest id it was placed under is the credential.
        $guestId = $request->header('X-Guest-ID');

        return $guestId !== null
            && $order->session_id !== null
            && hash_equals((string) $order->session_id, (string) $guestId);
    }

    /**
     * 404 rather than 403, so that probing ids cannot distinguish "someone
     * else's order" from "no such order".
     */
    protected function orderNotFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Order not found',
        ], 404);
    }
}
