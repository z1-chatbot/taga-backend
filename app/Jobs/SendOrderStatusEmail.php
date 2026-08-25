<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\EmailLog;
use App\Models\EmailAutomationSetting;
use App\Mail\OrderStatusEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendOrderStatusEmail implements ShouldQueue
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
    public $statusType;

    /**
     * Create a new job instance.
     */
    public function __construct(Order $order, string $statusType)
    {
        $this->order = $order;
        $this->statusType = $statusType;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Check if automation is enabled
        $automationKey = 'order_status_' . $this->statusType;
        if (!EmailAutomationSetting::isEnabled($automationKey)) {
            \Log::info("Order status email automation disabled: {$automationKey}");
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
            \Log::warning("Order {$this->order->order_number} has no email address, skipping status email");
            return;
        }

        try {
            // Log the email
            $emailLog = EmailLog::logEmail(
                $recipientEmail,
                'order_status_' . $this->statusType,
                'Order ' . ucfirst($this->statusType),
                null,
                $userId
            );

            // Send the email
            Mail::to($recipientEmail)->send(
                new OrderStatusEmail($this->order, $this->statusType)
            );

            // Mark as sent
            $emailLog->markAsSent();

            \Log::info("Order status email sent for order {$this->order->order_number} ({$this->statusType})", [
                'recipient' => $recipientEmail,
                'is_guest' => !$this->order->user
            ]);

        } catch (\Exception $e) {
            \Log::error("Failed to send order status email: " . $e->getMessage(), [
                'order_number' => $this->order->order_number,
                'recipient' => $recipientEmail
            ]);
            
            if (isset($emailLog)) {
                $emailLog->markAsFailed($e->getMessage());
            }
        }
    }
}
