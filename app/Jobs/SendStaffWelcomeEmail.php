<?php

namespace App\Jobs;

use App\Mail\StaffWelcomeEmail;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
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
 * The plain password is a constructor argument, which means it is written to
 * the jobs table in clear until the job runs. That is not new and not ideal,
 * but it is the same exposure the email itself has, it lives for about a minute
 * (the scheduler drains the queue every minute), and the alternative — storing
 * a reset token instead — is a different feature rather than a fix. Worth
 * revisiting; deliberately not smuggled into this change.
 */
class SendStaffWelcomeEmail implements ShouldQueue
{
    use Queueable;

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
            $this->user->loadMissing('roleRelation');

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
