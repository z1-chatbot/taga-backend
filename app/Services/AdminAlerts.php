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
     * A pharmacy has applied to sell on the platform.
     *
     * Distinct from a licence submission by an existing store: this is a brand
     * new account with nothing on the platform yet, sitting outside the
     * dashboard until somebody approves it. Nothing announced it, so an
     * applicant's wait was bounded only by how often the queue was opened.
     */
    public static function pharmacyApplied(Store $store): int
    {
        return PlatformAdmins::notify(
            fn () => new AdminAlertEmail(
                subject: "New pharmacy application: {$store->name}",
                heading: 'A pharmacy has applied to join',
                intro: "{$store->name} has registered and submitted a pharmacy licence for review.",
                rows: array_filter([
                    'Pharmacy' => e($store->name),
                    'Contact' => e($store->email ?: ($store->owner?->email ?? 'Not on file')),
                    'Location' => e(trim(($store->city ? $store->city.', ' : '').($store->state ?? ''), ', ')) ?: null,
                    'Applied' => $store->created_at?->format('j F Y, g:ia'),
                ]),
                actionUrl: AppUrl::admin('/store-verifications'),
                actionLabel: 'Open the review queue',
                note: 'They are outside the dashboard until this is approved, and nothing they '
                    .'list is purchasable, so the wait is visible to them as an empty shopfront.',
            ),
            'a new pharmacy application',
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
