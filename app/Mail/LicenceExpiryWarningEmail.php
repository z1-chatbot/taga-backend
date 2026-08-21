<?php

namespace App\Mail;

use App\Support\AppUrl;
use App\Mail\Concerns\SendsFromMailbox;
use App\Models\Store;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Warns a pharmacy that its licence is about to lapse.
 *
 * Selling is gated on a current licence, so the day one expires the shop's
 * entire catalogue stops being purchasable. Without this the first they know of
 * it is the orders drying up.
 *
 * Also used the other side of the date, to tell a shop its listings have now
 * come down and what to do about it.
 */
class LicenceExpiryWarningEmail extends Mailable
{
    use Queueable, SerializesModels, SendsFromMailbox;

    /**
     * Sent from the support mailbox — a person is expected to reply to this.
     *
     * Each mailbox is its own SMTP account and may only send as its own
     * address, so this picks the credentials as well as the From line.
     */
    protected string $mailbox = 'support';

    public Store $store;

    public int $daysRemaining;

    public bool $expired;

    public string $dashboardUrl;

    public function __construct(Store $store, int $daysRemaining)
    {
        $this->store = $store;
        $this->daysRemaining = $daysRemaining;
        $this->expired = $daysRemaining < 0;

        $this->dashboardUrl = AppUrl::admin();
    }

    public function envelope(): Envelope
    {
        if ($this->expired) {
            return new Envelope(
                subject: "{$this->store->name} is no longer selling — pharmacy licence expired",
            );
        }

        return new Envelope(
            subject: $this->daysRemaining === 0
                ? "{$this->store->name}: your pharmacy licence expires today"
                : "{$this->store->name}: your pharmacy licence expires in {$this->daysRemaining} days",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.licence-expiry-warning',
            text: 'emails.licence-expiry-warning-text',
        );
    }
}
