<?php

namespace App\Http\Middleware;

use App\Models\DeliveryAgent;
use App\Support\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Authenticates a delivery rider by signed bearer token.
 *
 * Previously accepted an unsigned `base64(id|email|timestamp)` string, so a
 * rider account — and the delivery codes and customer addresses it can see —
 * could be impersonated by anyone who knew the rider's email.
 */
class DeliveryAgentAuth
{
    public function handle(Request $request, Closure $next)
    {
        $claims = ApiToken::parseOfType($request->bearerToken(), ApiToken::TYPE_AGENT);

        if (! $claims) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $agent = DeliveryAgent::where('id', $claims['id'])
            ->where('email', $claims['email'])
            ->first();

        if (! $agent) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        Auth::setUser($agent);
        $request->setUserResolver(fn () => $agent);

        return $next($request);
    }
}
