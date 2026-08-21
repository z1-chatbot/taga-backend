<?php

namespace App\Mail;

use App\Support\AppUrl;
use App\Mail\Concerns\SendsFromMailbox;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ResetPasswordEmail extends Mailable
{
    use Queueable, SerializesModels, SendsFromMailbox;

    /**
     * Sent from the noreply mailbox — identity: account creation and access, no reply expected.
     *
     * Each mailbox is its own SMTP account and may only send as its own
     * address, so this picks the credentials as well as the From line.
     */
    protected string $mailbox = 'noreply';

    public User $user;

    public string $resetUrl;

    public int $expiresInMinutes;

    public function __construct(User $user, string $token, int $expiresInMinutes)
    {
        $this->user = $user;
        $this->expiresInMinutes = $expiresInMinutes;

        $frontendUrl = AppUrl::storefront();

        $this->resetUrl = "{$frontendUrl}/reset-password?token={$token}&email=".urlencode($user->email);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reset your Taga password',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.reset-password',
            text: 'emails.reset-password-text',
        );
    }

    /**
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
