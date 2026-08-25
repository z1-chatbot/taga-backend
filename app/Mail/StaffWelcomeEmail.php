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
 * A new staff account, and the password to sign in with.
 *
 * This was not a Mailable at all until now. It was sent as
 * `Mail::send('emails.staff-welcome', $data, fn ($message) => ...)` — the raw
 * facade with a view name — which is why it never arrived.
 *
 * That form has no Mailable, so it never touches SendsFromMailbox, so it leaves
 * through the *default* mailer. The default `smtp` mailer authenticates as
 * MAIL_USERNAME but declares no `from` of its own, so it falls back to the
 * global MAIL_FROM_ADDRESS. Each Hostinger mailbox is a separate SMTP account
 * that may only send as its own address, and the mismatch is refused outright:
 *
 *     553 5.7.1 <...>: Sender address rejected: not owned by user ...
 *
 * That is the exact failure the three named mailboxes were introduced to fix.
 * Every other message in the application was converted to a Mailable at the
 * time; these two welcome emails were missed because they did not look like
 * mailables and so did not turn up in a search for them.
 *
 * The send sat inside a queued job that logged and rethrew, so the failure went
 * to `failed_jobs` while the API had already answered "Welcome email sent." A
 * new colleague was left with an account they could not get into and no
 * password, and nothing on screen said so.
 *
 * noreply, per config/mail.php: identity — sign-up, verification, credentials.
 */
class StaffWelcomeEmail extends Mailable
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
        return new Envelope(subject: 'Your Taga staff account is ready');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.staff-welcome',
            text: 'emails.staff-welcome-text',
        );
    }
}
