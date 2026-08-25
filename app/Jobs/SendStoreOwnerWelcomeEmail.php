<?php

namespace App\Jobs;

use App\Mail\StoreOwnerWelcomeEmail;
use App\Models\User;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Hands a new pharmacy its sign-in details.
 *
 * The message itself is App\Mail\StoreOwnerWelcomeEmail. Assembled here as a
 * raw `Mail::send()` until now, and so never delivered — and the sign-in link
 * it built came from `config('app.vendor_url')`, a key that has never existed.
 * Both are fixed in the mailable.
 */
class SendStoreOwnerWelcomeEmail
{
    use Queueable;

    /*
     * Deliberately NOT queued.
     *
     * This carries the password somebody needs in order to sign in at all, and
     * queued mail waits in the `jobs` table for a worker. Where nothing drains
     * that queue the row sits there with attempts=0 indefinitely: no error, no
     * failed job, nothing in the log — because the send is never attempted.
     * The account exists, the dashboard reports "Welcome email sent", and the
     * person simply cannot get in.
     *
     * Creating a colleague is a rare, deliberate admin action, so the second or
     * two of SMTP on the request is a fair price for the message being either
     * delivered or visibly broken. Higher-volume mail (cart reminders, order
     * follow-ups) stays queued, where a worker is the right answer.
     *
     * Run `php artisan taga:mail-doctor` to see whether that queue is moving.
     */
    public function __construct(
        public User $user,
        public string $plainPassword
    ) {}

    public function handle(): void
    {
        try {
            Mail::to($this->user->email, $this->user->name)
                ->send(new StoreOwnerWelcomeEmail($this->user, $this->plainPassword));

            Log::info("Store owner welcome email sent to: {$this->user->email}");
        } catch (\Throwable $e) {
            Log::error("Failed to send store owner welcome email to {$this->user->email}: ".$e->getMessage());

            throw $e;
        }
    }
}
