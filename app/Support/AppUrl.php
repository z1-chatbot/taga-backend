<?php

namespace App\Support;

/**
 * Addresses for the four apps, as somebody outside this machine would reach
 * them.
 *
 * Use these — and only these — for links that travel in an email. The values
 * they read are kept apart from the ones that drive CORS and the Paystack
 * callback, because those must stay pointed at localhost in development while
 * an emailed link must not: a rider opening an invitation on their phone
 * cannot resolve http://localhost:5175, which is exactly what the first agent
 * invitation contained.
 *
 * See config/app.php for the two sets and why they are separate.
 */
final class AppUrl
{
    /** The storefront customers shop on. */
    public static function storefront(string $path = ''): string
    {
        return self::build('frontend', $path);
    }

    /** The admin dashboard. */
    public static function admin(string $path = ''): string
    {
        return self::build('admin', $path);
    }

    /** The riders' portal. */
    public static function agentPortal(string $path = ''): string
    {
        return self::build('agent_portal', $path);
    }

    /** The logistics companies' portal. */
    public static function logisticsPortal(string $path = ''): string
    {
        return self::build('logistics_portal', $path);
    }

    /**
     * Joins base and path with exactly one slash between them, so callers can
     * pass '/login', 'login' or '' without producing '//login' or a URL that
     * runs the host straight into the path.
     */
    private static function build(string $app, string $path): string
    {
        $base = rtrim((string) config("app.public_urls.{$app}"), '/');

        if ($path === '') {
            return $base;
        }

        return $base.'/'.ltrim($path, '/');
    }
}
