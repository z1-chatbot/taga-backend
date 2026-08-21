<?php

namespace App\Mail;

use App\Mail\Concerns\SendsFromMailbox;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PayoutApprovedEmail extends Mailable
{
    use Queueable, SerializesModels, SendsFromMailbox;

    /**
     * Sent from the shop mailbox — commerce: orders, cart, delivery, payouts, partner updates.
     *
     * Each mailbox is its own SMTP account and may only send as its own
     * address, so this picks the credentials as well as the From line.
     */
    protected string $mailbox = 'shop';

    public $payout;
    public $recipientType; // 'store_owner', 'logistics_company', 'delivery_agent'
    public $recipientName;

    public function __construct($payout, $recipientType, $recipientName)
    {
        $this->payout = $payout;
        $this->recipientType = $recipientType;
        $this->recipientName = $recipientName;
    }

    public function build()
    {
        return $this->subject('Payout Approved - ₦' . number_format($this->payout->amount, 2))
                    ->view('emails.payout-approved');
    }
}
