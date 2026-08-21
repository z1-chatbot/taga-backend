<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Authenticates a customer/admin by signed bearer token.
 *
 * See App\Support\ApiToken for why the token is signed. This middleware
 * previously accepted `base64(id|email|timestamp)` with no signature check,
 * which meant any account could be impersonated by constructing that string.
 *
 * It also logged every token check at info level on every request; that noise
 * is gone along with it.
 */
class TokenAuthentication
{
    public function handle(Request $request, Closure $next)
    {
        $claims = ApiToken::parseOfType($request->bearerToken(), ApiToken::TYPE_USER);

        if (! $claims) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $user = User::where('id', $claims['id'])
            ->where('email', $claims['email'])
            ->first();

        // The email is part of the signed payload, so a mismatch here means the
        // account was changed or removed after the token was issued.
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (property_exists($user, 'is_active') || isset($user->is_active)) {
            if ($user->is_active === false || $user->is_active === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'This account has been deactivated.',
                ], 403);
            }
        }

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
