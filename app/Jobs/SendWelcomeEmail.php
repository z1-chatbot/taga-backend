<?php

namespace App\Jobs;

use App\Models\Coupon;
use App\Models\User;
use App\Models\EmailLog;
use App\Models\EmailAutomationSetting;
use App\Mail\WelcomeEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendWelcomeEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * A deleted user means there is nothing to send, not a failure.
     *
     * SerializesModels stores only an id and reloads the record when the job
     * runs. Without this, a job whose user has since been deleted throws
     * ModelNotFoundException, and Laravel routes that to failed_jobs — where it
     * sits looking like a mail problem to investigate, when in fact there is
     * simply nobody left to email. Four such rows are what a deleted staff
     * account left behind.
     *
     * Safe here specifically because none of the dispatch sites for this job
     * sit inside an open transaction. `after_commit` is false on every queue
     * connection, so a job dispatched mid-transaction could run before the
     * commit and find no row — and with this set, that transient miss would be
     * silently discarded rather than retried. That is not the situation for
     * this job; check it again before copying this line onto another one.
     */
    public $deleteWhenMissingModels = true;

    public $user;

    /**
     * Create a new job instance.
     */
    /*
     * No coupon parameter.
     *
     * This used to accept one and guard it with Coupon::usable(), which read as
     * a fix and was not: App\Mail\WelcomeEmail replaced the resulting null
     * with a hardcoded 'WELCOME10' as soon as it received it. Signing up earns
     * no discount on this platform, so there is nothing here to validate — the
     * safest version of a coupon that does not exist is no parameter at all.
     */
    public function __construct(User $user)
    {
        $this->user = $user;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Check if automation is enabled
        if (!EmailAutomationSetting::isEnabled(EmailAutomationSetting::WELCOME_EMAIL)) {
            \Log::info("Welcome email automation disabled");
            return;
        }

        try {
            // Log the email
            $emailLog = EmailLog::logEmail(
                $this->user->email,
                'welcome',
                'Welcome to Taga',
                null,
                $this->user->id
            );

            // Send the email
            Mail::to($this->user->email)->send(
                new WelcomeEmail($this->user)
            );

            // Mark as sent
            $emailLog->markAsSent();

            \Log::info("Welcome email sent to {$this->user->email}");

        } catch (\Exception $e) {
            \Log::error("Failed to send welcome email: " . $e->getMessage());
            
            if (isset($emailLog)) {
                $emailLog->markAsFailed($e->getMessage());
            }
        }
    }
}
