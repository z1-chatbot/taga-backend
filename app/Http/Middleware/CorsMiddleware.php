<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds CORS headers to every response.
 *
 * Two things this used to get wrong:
 *
 * 1. It called `$response->header(...)`, which only exists on
 *    Illuminate\Http\Response. Anything streamed — file downloads, CSV
 *    exports, prescription images — is a Symfony StreamedResponse and has no
 *    such method, so those endpoints died with "Call to undefined method
 *    StreamedResponse::header()". Setting headers through `$response->headers`
 *    works on every response type.
 *
 * 2. It reflected the caller's Origin back with
 *    Access-Control-Allow-Credentials: true, which lets any site on the
 *    internet make credentialed cross-origin calls. Origins are now checked
 *    against the four configured app URLs.
 */
class CorsMiddleware
{
    private const HEADERS = 'Content-Type, Authorization, X-Requested-With, X-XSRF-TOKEN, Accept, X-Guest-ID, X-Maintenance-Token';

    private const METHODS = 'GET, POST, PUT, PATCH, DELETE, OPTIONS';

    public function handle(Request $request, Closure $next): Response
    {
        $origin = $this->resolveOrigin($request);

        if ($request->isMethod('OPTIONS')) {
            $response = response('', 204);
            $this->apply($response, $origin);
            $response->headers->set('Access-Control-Max-Age', '86400');

            return $response;
        }

        $response = $next($request);

        $this->apply($response, $origin);

        return $response;
    }

    private function apply(Response $response, ?string $origin): void
    {
        // No allowed origin means no CORS headers at all. Omitting them is what
        // makes the browser refuse the response; sending a wrong value would be
        // read as permission.
        if ($origin === null) {
            return;
        }

        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Methods', self::METHODS);
        $response->headers->set('Access-Control-Allow-Headers', self::HEADERS);
        $response->headers->set('Access-Control-Allow-Credentials', 'true');

        // The allowed origin varies by request, so any shared cache must key on
        // it rather than serving one site's headers to another.
        $response->headers->set('Vary', 'Origin');
    }

    /**
     * The request's Origin if it is one of ours, otherwise null.
     *
     * Same-origin and server-to-server calls (curl, Paystack webhooks, cron)
     * send no Origin header and need no CORS headers, so they pass through
     * untouched.
     */
    private function resolveOrigin(Request $request): ?string
    {
        $origin = $request->header('Origin');

        if (! $origin) {
            return null;
        }

        return in_array($origin, $this->allowedOrigins(), true) ? $origin : null;
    }

    /**
     * @return list<string>
     */
    private function allowedOrigins(): array
    {
        $configured = array_filter([
            config('app.frontend_url'),
            config('app.admin_url'),
            config('app.agent_portal_url'),
            config('app.logistics_portal_url'),
        ]);

        // CORS_ALLOWED_ORIGINS is the escape hatch for extra hosts (a staging
        // domain, a preview deploy) without editing code.
        $extra = array_filter(array_map(
            'trim',
            explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
        ));

        return array_values(array_unique(array_map(
            fn ($url) => rtrim((string) $url, '/'),
            [...$configured, ...$extra]
        )));
    }
}
