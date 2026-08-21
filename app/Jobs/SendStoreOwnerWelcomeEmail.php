<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendStoreOwnerWelcomeEmail implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public User $user,
        public string $plainPassword
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Mail::send('emails.store-owner-welcome', [
                'user' => $this->user,
                'password' => $this->plainPassword,
                'loginUrl' => config('app.vendor_url') . '/login' // Vendor dashboard URL
            ], function ($message) {
                $message->to($this->user->email, $this->user->name)
                        ->subject('Welcome to Taga - Your Store Owner Account');
            });

            Log::info("Store owner welcome email sent to: {$this->user->email}");
        } catch (\Exception $e) {
            Log::error("Failed to send store owner welcome email to {$this->user->email}: " . $e->getMessage());
            throw $e;
        }
    }
}
