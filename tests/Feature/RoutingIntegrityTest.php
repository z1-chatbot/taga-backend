<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Whole-route-table checks. These catch the failure modes that only show up
 * when someone actually calls the endpoint — a controller method that was
 * never written, a literal path shadowed by a wildcard, an admin route with no
 * admin guard.
 */
class RoutingIntegrityTest extends TestCase
{
    public function test_every_route_points_at_a_method_that_exists(): void
    {
        $broken = [];

        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();

            if ($action === 'Closure' || ! str_contains($action, '@')) {
                continue;
            }

            [$class, $method] = explode('@', $action);

            if (! class_exists($class)) {
                $broken[] = "{$route->uri()} -> missing class {$class}";
                continue;
            }

            if (! method_exists($class, $method)) {
                $broken[] = "{$route->uri()} -> missing method {$class}::{$method}";
            }
        }

        $this->assertSame([], $broken, "Routes registered against code that does not exist:\n".implode("\n", $broken));
    }

    public function test_admin_routes_all_carry_an_authorisation_guard(): void
    {
        $unguarded = [];

        // The one deliberate exception: storefront config (currency symbol,
        // COD toggle) that the shop needs before anyone signs in.
        $publicByDesign = ['api/v1/admin/settings/public'];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/admin/')) {
                continue;
            }

            if (in_array($route->uri(), $publicByDesign, true)) {
                continue;
            }

            $middleware = $route->gatherMiddleware();

            $guarded = collect($middleware)->contains(
                fn ($m) => is_string($m)
                    && (str_contains($m, 'AdminMiddleware') || str_contains($m, 'PermissionMiddleware') || str_starts_with($m, 'permission:') || $m === 'admin')
            );

            if (! $guarded) {
                $unguarded[] = implode('|', $route->methods()).' '.$route->uri();
            }
        }

        $this->assertSame([], $unguarded, "Admin routes with no admin or permission guard:\n".implode("\n", $unguarded));
    }

    public function test_literal_paths_are_not_shadowed_by_wildcard_routes(): void
    {
        $shadowed = [];
        $seen = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $uri = $route->uri();

                // Only literal paths can be shadowed.
                if (! str_contains($uri, '{')) {
                    foreach ($seen[$method] ?? [] as $earlier) {
                        if ($earlier['uri'] === $uri || ! str_contains($earlier['uri'], '{')) {
                            continue;
                        }

                        if ($this->wildcardSwallows($earlier, $uri)) {
                            $shadowed[] = "{$method} {$uri} is unreachable — {$earlier['uri']} is registered first";
                        }
                    }
                }

                $seen[$method][] = ['uri' => $uri, 'wheres' => $route->wheres];
            }
        }

        $this->assertSame([], $shadowed, implode("\n", $shadowed));
    }

    /**
     * Whether an earlier wildcard route would swallow the given literal path,
     * honouring any ->where() constraints on its parameters. Several routes
     * here use a negative lookahead on {slug} precisely to avoid this, and
     * ignoring that would report collisions that do not happen.
     */
    private function wildcardSwallows(array $earlier, string $uri): bool
    {
        $pattern = preg_quote($earlier['uri'], '#');

        $pattern = preg_replace_callback('#\\\{([^}]+?)\\\??\\\}#', function ($m) use ($earlier) {
            $name = trim($m[1], '?');
            $constraint = $earlier['wheres'][$name] ?? '[^/]+';

            return '(?:'.$constraint.')';
        }, $pattern);

        return (bool) preg_match('#^'.$pattern.'$#', $uri);
    }
}
