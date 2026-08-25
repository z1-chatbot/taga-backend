<?php

namespace App\Mail;

use App\Mail\Concerns\SendsFromMailbox;
use App\Models\User;
use App\Support\AppUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A new pharmacy account, and the password to sign in with.
 *
 * Sent as a raw `Mail::send()` with a view name until now, and so never
 * delivered — see StaffWelcomeEmail for the full account of why that form
 * cannot reach a Hostinger mailbox.
 *
 * It carried a second fault of its own: the sign-in link was built from
 * `config('app.vendor_url')`, a key config/app.php has never declared. It
 * resolved to null, leaving the button pointing at the bare string "/login" —
 * so on the days the message did leave, it invited a pharmacy to sign in at a
 * link that goes nowhere. There is no separate vendor dashboard; a store owner
 * signs in to the admin dashboard, which is what AppUrl::admin() returns.
 *
 * noreply, per config/mail.php: identity — sign-up, verification, credentials.
 */
class StoreOwnerWelcomeEmail extends Mailable
{
    use Queueable, SerializesModels, SendsFromMailbox;

    protected string $mailbox = 'noreply';

    public string $loginUrl;

    public function __construct(
        public User $user,
        public string $password,
    ) {
        $this->loginUrl = AppUrl::admin('/login');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Taga pharmacy dashboard is ready');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.store-owner-welcome',
            text: 'emails.store-owner-welcome-text',
        );
    }
}
