<?php

namespace App\Mail;

use App\Mail\Concerns\SendsFromMailbox;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DeliveryTrackingUpdateEmail extends Mailable
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
    public $statusLabel;
    public $statusDescription;
    public $recipientName;
    public $recipientType; // 'customer', 'company', 'agent'

    /**
     * Create a new message instance.
     */
    public function __construct(Order $order, string $statusLabel, string $statusDescription, string $recipientName, string $recipientType = 'customer')
    {
        $this->order = $order;
        $this->statusLabel = $statusLabel;
        $this->statusDescription = $statusDescription;
        $this->recipientName = $recipientName;
        $this->recipientType = $recipientType;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Delivery Update: ' . $this->statusLabel . ' - Order #' . $this->order->order_number,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.delivery-tracking-update',
            text: 'emails.delivery-tracking-update-text',
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
