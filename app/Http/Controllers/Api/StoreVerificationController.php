<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Pharmacy licence submission (vendor) and approval (platform admin).
 *
 * Approval is what grants `can_sell_prescription` / `can_sell_controlled`; without it
 * a vendor can only list general-sale items.
 */
class StoreVerificationController extends Controller
{
    /**
     * Vendor: current verification state for their store.
     */
    public function show(Request $request): JsonResponse
    {
        $store = $this->resolveStore($request);

        if (! $store) {
            return response()->json([
                'success' => false,
                'message' => 'No store associated with this account.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $this->present($store),
        ]);
    }

    /**
     * Vendor: send a licence document for review, or send a renewed one.
     */
    public function submit(Request $request): JsonResponse
    {
        $store = $this->resolveStore($request);

        if (! $store) {
            return response()->json([
                'success' => false,
                'message' => 'No store associated with this account.',
            ], 403);
        }

        if ($store->verification_status === Store::VERIFICATION_PENDING) {
            return response()->json([
                'success' => false,
                'message' => 'A verification request is already under review.',
            ], 422);
        }

        // The document and nothing else, exactly as at application: everything a
        // reviewer needs is printed on the licence, and it is the reviewer who
        // records it when they approve. A renewal that only changed the typed
        // expiry — with the same old document attached — used to look identical
        // to a genuine one.
        $request->validate([
            'license_document' => 'required|file|mimes:jpg,jpeg,png,webp,pdf|max:10240',
        ]);

        // Licence documents are confidential; keep them off the public disk.
        $path = $request->file('license_document')->store('licenses', 'local');

        $store->update(array_merge(
            [],
            [
                'pharmacy_license_document' => $path,
                'verification_status' => Store::VERIFICATION_PENDING,
                'verification_notes' => null,
                // Any previously granted permissions are withdrawn until re-approved.
                'can_sell_prescription' => false,
                'can_sell_controlled' => false,
                'verified_at' => null,
                'verified_by' => null,
                // A new licence means a new expiry, so the reminders start over.
                'licence_reminder_stage' => null,
            ]
        ));

        $this->notifySubmission($store->fresh());

        return response()->json([
            'success' => true,
            'message' => 'Verification submitted. Our team will review your licence.',
            'data' => $this->present($store->fresh()),
        ]);
    }

    /**
     * Confirm the submission to the pharmacy, and put it in front of a reviewer.
     *
     * Submitting used to be silent both ways. The pharmacy had no confirmation
     * that the document arrived, and nothing told the platform there was
     * anything in the queue — so a licence sat unreviewed until somebody
     * happened to open the page, while the pharmacy watched its regulated
     * listings go dark with no explanation.
     *
     * Neither message may take the submission down with it if the mail server
     * is having a bad day: the licence is already saved by the time this runs,
     * and losing the upload because an SMTP connection timed out would be a far
     * worse outcome than a missing email. Hence a catch around each, separately,
     * so a failure to reach the reviewers does not also cost the pharmacy its
     * acknowledgement.
     */
    private function notifySubmission(Store $store): void
    {
        $applicant = $store->email ?: $store->owner?->email;

        if ($applicant) {
            try {
                \Illuminate\Support\Facades\Mail::to($applicant)->send(
                    new \App\Mail\StoreVerificationSubmittedEmail($store, forReviewer: false)
                );
            } catch (\Throwable $e) {
                \Log::error('Failed to acknowledge a licence submission: '.$e->getMessage(), [
                    'store_id' => $store->id,
                ]);
            }
        } else {
            \Log::warning('Licence submitted but there is no address to acknowledge it to', [
                'store_id' => $store->id,
            ]);
        }

        // Through the shared resolver rather than a lookup of its own. This
        // method grew its own copy of "who are the admins" before there was one
        // place to ask, and four such copies had already drifted into three
        // different answers.
        \App\Support\PlatformAdmins::notify(
            fn () => new \App\Mail\StoreVerificationSubmittedEmail($store, forReviewer: true),
            'a licence submission',
            ['store_id' => $store->id],
        );
    }

    /**
     * Admin: stores awaiting review.
     */
    public function pending(Request $request): JsonResponse
    {
        $query = Store::query()->with('owner')->latest('updated_at');

        $status = $request->input('status', Store::VERIFICATION_PENDING);

        // A licence quietly lapsing takes a whole pharmacy off sale, so the
        // queue can also be filtered to the ones about to — otherwise the only
        // way to notice is a shop complaining that its orders stopped.
        if ($status === 'expiring') {
            $query->where('verification_status', Store::VERIFICATION_APPROVED)
                ->whereNotNull('pharmacy_license_expiry')
                ->whereDate('pharmacy_license_expiry', '<=', now()->addDays(
                    $request->integer('within_days', 30)
                )->toDateString())
                ->reorder('pharmacy_license_expiry');
        } else {
            $query->where('verification_status', $status);
        }

        return response()->json([
            'success' => true,
            'counts' => [
                'pending' => Store::where('verification_status', Store::VERIFICATION_PENDING)->count(),
                'expiring' => Store::where('verification_status', Store::VERIFICATION_APPROVED)
                    ->whereNotNull('pharmacy_license_expiry')
                    ->whereDate('pharmacy_license_expiry', '<=', now()->addDays(30)->toDateString())
                    ->whereDate('pharmacy_license_expiry', '>=', now()->toDateString())
                    ->count(),
                'expired' => Store::where('verification_status', Store::VERIFICATION_APPROVED)
                    ->whereNotNull('pharmacy_license_expiry')
                    ->whereDate('pharmacy_license_expiry', '<', now()->toDateString())
                    ->count(),
            ],
            'data' => $query->paginate($request->integer('per_page', 20))
                ->through(fn (Store $store) => array_merge($this->present($store), [
                    'store' => ['id' => $store->id, 'name' => $store->name, 'slug' => $store->slug],
                    'owner' => $store->owner
                        ? ['id' => $store->owner->id, 'name' => $store->owner->name, 'email' => $store->owner->email]
                        : null,
                    'license_document_url' => "/api/v1/admin/stores/{$store->id}/verification/document",
                ])),
        ]);
    }

    /**
     * Admin: streams a submitted licence document.
     */
    public function document($id)
    {
        $store = Store::find($id);

        if (! $store || ! $store->pharmacy_license_document) {
            return response()->json([
                'success' => false,
                'message' => 'Licence document not found',
            ], 404);
        }

        if (! Storage::disk('local')->exists($store->pharmacy_license_document)) {
            return response()->json([
                'success' => false,
                'message' => 'Licence document file is missing',
            ], 404);
        }

        return Storage::disk('local')->download($store->pharmacy_license_document);
    }

    /**
     * Admin: approve or reject, and set what the store may sell.
     */
    public function review(Request $request, $id): JsonResponse
    {
        $validated = $request->validate([
            'action' => 'required|in:approve,reject',
            'can_sell_prescription' => 'nullable|boolean',
            'can_sell_controlled' => 'nullable|boolean',
            'notes' => 'required_if:action,reject|nullable|string|max:2000',
            // Read off the document the reviewer is approving. The pharmacy
            // uploads the licence and types none of this, so if it is not
            // captured here it is captured nowhere — and the expiry is what
            // the renewal reminders and `isLicenceValid()` run on. Required to
            // approve, ignored on a rejection.
            'pharmacy_license_number' => 'required_if:action,approve|nullable|string|max:100',
            'pharmacy_license_expiry' => 'required_if:action,approve|nullable|date',
            'premises_registration_number' => 'nullable|string|max:100',
            'superintendent_pharmacist_name' => 'nullable|string|max:255',
            'superintendent_pharmacist_license' => 'nullable|string|max:100',
            'superintendent_pharmacist_phone' => 'nullable|string|max:50',
        ], [
            'pharmacy_license_number.required_if' => 'Enter the licence number shown on the document.',
            'pharmacy_license_expiry.required_if' => 'Enter the expiry date shown on the document — '
                .'renewal reminders depend on it.',
        ]);

        $store = Store::find($id);

        if (! $store) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found',
            ], 404);
        }

        // Read this before approving: approval promotes the owner, and after
        // that there is no way to tell that they applied from outside.
        $wasApplicant = $store->owner?->role !== 'store_owner';

        if ($validated['action'] === 'reject') {
            $store->update([
                'verification_status' => Store::VERIFICATION_REJECTED,
                'verification_notes' => $validated['notes'],
                'can_sell_prescription' => false,
                'can_sell_controlled' => false,
                'verified_at' => null,
                'verified_by' => $request->user()?->id,
            ]);

            $notice = $this->notifyDecision($store->fresh(), false, $validated['notes'] ?? null, $wasApplicant);

            return $this->decisionResponse($store, $notice, 'Store verification rejected.');
        }

        $canSellControlled = $request->boolean('can_sell_controlled');

        $store->update([
            // What the reviewer read off the licence itself.
            'pharmacy_license_number' => $validated['pharmacy_license_number'],
            'pharmacy_license_expiry' => $validated['pharmacy_license_expiry'],
            'premises_registration_number' => $validated['premises_registration_number'] ?? $store->premises_registration_number,
            'superintendent_pharmacist_name' => $validated['superintendent_pharmacist_name'] ?? $store->superintendent_pharmacist_name,
            'superintendent_pharmacist_license' => $validated['superintendent_pharmacist_license'] ?? $store->superintendent_pharmacist_license,
            'superintendent_pharmacist_phone' => $validated['superintendent_pharmacist_phone'] ?? $store->superintendent_pharmacist_phone,
            // A new expiry means the renewal reminders start over.
            'licence_reminder_stage' => null,
            'verification_status' => Store::VERIFICATION_APPROVED,
            'verified_at' => now(),
            'verified_by' => $request->user()?->id,
            'verification_notes' => $validated['notes'] ?? null,
            // Whether approval implies Rx permission is admin-configurable; some
            // operators prefer to grant it as a separate deliberate step.
            'can_sell_prescription' => $request->has('can_sell_prescription')
                ? $request->boolean('can_sell_prescription')
                : \App\Support\PharmacyPolicy::grantPrescriptionOnApproval(),
            // Controlled substances stay opt-in by design and are never granted
            // implicitly — this is intentionally NOT configurable.
            'can_sell_controlled' => $canSellControlled,
        ]);

        // Approval is the moment the shop opens and the owner is let into the
        // dashboard. A pharmacy that applied from the storefront has been
        // waiting outside for exactly this.
        StoreApplicationController::grantDashboardAccess($store->fresh());

        $notice = $this->notifyDecision($store->fresh(), true, null, $wasApplicant);

        return $this->decisionResponse($store, $notice, 'Store verified successfully.');
    }

    /**
     * Tell the pharmacy what was decided, and say whether we managed to.
     *
     * Approval is what puts their listings on sale and rejection is what keeps
     * them off it, so staying silent left a shop with no idea why it had no
     * orders. A mail failure must not undo the decision itself — the licence
     * really has been approved — hence the catch.
     *
     * What the catch must NOT do is swallow the outcome. Both failures here
     * used to end in a log line while the reviewer was told "Store verified
     * successfully", so an approval that emailed nobody was indistinguishable
     * from one that worked. The only place the difference showed was a server
     * log nobody reads, and the pharmacy waited for a message that was never
     * coming. This returns what happened so the reviewer can be told.
     *
     * @return array{sent: bool, recipient: ?string, error: ?string}
     */
    private function notifyDecision(Store $store, bool $approved, ?string $reason, ?bool $isApplicant = null): array
    {
        /*
         * The person, not the premises.
         *
         * This read `$store->email` first and only fell back to the owner. The
         * two are separate fields captured on separate steps of the application
         * — `email` is the pharmacy's public contact address, `owner_email` is
         * whoever registered it — so wherever a shop published a general
         * address (info@, a landline-era address, a branch inbox nobody opens)
         * the decision went there and the person waiting for it never saw it.
         *
         * Approval is addressed to a human: it is what promotes their account
         * and lets them into the dashboard. That is the registrant. The
         * pharmacy address stays only as a fallback, so a store whose owner
         * record has no address is still reachable rather than silently
         * unsendable.
         */
        $recipient = $store->owner?->email ?: $store->email;

        if (! $recipient) {
            \Log::warning('Store verification decided but there is no address to tell them', [
                'store_id' => $store->id,
                'approved' => $approved,
            ]);

            return [
                'sent' => false,
                'recipient' => null,
                'error' => 'This pharmacy has no email address on file, and neither does its owner.',
            ];
        }

        try {
            \Illuminate\Support\Facades\Mail::to($recipient)->send(
                new \App\Mail\StoreVerificationDecisionEmail($store, $approved, $reason, $isApplicant)
            );

            \Log::info('Store verification decision email sent', [
                'store_id' => $store->id,
                'approved' => $approved,
                'recipient' => $recipient,
            ]);

            /*
             * Logged like the applicant's verification email is. A log file on
             * shared hosting is not somewhere anyone can check whether a given
             * pharmacy was told; this row is.
             *
             * Caught separately on purpose. The message has already gone by
             * this point, so a failure to write the record must not fall into
             * the catch below and report a delivered email as a failed one.
             */
            try {
                \App\Models\EmailLog::logEmail(
                    $recipient,
                    'store_verification_decision',
                    $approved ? 'Pharmacy licence approved' : 'Pharmacy licence not approved',
                    null,
                    $store->owner_id
                )->markAsSent();
            } catch (\Throwable $e) {
                \Log::warning('Could not record the store verification email: '.$e->getMessage(), [
                    'store_id' => $store->id,
                ]);
            }

            return ['sent' => true, 'recipient' => $recipient, 'error' => null];
        } catch (\Throwable $e) {
            \Log::error('Failed to send store verification decision email: '.$e->getMessage(), [
                'store_id' => $store->id,
                'recipient' => $recipient,
                'exception' => $e::class,
            ]);

            return ['sent' => false, 'recipient' => $recipient, 'error' => $e->getMessage()];
        }
    }

    /**
     * The decision stands either way; the message about it might not have.
     *
     * Reported next to the success rather than instead of it, because the two
     * are genuinely separate outcomes and the reviewer needs to act on the
     * second one — by telling the pharmacy another way.
     */
    private function decisionResponse(Store $store, array $notice, string $success): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $notice['sent']
                ? $success
                : $success.' The pharmacy could NOT be emailed, so tell them another way.',
            'notification' => $notice,
            'data' => $this->present($store->fresh()),
        ]);
    }

    private function resolveStore(Request $request): ?Store
    {
        $user = $request->user();

        if (! $user) {
            return null;
        }

        if ($user->store_id) {
            return Store::find($user->store_id);
        }

        return Store::where('owner_id', $user->id)->first();
    }

    /**
     * Omits the stored document path — it is only reachable via document().
     */
    private function present(Store $store): array
    {
        return [
            'store_id' => $store->id,
            'verification_status' => $store->verification_status,
            'is_licence_valid' => $store->isLicenceValid(),
            // Whether anything this shop lists is actually purchasable, and why
            // not — the dashboard needs to say so plainly rather than leave an
            // owner wondering why they have no orders.
            'can_sell' => $store->canSell(),
            'selling_blocked_reason' => $store->sellingBlockedReason(),
            'days_until_expiry' => $store->pharmacy_license_expiry
                ? (int) now()->startOfDay()->diffInDays($store->pharmacy_license_expiry->startOfDay(), false)
                : null,
            'pharmacy_license_number' => $store->pharmacy_license_number,
            'pharmacy_license_expiry' => $store->pharmacy_license_expiry?->toDateString(),
            'has_license_document' => (bool) $store->pharmacy_license_document,
            'premises_registration_number' => $store->premises_registration_number,
            'superintendent_pharmacist_name' => $store->superintendent_pharmacist_name,
            'superintendent_pharmacist_license' => $store->superintendent_pharmacist_license,
            'superintendent_pharmacist_phone' => $store->superintendent_pharmacist_phone,
            'can_sell_prescription' => $store->can_sell_prescription,
            'can_sell_controlled' => $store->can_sell_controlled,
            'verified_at' => $store->verified_at?->toIso8601String(),
            'verification_notes' => $store->verification_notes,
        ];
    }
}
