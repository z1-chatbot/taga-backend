<?php

namespace App\Mail;

use App\Mail\Concerns\SendsFromMailbox;
use App\Models\Store;
use App\Support\AppUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Acknowledges a pharmacy licence submission, and tells the reviewers about it.
 *
 * Submitting a licence used to be completely silent in both directions. The
 * pharmacy uploaded a document, got a JSON response, and then heard nothing —
 * with no way to tell a submission that was queued for review from one that had
 * failed to arrive. Meanwhile nothing told the platform there was anything
 * waiting, so the queue was only ever emptied by somebody remembering to open
 * it.
 *
 * That silence is worse than it sounds, because submitting also withdraws
 * `can_sell_prescription` and `can_sell_controlled` until the new licence is
 * approved. A pharmacy renewing on time watches its regulated listings go dark
 * and is told neither that this happened nor that it is temporary. The
 * acknowledgement below says so plainly.
 *
 * One class, two audiences. The pharmacy's copy and the reviewer's copy are
 * about the same event and share every fact in it; splitting them into separate
 * mailables would mean two files that have to be kept in step by hand for no
 * gain. `$forReviewer` picks the voice.
 */
class StoreVerificationSubmittedEmail extends Mailable
{
    use Queueable, SerializesModels, SendsFromMailbox;

    /**
     * Support, not noreply — a pharmacy that reads this and has a question
     * about its licence should be able to press reply and reach a person.
     */
    protected string $mailbox = 'support';

    public Store $store;

    /** True for the platform reviewers' copy, false for the pharmacy's. */
    public bool $forReviewer;

    public string $dashboardUrl;

    /** Deep link to the review queue, for the reviewers' copy. */
    public string $queueUrl;

    /** True while they are still outside waiting to be let in. */
    public bool $isApplicant;

    public function __construct(Store $store, bool $forReviewer = false)
    {
        $this->store = $store;
        $this->forReviewer = $forReviewer;

        $this->dashboardUrl = AppUrl::admin();
        $this->queueUrl = AppUrl::admin('/store-verifications');

        $this->isApplicant = $store->owner?->role !== 'store_owner';
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->forReviewer
                ? "Licence awaiting review: {$this->store->name}"
                : "We have your pharmacy licence for {$this->store->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.store-verification-submitted',
            text: 'emails.store-verification-submitted-text',
        );
    }
}
