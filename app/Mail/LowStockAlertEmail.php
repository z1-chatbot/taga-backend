<?php

namespace App\Mail;

use App\Mail\Concerns\SendsFromMailbox;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LowStockAlertEmail extends Mailable
{
    use Queueable, SerializesModels, SendsFromMailbox;

    /**
     * Sent from the shop mailbox — commerce: orders, cart, delivery, payouts, partner updates.
     *
     * Each mailbox is its own SMTP account and may only send as its own
     * address, so this picks the credentials as well as the From line.
     */
    protected string $mailbox = 'shop';

    public $lowStockProducts;
    public $threshold;

    /** The pharmacy this list belongs to; null for platform-owned stock. */
    public ?string $storeName;

    public function __construct(array $lowStockProducts, int $threshold = 10, ?string $storeName = null)
    {
        $this->lowStockProducts = $lowStockProducts;
        $this->threshold = $threshold;
        $this->storeName = $storeName;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $count = count($this->lowStockProducts);
        $who = $this->storeName ? " - {$this->storeName}" : '';

        return new Envelope(
            subject: "Low stock: {$count} product(s) need restocking{$who} - Taga",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.low-stock-alert',
            text: 'emails.low-stock-alert-text',
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
