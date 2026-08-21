<?php

namespace App\Mail;

use App\Mail\Concerns\SendsFromMailbox;
use App\Models\User;
use App\Models\Coupon;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class FirstOrderCouponEmail extends Mailable
{
    use Queueable, SerializesModels, SendsFromMailbox;

    /**
     * Sent from the shop mailbox — commerce: orders, cart, delivery, payouts, partner updates.
     *
     * Each mailbox is its own SMTP account and may only send as its own
     * address, so this picks the credentials as well as the From line.
     */
    protected string $mailbox = 'shop';

    public $user;
    public $coupon;

    /**
     * Create a new message instance.
     */
    public function __construct(User $user, Coupon $coupon)
    {
        $this->user = $user;
        $this->coupon = $coupon;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '🎁 Thank You! Here\'s 10% Off Your Next Order',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.first-order-coupon',
            text: 'emails.first-order-coupon-text',
        );
    }

    /**
     * Get the attachments for the message.
     */
    public function attachments(): array
    {
        return [];
    }
}
