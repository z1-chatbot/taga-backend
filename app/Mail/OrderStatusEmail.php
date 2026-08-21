<?php

namespace App\Mail;

use App\Mail\Concerns\SendsFromMailbox;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderStatusEmail extends Mailable
{
    use Queueable, SerializesModels, SendsFromMailbox;

    /**
     * Sent from the shop mailbox — commerce: orders, cart, delivery, payouts, partner updates.
     *
     * Each mailbox is its own SMTP account and may only send as its own
     * address, so this picks the credentials as well as the From line.
     */
    protected string $mailbox = 'shop';

    public $order;
    public $statusType; // 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled'

    /**
     * Create a new message instance.
     */
    public function __construct(Order $order, string $statusType)
    {
        $this->order = $order;
        $this->statusType = $statusType;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $subjects = [
            'confirmed' => 'Order Confirmed! 🎉 - Order #' . $this->order->order_number,
            'processing' => 'Your Order is Being Prepared 📦 - Order #' . $this->order->order_number,
            'shipped' => 'Your Order Has Been Shipped! 🚚 - Order #' . $this->order->order_number,
            'delivered' => 'Your Order Has Been Delivered! ✅ - Order #' . $this->order->order_number,
            'cancelled' => 'Order Cancelled - Order #' . $this->order->order_number,
        ];

        return new Envelope(
            subject: $subjects[$this->statusType] ?? 'Order Update - Taga',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.order-status',
            text: 'emails.order-status-text',
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
