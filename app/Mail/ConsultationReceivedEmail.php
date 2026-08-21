<?php

namespace App\Mail;

use App\Mail\Concerns\SendsFromMailbox;
use App\Models\ConsultationRequest;
use App\Support\AppUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Confirms a consultation request and gives the requester their reference.
 *
 * A guest who raises one from the widget has nothing else to hold on to: no
 * account, no order, and a browser they may not come back on. This is the only
 * copy of the reference they get, so it leads with it.
 */
class ConsultationReceivedEmail extends Mailable
{
    use Queueable, SerializesModels, SendsFromMailbox;

    /** A person answers these, so they come from the support mailbox. */
    protected string $mailbox = 'support';

    public ConsultationRequest $consultation;

    public string $practitioner;

    /** Opens the storefront with the widget showing this thread. */
    public string $trackUrl;

    public function __construct(ConsultationRequest $consultation)
    {
        $this->consultation = $consultation;
        $this->practitioner = $consultation->practitionerLabel();
        $this->trackUrl = AppUrl::storefront('/?consultation='.$consultation->reference);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "We have your consultation request ({$this->consultation->reference})",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.consultation-received',
            text: 'emails.consultation-received-text',
        );
    }
}
