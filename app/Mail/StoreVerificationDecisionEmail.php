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
 * Tells a pharmacy the outcome of its licence review.
 *
 * A store can register and build its catalogue while the licence is being
 * checked, but nothing it lists is purchasable until that check passes â€” so this
 * is the message that puts them on sale, and the one that explains why they are
 * not. Both outcomes were previously silent: the status changed in the database
 * and the pharmacy was never told.
 */
class StoreVerificationDecisionEmail extends Mailable
{
    use Queueable, SerializesModels, SendsFromMailbox;

    /**
     * Sent from the support mailbox - a person is expected to reply to this.
     *
     * Each mailbox is its own SMTP account and may only send as its own
     * address, so this picks the credentials as well as the From line.
     */
    protected string $mailbox = 'support';

    public Store $store;

    public bool $approved;

    public ?string $reason;

    public string $dashboardUrl;

    /** True when they are still outside: approval is what lets them in. */
    public bool $isApplicant;

    /** Where an applicant corrects a rejected application. */
    public string $applyUrl;

    /**
     * @param  bool|null  $isApplicant  Whether they were still outside when this
     *                                  was decided. Must be passed by anything
     *                                  that promotes them first, since by then
     *                                  their role no longer says so.
     */
    public function __construct(Store $store, bool $approved, ?string $reason = null, ?bool $isApplicant = null)
    {
        $this->store = $store;
        $this->approved = $approved;
        $this->reason = $reason;

        $this->dashboardUrl = AppUrl::admin();
        $this->applyUrl = AppUrl::storefront('/sell');

        // Someone who has never been promoted is still an applicant, whatever
        // this decision turns out to be.
        $this->isApplicant = $isApplicant ?? ($store->owner?->role !== 'store_owner');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->approved
                ? ($this->isApplicant
                    ? "{$this->store->name} is approved — your Taga dashboard is ready"
                    : "{$this->store->name} is verified and now selling on Taga")
                : "We could not verify the pharmacy licence for {$this->store->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.store-verification-decision',
            text: 'emails.store-verification-decision-text',
        );
    }
}
