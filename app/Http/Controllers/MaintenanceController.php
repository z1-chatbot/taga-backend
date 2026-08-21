<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * One authenticated endpoint for running deployment tasks without a terminal.
 *
 * ---------------------------------------------------------------------------
 * What this replaces
 * ---------------------------------------------------------------------------
 * The project previously carried 28 standalone PHP scripts in public/ —
 * deploy.php, run-migrations.php, setup-seed.php, process-queue.php and so on.
 * Each was guarded by a plaintext secret written into the file itself
 * ('run_migrations_abc456', 'test_artisan_xyz', ...), so anyone holding a copy
 * of the source could run migrations or seeders against the live site. Their
 * own comments said to delete them after use; they shipped instead.
 *
 * ---------------------------------------------------------------------------
 * How this one is different
 * ---------------------------------------------------------------------------
 *  - The token lives in .env (MAINTENANCE_TOKEN), never in source or git.
 *  - Off by default. No token, or a token under 32 characters, and every
 *    request 404s — the endpoint does not exist as far as a caller can tell.
 *  - Compared with hash_equals, so a wrong token cannot be timed.
 *  - Fixed allowlist. There is no "run any command" option, because that is a
 *    remote shell wearing a hat.
 *  - Rate limited, and every attempt is logged with its IP.
 *  - 404 rather than 401 on a bad token, so probing tells an attacker nothing.
 *
 * ---------------------------------------------------------------------------
 * Usage
 * ---------------------------------------------------------------------------
 *   POST https://yoursite/__ops/migrate
 *   Header: X-Maintenance-Token: <the token from .env>
 *
 * For cron services that can only fetch a URL, the token may instead be passed
 * as ?token=... — but note that query strings land in server access logs, so
 * prefer the header wherever the caller supports it.
 *
 * Generate a token with:  php artisan ops:token
 */
class MaintenanceController extends Controller
{
    /**
     * Commands this endpoint is willing to run.
     *
     * Arguments are fixed here rather than taken from the request: accepting
     * caller-supplied options would let someone pass --path or --class and turn
     * an allowlist back into arbitrary execution.
     */
    private const ALLOWED = [
        // --- schema -------------------------------------------------------
        'install' => ['db:install', ['--force' => true], 'Load the SQL schema, then run pending migrations'],
        'migrate' => ['migrate', ['--force' => true], 'Run pending migrations'],
        'migrate-status' => ['migrate:status', [], 'Show which migrations have run'],

        // --- caches (the usual post-deploy sequence) ----------------------
        'clear' => ['optimize:clear', [], 'Clear config, route, view and application caches'],
        'cache' => ['optimize', [], 'Rebuild config and route caches'],
        'config-clear' => ['config:clear', [], 'Clear the config cache'],
        'route-clear' => ['route:clear', [], 'Clear the route cache'],
        'view-clear' => ['view:clear', [], 'Clear compiled views'],

        // --- filesystem ---------------------------------------------------
        'storage-link' => ['storage:link', [], 'Create the public storage symlink'],

        // --- recurring work (point a cron job at these) -------------------
        // Bounded so the request cannot hang: a web request is not a daemon.
        'queue' => ['queue:work', ['--stop-when-empty' => true, '--max-time' => 50, '--tries' => 3], 'Process queued jobs until the queue is empty'],
        'schedule' => ['schedule:run', [], 'Run any due scheduled tasks'],
    ];

    public function __invoke(Request $request, string $command): JsonResponse
    {
        $token = (string) config('app.maintenance_token');

        // Absent or weak token means the feature is off, not misconfigured.
        if (strlen($token) < 32) {
            abort(404);
        }

        $supplied = (string) ($request->header('X-Maintenance-Token') ?? $request->query('token', ''));

        if (! hash_equals($token, $supplied)) {
            Log::warning('Maintenance endpoint: rejected', [
                'command' => $command,
                'ip' => $request->ip(),
            ]);

            // 404, not 401: a probe should not learn that the endpoint is real.
            abort(404);
        }

        if (! array_key_exists($command, self::ALLOWED)) {
            Log::warning('Maintenance endpoint: unknown command', [
                'command' => $command,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unknown command.',
                'available' => $this->catalogue(),
            ], 422);
        }

        [$artisanCommand, $arguments] = self::ALLOWED[$command];

        Log::info('Maintenance endpoint: running', [
            'command' => $artisanCommand,
            'ip' => $request->ip(),
        ]);

        try {
            // Time limits are generous but finite; a shared host will cut the
            // request off eventually regardless.
            @set_time_limit(120);

            $exitCode = Artisan::call($artisanCommand, $arguments);
            $output = Artisan::output();

            Log::info('Maintenance endpoint: finished', [
                'command' => $artisanCommand,
                'exit_code' => $exitCode,
            ]);

            return response()->json([
                'success' => $exitCode === 0,
                'command' => $artisanCommand,
                'exit_code' => $exitCode,
                'output' => trim($output),
            ], $exitCode === 0 ? 200 : 500);
        } catch (\Throwable $e) {
            Log::error('Maintenance endpoint: failed', [
                'command' => $artisanCommand,
                'error' => $e->getMessage(),
            ]);

            // The message can carry connection strings, so it goes to the log
            // rather than the response body.
            return response()->json([
                'success' => false,
                'command' => $artisanCommand,
                'message' => 'The command failed. See storage/logs for detail.',
            ], 500);
        }
    }

    /** Human-readable list of what may be run. */
    private function catalogue(): array
    {
        return collect(self::ALLOWED)
            ->map(fn (array $entry) => $entry[2])
            ->all();
    }
}
