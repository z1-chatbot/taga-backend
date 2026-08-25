<?php

namespace App\Mail;

use App\Mail\Concerns\SendsFromMailbox;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A platform event an administrator needs to know about.
 *
 * Deliberately generic. Every one of these messages has the same job — say what
 * happened, give the handful of facts needed to judge it, and link to the page
 * where it is dealt with — and writing a bespoke mailable and two blade
 * templates per event would mean the fifth one gets skipped because it is a
 * chore. Events that genuinely need more than a labelled list (a licence
 * decision, a payout with bank details) keep their own templates; this is for
 * the rest.
 *
 * Note there is no model here, only scalars. That is what lets a caller send
 * this from inside a transaction or about a record that is about to change,
 * without a SerializesModels round-trip reloading something different than the
 * message described.
 */
class AdminAlertEmail extends Mailable
{
    use Queueable, SerializesModels, SendsFromMailbox;

    /**
     * Support, not shop.
     *
     * These are internal operational messages and the natural response to one
     * is a reply to a colleague, not a customer-facing commerce notice.
     */
    protected string $mailbox = 'support';

    /**
     * @param  string  $subject      Subject line.
     * @param  string  $heading      Headline inside the message.
     * @param  string  $intro        One sentence: what happened, in plain words.
     * @param  array<string, string|null>  $rows  Label => value. Nulls are
     *         dropped, so a caller can list an optional field unconditionally.
     * @param  string|null  $actionUrl    Where it gets dealt with.
     * @param  string|null  $actionLabel  Button text for that link.
     * @param  string|null  $note         Smaller print under the facts.
     */
    public function __construct(
        // Not a promoted property: Illuminate\Mail\Mailable already declares
        // an untyped `public $subject`, and PHP refuses a subclass that
        // redeclares an inherited property with a type. Assigned in the body
        // instead, which also means envelope() and build() both see it.
        string $subject,
        public string $heading,
        public string $intro,
        public array $rows = [],
        public ?string $actionUrl = null,
        public ?string $actionLabel = null,
        public ?string $note = null,
    ) {
        $this->subject = $subject;

        // Blade's @include('emails.partials.rows') renders values as raw markup
        // so callers can emphasise a figure. Everything reaching this class is
        // therefore escaped at the point it is set, and nulls are stripped here
        // rather than in each template.
        $this->rows = array_filter($rows, fn ($value) => $value !== null && $value !== '');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin-alert',
            text: 'emails.admin-alert-text',
        );
    }
}
