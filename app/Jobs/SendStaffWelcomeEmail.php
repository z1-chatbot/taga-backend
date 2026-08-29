<?php

namespace App\Jobs;

use App\Mail\StaffWelcomeEmail;
use App\Models\User;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Hands a new colleague their sign-in details.
 *
 * The message itself is App\Mail\StaffWelcomeEmail. It used to be assembled
 * here as a raw `Mail::send('emails.staff-welcome', $data, $closure)`, which is
 * why it never arrived: with no Mailable there is no SendsFromMailbox, so it
 * left through the default mailer with a From address that mailbox is not
 * permitted to send as, and Hostinger refused it. See the mailable for the
 * full account.
 *
 * The plain password is held in memory only, now that this runs inline rather
 * than through the queue — it is no longer written to the jobs table in clear.
 * It is still in the email itself, which is the exposure that remains; sending
 * a single-use reset link instead would remove it, but that is a different
 * feature rather than a fix.
 */
class SendStaffWelcomeEmail
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
            // roleRelation is named in the template. Loading it here rather
            // than relying on the caller means the row reads "Staff" rather
            // than falling back to the raw role string.
            // practitionerTypes as well: the template lists the specialties a
            // practitioner answers for, and a queued job deserialises the user
            // without its relations.
            $this->user->loadMissing('roleRelation', 'practitionerTypes');

            Mail::to($this->user->email, $this->user->name)
                ->send(new StaffWelcomeEmail($this->user, $this->plainPassword));

            Log::info("Staff welcome email sent to: {$this->user->email}");
        } catch (\Throwable $e) {
            Log::error("Failed to send staff welcome email to {$this->user->email}: ".$e->getMessage());

            // Rethrown so the failure is retried and then recorded in
            // failed_jobs. Nothing downstream depends on this having succeeded
            // — the account already exists and is already usable — so failing
            // loudly here is the only way it is visible at all.
            throw $e;
        }
    }
}
