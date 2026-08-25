<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Mail\Mailable;

/**
 * Who the platform's own administrators are, and how to reach them.
 *
 * Four places had four answers to this question. Two resolved real admin
 * accounts with `User::where('role', 'admin')->pluck('email')`; one used
 * `config('mail.admin_email', env('ADMIN_EMAIL', 'admin@example.com'))`; one
 * had no notion of an admin at all and simply sent nothing.
 *
 * The config one was worse than inconsistent, it was silently broken in
 * production. `config/mail.php` declares no `admin_email` key, so that call
 * always fell through to its default — and the default calls `env()` outside a
 * config file. Once `php artisan config:cache` has run, Laravel skips loading
 * the .env file entirely, so `env('ADMIN_EMAIL')` returns null and the whole
 * expression resolves to the literal `admin@example.com`. Every "new order"
 * notification the platform has sent from a cached-config deploy went to a
 * domain reserved by the IETF for documentation.
 *
 * So: real admin accounts first, because those are addresses that certainly
 * belong to someone who can act. The configured address is a fallback for a
 * platform that has not created an admin user yet, not a default. And a
 * missing address is logged as a warning rather than passed to the mailer,
 * which would throw halfway through whatever transaction called it.
 */
class PlatformAdmins
{
    /**
     * Every address that should hear about platform-level events.
     *
     * @return array<int, string>
     */
    public static function emails(): array
    {
        $emails = User::where('role', 'admin')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->pluck('email')
            ->unique()
            ->values()
            ->all();

        if (! empty($emails)) {
            return $emails;
        }

        // No admin accounts exist yet. `config('mail.admin_email')` is a real
        // key now rather than one that never resolved, so this works under a
        // cached config.
        $fallback = config('mail.admin_email');

        return $fallback ? [$fallback] : [];
    }

    /**
     * Send one mailable to every administrator, individually.
     *
     * Individually rather than as one message with many recipients, which is
     * what two of the old call sites did by handing an array to `Mail::to()`.
     * That puts every administrator's address in the To header of a message
     * each of them can see, and — more practically — means one bad address
     * takes the whole send down with it and nobody hears about the event.
     *
     * Never throws. Every caller is inside something that has already happened
     * and must not be undone: an order is paid, a licence is uploaded, a payout
     * is deducted from a balance. Losing that because an SMTP connection timed
     * out would be a far worse outcome than a missing notification, so failures
     * are logged with enough context to find the event afterwards.
     *
     * @param  callable(): Mailable  $factory  Builds a fresh mailable per
     *         recipient. A Mailable accumulates recipients on `to()`, so
     *         reusing one instance across a loop sends the second message to
     *         both people, the third to all three, and so on.
     * @return int  how many were actually sent
     */
    public static function notify(callable $factory, string $event, array $context = []): int
    {
        $recipients = self::emails();

        if (empty($recipients)) {
            Log::warning("No platform administrator to notify about {$event}", $context + [
                'hint' => 'Create a user with role=admin, or set ADMIN_EMAIL.',
            ]);

            return 0;
        }

        $sent = 0;

        foreach ($recipients as $recipient) {
            try {
                Mail::to($recipient)->send($factory());
                $sent++;
            } catch (\Throwable $e) {
                Log::error("Failed to notify an administrator about {$event}: ".$e->getMessage(), $context + [
                    'recipient' => $recipient,
                ]);
            }
        }

        return $sent;
    }
}
