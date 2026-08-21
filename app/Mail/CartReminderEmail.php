<?php

namespace App\Mail;

use App\Mail\Concerns\SendsFromMailbox;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CartReminderEmail extends Mailable
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
    public $cartItems;
    public $cartTotal;
    public $reminderType; // '1h', '24h', '3d'

    /**
     * Create a new message instance.
     */
    public function __construct(User $user, array $cartItems, float $cartTotal, string $reminderType = '1h')
    {
        $this->user = $user;
        $this->cartItems = $cartItems;
        $this->cartTotal = $cartTotal;
        $this->reminderType = $reminderType;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $subjects = [
            '1h' => 'You left items in your cart! 🛒',
            '24h' => 'Still interested? Complete your purchase 💝',
            '3d' => 'Last chance! Your cart expires soon ⏰',
        ];

        return new Envelope(
            subject: $subjects[$this->reminderType] ?? 'Complete Your Purchase - Taga',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.cart-reminder',
            text: 'emails.cart-reminder-text',
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
