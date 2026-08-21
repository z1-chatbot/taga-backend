<?php

namespace App\Mail;

use App\Mail\Concerns\SendsFromMailbox;
use App\Models\Order;
use App\Models\DeliverySetting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DeliveryAssignmentEmail extends Mailable
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
    public $recipientType; // 'company' or 'agent'
    public $recipientName;
    public $trackingNumber;
    public $shippingFeeAfterCommission;

    /**
     * Create a new message instance.
     */
    public function __construct(Order $order, string $recipientType, string $recipientName, ?string $trackingNumber = null)
    {
        $this->order = $order;
        $this->recipientType = $recipientType;
        $this->recipientName = $recipientName;
        $this->trackingNumber = $trackingNumber;

        // The figure quoted here is produced by the same service that credits
        // the earning on delivery. It used to be an independent percentage
        // calculation, so a courier was promised one amount in this email and
        // paid a different one when the parcel arrived.
        $this->shippingFeeAfterCommission = app(\App\Services\DeliveryEarningsService::class)
            ->quote($order)['courier'];
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New Delivery Assignment - Order #' . $this->order->order_number,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.delivery-assignment',
            text: 'emails.delivery-assignment-text',
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
