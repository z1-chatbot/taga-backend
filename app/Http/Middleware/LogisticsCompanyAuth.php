<?php

namespace App\Http\Middleware;

use App\Models\LogisticsCompany;
use App\Support\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Authenticates a logistics company by signed bearer token.
 *
 * Previously accepted an unsigned `base64(id|admin_email|timestamp)` string, so
 * a company account — which can manage riders, orders and payouts — could be
 * impersonated by anyone who knew its admin email.
 */
class LogisticsCompanyAuth
{
    public function handle(Request $request, Closure $next)
    {
        $claims = ApiToken::parseOfType($request->bearerToken(), ApiToken::TYPE_COMPANY);

        if (! $claims) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $company = LogisticsCompany::where('id', $claims['id'])
            ->where('admin_email', $claims['email'])
            ->first();

        if (! $company) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        Auth::setUser($company);
        $request->setUserResolver(fn () => $company);

        return $next($request);
    }
}
