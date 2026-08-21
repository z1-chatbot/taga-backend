<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class PermissionMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  $permission
     * @return mixed
     */
    public function handle(Request $request, Closure $next, string $permission)
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required.'
            ], 401);
        }

        if (!$user->hasPermission($permission)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to perform this action.',
                'required_permission' => $permission
            ], 403);
        }

        return $next($request);
    }
}
