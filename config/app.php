<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
     * Where the storefront lives. Drives the Paystack callback, password-reset
     * links and email-verification links — so a wrong value here silently sends
     * paying customers to another site. The default was the previous business's
     * domain; it is now a local fallback, and production sets FRONTEND_URL.
     */
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5174'),
    
    /*
     * Token for the /__ops maintenance endpoint, used to run deploy tasks on
     * hosting without a terminal. Leave unset to disable the endpoint entirely;
     * anything shorter than 32 characters is treated as unset. Generate one
     * with `php artisan ops:token`. Never commit a real value.
     */
    'maintenance_token' => env('MAINTENANCE_TOKEN'),

    /*
     * Prefix on customer-facing order numbers and payment references. Was
     * hardcoded to 'HL' from the business this codebase started as, so every
     * Taga order number read "HL-...".
     */
    'order_prefix' => env('ORDER_PREFIX', 'TG'),

    /*
     * The other three apps. These are emailed to admins, logistics companies
     * and riders as their sign-in link, so like frontend_url they pointed
     * invitees at the previous business's domain. Local defaults; production
     * sets the env vars.
     */
    'admin_url' => env('ADMIN_URL', 'http://localhost:5173'),

    'logistics_portal_url' => env('LOGISTICS_PORTAL_URL', 'http://localhost:5176'),

    'agent_portal_url' => env('AGENT_PORTAL_URL', 'http://localhost:5175'),

    /*
     * The same four apps, as a person outside this machine would reach them.
     *
     * Two different questions were being answered by one set of values, and
     * they only agree in production:
     *
     *   - which origins may call this API (CorsMiddleware) and where a local
     *     checkout returns from Paystack — these must stay localhost in
     *     development or nothing works;
     *   - what address to put in an email — which must be a real domain, since
     *     a rider reading an invitation on their phone cannot resolve
     *     http://localhost:5175.
     *
     * Overwriting the first set with production domains is what once dropped
     * localhost out of the CORS allowlist and broke admin sign-in, so they are
     * now separate. The rule: a link that leaves the browser session and
     * travels in an email uses these; anything the local browser follows uses
     * the values above.
     *
     * Each falls back to its development counterpart, so an unset variable
     * behaves exactly as before rather than emailing a blank link.
     */
    'public_urls' => [
        'frontend' => env('PUBLIC_FRONTEND_URL', env('FRONTEND_URL', 'http://localhost:5174')),
        'admin' => env('PUBLIC_ADMIN_URL', env('ADMIN_URL', 'http://localhost:5173')),
        'agent_portal' => env('PUBLIC_AGENT_PORTAL_URL', env('AGENT_PORTAL_URL', 'http://localhost:5175')),
        'logistics_portal' => env('PUBLIC_LOGISTICS_PORTAL_URL', env('LOGISTICS_PORTAL_URL', 'http://localhost:5176')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => env('APP_TIMEZONE', 'Africa/Lagos'),

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
