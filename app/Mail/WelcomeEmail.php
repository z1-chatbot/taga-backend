<?php

namespace App\Mail;

use App\Mail\Concerns\SendsFromMailbox;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WelcomeEmail extends Mailable
{
    use Queueable, SerializesModels, SendsFromMailbox;

    /**
     * Sent from the noreply mailbox - identity: account creation and access, no reply expected.
     *
     * Each mailbox is its own SMTP account and may only send as its own
     * address, so this picks the credentials as well as the From line.
     */
    protected string $mailbox = 'noreply';

    public $user;

    /*
     * No coupon, deliberately.
     *
     * There is no sign-up bonus on this platform and there never has been. This
     * class used to end its constructor with
     *
     *     $this->couponCode = $couponCode ?? 'WELCOME10';
     *
     * which meant every welcome email advertised a discount code that had never
     * been created — `Coupon::usable('WELCOME10')` is false and the coupons
     * table has no such row, so the basket refused it at checkout.
     *
     * The job that dispatches this had already been fixed to resolve an
     * unusable code to null before handing it over. That fix did nothing: this
     * line put the phantom code straight back one statement later, and the
     * comments in the job and the template both described an intent this class
     * silently defeated. The parameter is gone rather than defaulted to null,
     * so it cannot be reintroduced by passing something in.
     */
    public function __construct(User $user)
    {
        $this->user = $user;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to Taga! 🎉',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.welcome',
            text: 'emails.welcome-text',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
