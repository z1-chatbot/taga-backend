<?php

namespace App\Mail;

use App\Mail\Concerns\SendsFromMailbox;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderNotificationEmail extends Mailable
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
    public $notificationType;
    public $recipientName;
    public $data;

    /**
     * Create a new message instance.
     */
    public function __construct(Order $order, string $notificationType, string $recipientName, array $data = [])
    {
        $this->order = $order;
        $this->notificationType = $notificationType;
        $this->recipientName = $recipientName;
        $this->data = $data;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $subjects = [
            // Customer notifications
            'order_placed' => '🎉 Order Confirmed! - Order #' . $this->order->order_number,
            'status_update' => '📦 Order Update - Order #' . $this->order->order_number,
            'delivery_issue' => '⚠️ Delivery Update - Order #' . $this->order->order_number,
            
            // Store owner notifications
            'new_order' => '🔔 New Order Received - Order #' . $this->order->order_number,
            'agent_assigned' => '🚚 Delivery Agent Assigned - Order #' . $this->order->order_number,
            'order_collected' => '✅ Order Collected by Agent - Order #' . $this->order->order_number,
            'order_delivered' => '🎉 Order Delivered - Order #' . $this->order->order_number,
            'order_cancelled' => '❌ Order Cancelled - Order #' . $this->order->order_number,
            
            // Admin notifications
            'ready_for_pickup' => '📦 Order Ready for Pickup - Order #' . $this->order->order_number,
            'order_picked_up' => '🚚 Order Picked Up - Order #' . $this->order->order_number,
            
            // Agent notifications
            'delivery_assigned' => '🚚 New Delivery Assignment - Order #' . $this->order->order_number,
            'delivery_completed' => '✅ Delivery Completed - Order #' . $this->order->order_number,
            'delivery_cancelled' => '❌ Delivery Cancelled - Order #' . $this->order->order_number,
        ];

        return new Envelope(
            subject: $subjects[$this->notificationType] ?? 'Order Notification - ' . config('app.name'),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.order-notification',
            text: 'emails.order-notification-text',
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
