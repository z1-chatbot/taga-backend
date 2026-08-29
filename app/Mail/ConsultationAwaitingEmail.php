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
 * Tells a practitioner that someone is waiting in their specialty.
 *
 * A request reaches every practitioner covering that specialty rather than a
 * named one, which is the right way round for cover — but it means nobody is
 * personally on the hook, and without this nobody would know a request had
 * arrived unless they happened to have the queue open.
 *
 * Deliberately does not carry the patient's message. This lands in several
 * inboxes at once and only one of those people will end up handling it; the
 * rest have no business reading someone's symptoms. The reference and the
 * specialty are enough to decide whether to pick it up.
 */
class ConsultationAwaitingEmail extends Mailable
{
    use Queueable, SerializesModels, SendsFromMailbox;

    /** A person answers these, so they come from the support mailbox. */
    protected string $mailbox = 'support';

    public ConsultationRequest $consultation;

    public string $practitionerName;

    public string $specialty;

    /** Opens the queue with this request showing. */
    public string $queueUrl;

    public function __construct(ConsultationRequest $consultation, string $practitionerName)
    {
        $this->consultation = $consultation;
        $this->practitionerName = $practitionerName;
        $this->specialty = $consultation->practitionerLabel();
        $this->queueUrl = AppUrl::admin('/consultations?reference='.$consultation->reference);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Someone is waiting to speak to a {$this->specialty} ({$this->consultation->reference})",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.consultation-awaiting',
            text: 'emails.consultation-awaiting-text',
        );
    }
}
