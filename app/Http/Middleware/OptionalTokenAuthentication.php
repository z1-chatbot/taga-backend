<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Resolves the caller when a valid token is present, and does nothing when it
 * is not.
 *
 * Several routes deliberately serve both guests and signed-in shoppers —
 * uploading a prescription before an account exists, placing a guest order,
 * viewing an order confirmation. Those routes were left entirely public, and
 * the controllers behind them then asked `$request->user()` to decide what the
 * caller may see. On a public route that is always null, so:
 *
 *   - a signed-in shopper could never list or open their own prescriptions;
 *   - prescriptions uploaded while signed in were saved with a null user_id,
 *     leaving them attached to a cart session rather than an account;
 *   - OrderController hand-decoded the bearer token itself to work around it.
 *
 * This middleware fills that gap in one place. It never rejects a request —
 * authorisation stays the controller's decision — it only makes the caller's
 * identity available when they have proven it.
 */
class OptionalTokenAuthentication
{
    public function handle(Request $request, Closure $next)
    {
        $claims = ApiToken::parseOfType($request->bearerToken(), ApiToken::TYPE_USER);

        if ($claims) {
            $user = User::where('id', $claims['id'])
                ->where('email', $claims['email'])
                ->first();

            if ($user) {
                Auth::setUser($user);
                $request->setUserResolver(fn () => $user);
            }
        }

        return $next($request);
    }
}
