<?php

namespace App\Mail;

use App\Mail\Concerns\SendsFromMailbox;
use App\Models\ConsultationMessage;
use App\Models\ConsultationRequest;
use App\Support\AppUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Carries a support reply out to the person who raised the consultation.
 *
 * Only ever constructed for a reply, never for an internal note — the two share
 * a table, and the guard that keeps notes off this path lives in
 * ConsultationController::adminReply().
 */
class ConsultationReplyEmail extends Mailable
{
    use Queueable, SerializesModels, SendsFromMailbox;

    /** Replies are a conversation: they come from, and go back to, support. */
    protected string $mailbox = 'support';

    public ConsultationRequest $consultation;

    /*
     * Named `reply`, not `message`: Laravel injects the Swift/Symfony message
     * into every mail view as $message, and a property of that name is silently
     * shadowed by it — the view then reads $message->author_name off the
     * transport object and the send fails at render time.
     */
    public ConsultationMessage $reply;

    public string $practitioner;

    public string $trackUrl;

    public function __construct(ConsultationRequest $consultation, ConsultationMessage $reply)
    {
        $this->consultation = $consultation;
        $this->reply = $reply;
        $this->practitioner = $consultation->practitionerLabel();
        $this->trackUrl = AppUrl::storefront('/?consultation='.$consultation->reference);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Re: your consultation request ({$this->consultation->reference})",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.consultation-reply',
            text: 'emails.consultation-reply-text',
        );
    }
}
