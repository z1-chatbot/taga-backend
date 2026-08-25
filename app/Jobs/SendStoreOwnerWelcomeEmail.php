<?php

namespace App\Jobs;

use App\Mail\StoreOwnerWelcomeEmail;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
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
class SendStoreOwnerWelcomeEmail implements ShouldQueue
{
    use Queueable;

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
