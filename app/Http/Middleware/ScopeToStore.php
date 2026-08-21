<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ScopeToStore
{
    /**
     * Handle an incoming request.
     * 
     * This middleware ensures that store staff can only access data from their own store.
     * It adds the store_id constraint to the authenticated user for use in controllers.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('admin');

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        // If user has a store_id (is store staff), ensure they can only access their store's data
        if ($user->store_id) {
            // Add store_id to request for easy access in controllers
            $request->merge(['scoped_store_id' => $user->store_id]);
            
            // Mark request as store-scoped
            $request->attributes->set('is_store_scoped', true);
        }

        // Store owners access their own store
        if ($user->role === 'store_owner' && $user->store) {
            $request->merge(['scoped_store_id' => $user->store->id]);
            $request->attributes->set('is_store_scoped', true);
        }

        return $next($request);
    }
}
