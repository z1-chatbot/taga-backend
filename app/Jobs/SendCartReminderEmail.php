<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\EmailLog;
use App\Models\EmailAutomationSetting;
use App\Mail\CartReminderEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendCartReminderEmail implements ShouldQueue
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
    public $cartItems;
    public $cartTotal;
    public $reminderType;

    /**
     * Create a new job instance.
     */
    public function __construct(User $user, array $cartItems, float $cartTotal, string $reminderType)
    {
        $this->user = $user;
        $this->cartItems = $cartItems;
        $this->cartTotal = $cartTotal;
        $this->reminderType = $reminderType;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Check if automation is enabled
        $automationKey = 'abandoned_cart_' . $this->reminderType;
        if (!EmailAutomationSetting::isEnabled($automationKey)) {
            \Log::info("Cart reminder automation disabled: {$automationKey}");
            return;
        }

        try {
            // Log the email
            $emailLog = EmailLog::logEmail(
                $this->user->email,
                'cart_reminder_' . $this->reminderType,
                'Cart Reminder - ' . $this->reminderType,
                null,
                $this->user->id
            );

            // Send the email
            Mail::to($this->user->email)->send(
                new CartReminderEmail($this->user, $this->cartItems, $this->cartTotal, $this->reminderType)
            );

            // Mark as sent
            $emailLog->markAsSent();

            \Log::info("Cart reminder email sent to {$this->user->email} ({$this->reminderType})");

        } catch (\Exception $e) {
            \Log::error("Failed to send cart reminder email: " . $e->getMessage());
            
            if (isset($emailLog)) {
                $emailLog->markAsFailed($e->getMessage());
            }
        }
    }
}
