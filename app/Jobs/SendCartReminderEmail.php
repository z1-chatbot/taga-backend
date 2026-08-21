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
