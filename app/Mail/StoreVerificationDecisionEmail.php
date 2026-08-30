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
 * checked, but nothing it lists is purchasable until that check passes — so this
 * is the message that puts them on sale, and the one that explains why they are
 * not. Both outcomes were previously silent: the status changed in the database
 * and the pharmacy was never told.
 */
class StoreVerificationDecisionEmail extends Mailable
{
    use Queueable, SerializesModels, SendsFromMailbox;

    /**
     * Sent from the noreply mailbox, with the rest of the account messages.
     *
     * This was on `support` because a pharmacy might reply to it. That reasoning
     * does not require the support mailbox: config/mail.php points the Reply-To
     * of `noreply` at the support address, so a pharmacy hitting reply reaches a
     * person either way.
     *
     * What it is, is an account message. Approval promotes the owner's account
     * and opens the dashboard; rejection is what keeps it shut. That is the same
     * family as sign-up, verification and password reset — the mailbox
     * config/mail.php describes as "identity".
     *
     * Each mailbox is its own SMTP account and may only send as its own address,
     * so this picks the credentials as well as the From line — which is why the
     * choice decides whether the message is delivered at all, not just how it
     * looks.
     */
    protected string $mailbox = 'noreply';

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
