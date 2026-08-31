<?php

namespace App\Services;

use App\Mail\AdminAlertEmail;
use App\Mail\PayoutRequestedEmail;
use App\Models\ConsultationRequest;
use App\Models\Store;
use App\Support\AppUrl;
use App\Support\PlatformAdmins;

/**
 * The platform events an administrator is told about.
 *
 * Gathered here because they were not gathered anywhere. Store and logistics
 * payout requests each wrote their own admin lookup and their own try/catch;
 * rider payouts, consultation requests and new pharmacy applications wrote
 * nothing at all and were simply never announced. The result was a platform
 * where some things reached an inbox and others waited for somebody to think
 * to refresh a queue.
 *
 * Every method here is best-effort by construction: PlatformAdmins::notify()
 * swallows and logs. That is deliberate rather than lax — each of these is
 * called after something irreversible has already happened (an order is paid,
 * a balance is debited, an application is saved), so a mail failure must never
 * propagate into the caller and undo it.
 *
 * Each alert names the page it is dealt with on, because an alert that tells
 * you something needs attention without saying where is only half a message.
 */
class AdminAlerts
{
    /**
     * A pharmacy is waiting on a licence decision.
     *
     * Fires for every route into the review queue, because from a reviewer's
     * side they are one job. There are three, and only the first used to send
     * anything:
     *
     *   · A brand new account applying from the storefront (/sell/register).
     *   · An existing customer applying while signed in (/sell/apply).
     *   · An owner an admin created, setting the shop up from inside the
     *     dashboard — which posts to /sell/apply as well.
     *
     * The last two announced themselves to nobody. The applicant was told we
     * would email them either way, and the only thing standing between them and
     * that promise was somebody remembering to open the verification queue.
     *
     * The wording has to bend to the route, or it tells the reviewer something
     * untrue. "They are outside the dashboard until this is approved" is right
     * for an applicant off the storefront and wrong for an admin-created owner,
     * who is already inside it with everything but their own shop page locked.
     */
    public static function pharmacyApplied(Store $store, bool $resubmission = false): int
    {
        // An owner who already holds the role is inside the dashboard, gated,
        // rather than shut out of it — see the store owner onboarding gates.
        $inDashboard = $store->owner?->role === 'store_owner';

        $note = $inDashboard
            ? 'Their shop exists but cannot sell, and every page of their dashboard except their '
                .'own shop is locked until this is approved.'
            : 'They are outside the dashboard until this is approved, and nothing they list is '
                .'purchasable, so the wait is visible to them as an empty shopfront.';

        return PlatformAdmins::notify(
            fn () => new AdminAlertEmail(
                subject: $resubmission
                    ? "Pharmacy licence resubmitted: {$store->name}"
                    : "New pharmacy application: {$store->name}",
                heading: $resubmission
                    ? 'A pharmacy has resent its licence'
                    : 'A pharmacy is waiting on a licence check',
                intro: $resubmission
                    ? "{$store->name} was turned down before and has submitted a new pharmacy licence."
                    : "{$store->name} has submitted a pharmacy licence for review.",
                rows: array_filter([
                    'Pharmacy' => e($store->name),
                    'Contact' => e($store->email ?: ($store->owner?->email ?? 'Not on file')),
                    'Location' => e(trim(($store->city ? $store->city.', ' : '').($store->state ?? ''), ', ')) ?: null,
                    // The submission, not the account: on a resubmission, and
                    // for an owner an admin created weeks earlier, created_at is
                    // not when this landed in the queue.
                    'Submitted' => $store->updated_at?->format('j F Y, g:ia'),
                ]),
                actionUrl: AppUrl::admin('/store-verifications'),
                actionLabel: 'Open the review queue',
                note: $note,
            ),
            $resubmission ? 'a resubmitted pharmacy licence' : 'a new pharmacy application',
            ['store_id' => $store->id],
        );
    }

    /**
     * Somebody has asked to withdraw money.
     *
     * One method for all three kinds of requester because the message is the
     * same message — a balance has been debited and is waiting on a decision —
     * and the existing template already branches on `$requesterType`. Riders
     * were the odd one out purely because nobody had wired theirs up.
     *
     * @param  string  $requesterType  store_owner, logistics_company or delivery_agent
     */
    public static function payoutRequested(
        $payout,
        string $requesterType,
        string $requesterName,
        ?string $requesterEmail
    ): int {
        return PlatformAdmins::notify(
            fn () => new PayoutRequestedEmail($payout, $requesterType, $requesterName, $requesterEmail),
            'a payout request',
            [
                'payout_id' => $payout->id ?? null,
                'requester_type' => $requesterType,
            ],
        );
    }

    /**
     * A customer wants to speak to a practitioner.
     *
     * The customer got an acknowledgement; nobody who could actually answer
     * was told. A consultation about medication is time-sensitive in a way an
     * unread queue does not respect.
     */
    public static function consultationRaised(ConsultationRequest $consultation): int
    {
        $practitioner = ConsultationRequest::PRACTITIONER_TYPES[$consultation->practitioner_type]
            ?? $consultation->practitioner_type;

        return PlatformAdmins::notify(
            fn () => new AdminAlertEmail(
                subject: "New consultation request {$consultation->reference}",
                heading: 'Someone has asked to speak to a practitioner',
                intro: "{$consultation->name} has raised a consultation request.",
                rows: array_filter([
                    'Reference' => e($consultation->reference),
                    'Practitioner' => e($practitioner),
                    'From' => e($consultation->name),
                    'Email' => e($consultation->email),
                    'Phone' => $consultation->phone ? e($consultation->phone) : null,
                    'Prefers' => e(ucfirst($consultation->preferred_contact ?? 'email')),
                    'Best time' => $consultation->preferred_time ? e($consultation->preferred_time) : null,
                    'Subject' => $consultation->subject ? e($consultation->subject) : null,
                ]),
                actionUrl: AppUrl::admin('/consultations'),
                actionLabel: 'Open consultations',
                // The message body itself is deliberately not in the email.
                // It is a health question from a named person, and the reply
                // is composed in the dashboard where the thread lives; copying
                // it into every administrator's inbox spreads it further than
                // it needs to go.
                note: 'The full message is on the consultation thread in the dashboard.',
            ),
            'a consultation request',
            ['consultation_id' => $consultation->id, 'reference' => $consultation->reference],
        );
    }
}
