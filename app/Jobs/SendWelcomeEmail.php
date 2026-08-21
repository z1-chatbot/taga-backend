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

    public $user;
    public $couponCode;

    /**
     * Create a new job instance.
     */
    /**
     * @param  string|null  $couponCode  A coupon that genuinely exists and is
     *                                   usable. Null means the email offers none.
     */
    public function __construct(User $user, ?string $couponCode = null)
    {
        $this->user = $user;

        // Only ever promise a code that will actually work at checkout. This
        // used to fall back to a hardcoded 'WELCOME10' when no coupon was
        // passed — and no such coupon has ever existed, so every welcome email
        // offered a discount that the basket then refused.
        $this->couponCode = $couponCode && Coupon::usable($couponCode)
            ? $couponCode
            : null;
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
                new WelcomeEmail($this->user, $this->couponCode)
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
