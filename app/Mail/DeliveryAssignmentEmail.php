<?php

namespace App\Mail;

use App\Mail\Concerns\SendsFromMailbox;
use App\Models\Order;
use App\Models\OrderShipment;
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

    /** The parcel being assigned. Null only for an order that has no shipments. */
    public $shipment;

    /**
     * Create a new message instance.
     */
    /**
     * Every parcel on this courier's round, this one included.
     *
     * Two pharmacies in the same city are assigned together as one pickup run,
     * so the manifest has to cover all of them. Scoped to the one parcel that
     * happened to be clicked, this email sent a rider to one shop and never
     * mentioned the second — which they had already been paid to collect.
     *
     * @var \Illuminate\Support\Collection
     */
    public $runShipments;

    public function __construct(
        Order $order,
        string $recipientType,
        string $recipientName,
        ?string $trackingNumber = null,
        ?OrderShipment $shipment = null
    ) {
        $this->order = $order;
        $this->recipientType = $recipientType;
        $this->recipientName = $recipientName;
        $this->trackingNumber = $trackingNumber;
        $this->shipment = $shipment;

        /*
         * The whole round, not the one parcel that was clicked.
         *
         * Pharmacies in the same city are assigned together and paid as one
         * journey, so the manifest has to name all of them. Without this the
         * email sent a rider to one shop and never mentioned the second, which
         * they had already been paid to collect.
         */
        $this->runShipments = $shipment
            ? $shipment->run()->with('store')->orderBy('id')->get()
            : $order->shipments()->with('store')->orderBy('id')->get();

        /*
         * The figure quoted here is produced by the same service that credits
         * the earning on delivery. It used to be an independent percentage
         * calculation, so a courier was promised one amount in this email and
         * paid a different one when the parcel arrived.
         *
         * The parcel has to be passed for that promise to hold now that an
         * order can be split: payment is per parcel, priced on that parcel's
         * own route and its own share of the shipping fee. Quoting the order
         * promised a courier the whole basket's fee for carrying part of it,
         * and looked their agreed rate up against the wrong route.
         */
        $this->shippingFeeAfterCommission = app(\App\Services\DeliveryEarningsService::class)
            ->quote($order, null, null, $shipment)['courier'];
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
