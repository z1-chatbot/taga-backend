<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\EmailLog;
use App\Models\EmailAutomationSetting;
use App\Mail\OrderFollowUpEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendOrderFollowUpEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * A deleted order means there is nothing to send, not a failure.
     *
     * SerializesModels stores only an id and reloads the record when the job
     * runs. Without this, a job whose order has since been deleted throws
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

    public $order;

    /**
     * Create a new job instance.
     */
    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Check if automation is enabled
        if (!EmailAutomationSetting::isEnabled(EmailAutomationSetting::ORDER_FOLLOW_UP)) {
            \Log::info("Order follow-up email automation disabled");
            return;
        }

        // Get email recipient (user or guest)
        $recipientEmail = null;
        $userId = null;
        
        if ($this->order->user) {
            $recipientEmail = $this->order->user->email;
            $userId = $this->order->user_id;
        } elseif (isset($this->order->shipping_address['email'])) {
            $recipientEmail = $this->order->shipping_address['email'];
            $userId = null; // Guest order
        }

        if (!$recipientEmail) {
            \Log::warning("Order {$this->order->order_number} has no email address, skipping follow-up email");
            return;
        }

        try {
            // Log the email
            $emailLog = EmailLog::logEmail(
                $recipientEmail,
                'order_followup',
                'Share Your Review',
                null,
                $userId
            );

            // Send the email
            Mail::to($recipientEmail)->send(
                new OrderFollowUpEmail($this->order)
            );

            // Mark as sent
            $emailLog->markAsSent();

            \Log::info("Order follow-up email sent for order {$this->order->order_number}", [
                'recipient' => $recipientEmail,
                'is_guest' => !$this->order->user
            ]);

        } catch (\Exception $e) {
            \Log::error("Failed to send order follow-up email: " . $e->getMessage(), [
                'order_number' => $this->order->order_number,
                'recipient' => $recipientEmail
            ]);
            
            if (isset($emailLog)) {
                $emailLog->markAsFailed($e->getMessage());
            }
        }
    }
}
