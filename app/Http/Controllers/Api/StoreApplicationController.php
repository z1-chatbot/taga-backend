<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\VerifyEmail;
use App\Models\EmailLog;
use App\Models\Role;
use App\Models\Store;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * A pharmacy applying to sell, from the storefront.
 *
 * Nobody creates a pharmacy on their behalf. A customer signs in, fills this in
 * once with their licence attached, and waits. Until an admin approves it they
 * have no dashboard at all — the store sits inactive, the account keeps the role
 * it already had, and the only thing they can see is that the review is under
 * way. Approval is what promotes them and sends the mail with the login link.
 *
 * The pieces this leans on already existed but had no door into them: the
 * licence fields and their private-disk upload come from
 * StoreVerificationController, which still handles resubmission for a pharmacy
 * that is already inside.
 */
class StoreApplicationController extends Controller
{
    /**
     * A pharmacy with no Taga account yet: one form, one submission.
     *
     * Sending a pharmacist through the shopper's sign-up page first and asking
     * them to come back was two steps where the pharmacist only ever saw one
     * job. What comes out of this is exactly what the two steps produced — an
     * ordinary user row and a pending store owned by it — so approval, roles
     * and everything downstream are unchanged.
     *
     * The account fields are prefixed `owner_` because `name`, `email` and
     * `phone` already mean the pharmacy's on this form, and quietly mixing the
     * two is how a person's phone number ends up published as a shop's.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // The person.
            'owner_name' => 'required|string|max:255',
            'owner_email' => 'required|string|email|max:255|unique:users,email',
            'owner_password' => 'required|string|min:8|confirmed',
            'owner_phone' => 'nullable|string|max:20',
            // The pharmacy.
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'phone' => 'required|string|max:20',
            'email' => 'required|email|max:255',
            'state' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'address' => 'required|string|max:500',
            // The licence: the document, and nothing else. Every detail a
            // reviewer needs is printed on it, so asking the pharmacist to
            // retype it only creates a second version to disagree with the
            // first. The reviewer records those details at approval, reading
            // them off the document they are already holding.
            'license_document' => 'required|file|mimes:jpg,jpeg,png,webp,pdf|max:10240',
        ], [
            'owner_email.unique' => 'That email already has a Taga account. Sign in and you can '
                .'apply from here without starting again.',
        ]);

        $documentPath = $request->file('license_document')->store('licenses', 'local');

        [$user, $store] = DB::transaction(function () use ($validated, $documentPath) {
            $user = User::create([
                'name' => $validated['owner_name'],
                'email' => $validated['owner_email'],
                'password' => Hash::make($validated['owner_password']),
                'phone' => $validated['owner_phone'] ?? null,
                // An applicant is an ordinary customer. Approval is the only
                // thing that promotes anyone.
                'role' => 'customer',
                'is_active' => false,
            ]);

            $store = new Store(array_merge(
                collect($validated)
                    ->except(['license_document', 'owner_name', 'owner_email', 'owner_password', 'owner_phone'])
                    ->toArray(),
                [
                    'pharmacy_license_document' => $documentPath,
                    // Not part of $validated -- an applicant does not get to
                    // choose what the platform takes. Set from Settings so the
                    // value there actually governs something.
                    'commission_rate' => SystemSetting::defaultCommissionRate(),
                    'verification_status' => Store::VERIFICATION_PENDING,
                    'verification_notes' => null,
                    'can_sell_prescription' => false,
                    'can_sell_controlled' => false,
                    'verified_at' => null,
                    'verified_by' => null,
                    'licence_reminder_stage' => null,
                    'status' => 'inactive',
                ]
            ));

            $store->owner_id = $user->id;
            $store->slug = $store->generateSlug();
            $store->save();

            return [$user, $store];
        });

        // Best-effort, exactly as ordinary registration treats it: a mail
        // outage must not roll back an account whose email is now taken.
        $verificationSent = $this->sendVerificationEmail($user);

        // And put it in front of a reviewer. An application announced itself to
        // nobody: the applicant was told we would email them either way, and
        // the only thing standing between them and that promise was somebody
        // remembering to open the verification queue.
        \App\Services\AdminAlerts::pharmacyApplied($store->fresh());

        return response()->json([
            'success' => true,
            'message' => 'Thank you. Your pharmacy licence is with our team for review, and we '
                .'will email '.$store->email.' either way.',
            'data' => array_merge($this->present($store), [
                'account_email' => $user->email,
                'requires_verification' => true,
                'verification_email_sent' => $verificationSent,
            ]),
        ], 201);
    }

    private function sendVerificationEmail(User $user): bool
    {
        try {
            $token = $user->generateEmailVerificationToken();

            Mail::to($user->email)->send(new VerifyEmail($user, $token));

            // Marked sent: this line runs only after the send above succeeded,
            // and a row left pending would report a delivered email as an
            // outstanding one.
            EmailLog::logEmail($user->email, 'verification', 'Verify Your Email - Taga', null, $user->id)
                ->markAsSent();

            return true;
        } catch (\Throwable $e) {
            Log::error('Verification email failed for a pharmacy applicant', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Where the applicant stands. Used by the storefront's status page.
     */
    public function show(Request $request): JsonResponse
    {
        $store = $this->applicationFor($request);

        if (! $store) {
            return response()->json([
                'success' => true,
                'data' => ['has_applied' => false],
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $this->present($store),
        ]);
    }

    /**
     * Apply, or correct and resend an application that was turned down.
     */
    public function apply(Request $request): JsonResponse
    {
        $user = $request->user();
        $existing = $this->applicationFor($request);

        if ($existing && $existing->verification_status === Store::VERIFICATION_PENDING) {
            return response()->json([
                'success' => false,
                'message' => 'Your application is already with our team. We will email you when it has been reviewed.',
                'code' => 'application_under_review',
            ], 422);
        }

        if ($existing && $existing->verification_status === Store::VERIFICATION_APPROVED) {
            return response()->json([
                'success' => false,
                'message' => 'This account already has an approved pharmacy. Sign in to your dashboard to manage it.',
                'code' => 'already_approved',
            ], 422);
        }

        $validated = $request->validate([
            // The pharmacy itself.
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'phone' => 'required|string|max:20',
            'email' => 'required|email|max:255',
            'state' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'address' => 'required|string|max:500',
            // The licence: the document, and nothing else. See register().
            'license_document' => 'required|file|mimes:jpg,jpeg,png,webp,pdf|max:10240',
        ]);

        // Confidential, and never publicly served.
        $documentPath = $request->file('license_document')->store('licenses', 'local');

        $store = DB::transaction(function () use ($validated, $documentPath, $user, $existing) {
            $attributes = array_merge(
                collect($validated)->except('license_document')->toArray(),
                [
                    'pharmacy_license_document' => $documentPath,
                    'commission_rate' => SystemSetting::defaultCommissionRate(),
                    'verification_status' => Store::VERIFICATION_PENDING,
                    'verification_notes' => null,
                    // Nothing is granted by applying.
                    'can_sell_prescription' => false,
                    'can_sell_controlled' => false,
                    'verified_at' => null,
                    'verified_by' => null,
                    'licence_reminder_stage' => null,
                    // Not a shop yet. `sellable()` needs active *and* approved,
                    // so this is the second of two independent locks.
                    'status' => 'inactive',
                ]
            );

            if ($existing) {
                $existing->update($attributes);

                return $existing->fresh();
            }

            $store = new Store($attributes);
            $store->owner_id = $user->id;
            $store->slug = $store->generateSlug();
            $store->save();

            return $store;
        });

        // Put it in front of a reviewer. register() has always done this and
        // this path never did, so the two ways of reaching the same queue
        // behaved differently: a signed-in customer applying, and an
        // admin-created owner setting their shop up in the dashboard, both
        // landed silently. The applicant is told we will email them either way,
        // and nothing was telling anybody there was a decision to make.
        //
        // Best-effort, exactly as register() treats it: a mail outage must not
        // fail an application whose licence is already saved. PlatformAdmins
        // logs and swallows a failed send.
        \App\Services\AdminAlerts::pharmacyApplied($store->fresh(), resubmission: (bool) $existing);

        return response()->json([
            'success' => true,
            'message' => 'Thank you. Your pharmacy licence is with our team for review, and we will '
                .'email '.$store->email.' either way.',
            'data' => $this->present($store),
        ], 201);
    }

    /**
     * The applicant's own store, whether or not they have been promoted yet.
     */
    private function applicationFor(Request $request): ?Store
    {
        $user = $request->user();

        if (! $user) {
            return null;
        }

        return Store::where('owner_id', $user->id)->first();
    }

    /**
     * Deliberately narrow: an applicant is outside the dashboard, so this says
     * where they stand and nothing more. No licence document, no internal notes
     * beyond the reason they were given.
     */
    private function present(Store $store): array
    {
        return [
            'has_applied' => true,
            'store_name' => $store->name,
            'status' => $store->verification_status,
            'submitted_at' => $store->updated_at?->toIso8601String(),
            'approved' => $store->verification_status === Store::VERIFICATION_APPROVED,
            'rejection_reason' => $store->verification_status === Store::VERIFICATION_REJECTED
                ? $store->verification_notes
                : null,
            'can_resubmit' => in_array($store->verification_status, [
                Store::VERIFICATION_REJECTED,
                Store::VERIFICATION_UNSUBMITTED,
            ], true),
            'dashboard_url' => $store->verification_status === Store::VERIFICATION_APPROVED
                ? rtrim((string) config('app.admin_url', config('app.frontend_url')), '/')
                : null,
            // Echoed back so a rejected applicant does not retype everything.
            // Only what they actually filled in — the licence details are the
            // reviewer's record of the document, not the applicant's input.
            'submitted' => [
                'name' => $store->name,
                'description' => $store->description,
                'phone' => $store->phone,
                'email' => $store->email,
                'state' => $store->state,
                'city' => $store->city,
                'address' => $store->address,
            ],
        ];
    }

    /**
     * Approval is the moment a pharmacy becomes a pharmacy: the shop opens and
     * the owner is let into the dashboard. Called from the admin review so the
     * two can never drift apart.
     *
     * Idempotent — a store re-approved after a lapsed licence must not be
     * demoted and re-promoted, and an owner who is already an admin keeps their
     * own role.
     */
    public static function grantDashboardAccess(Store $store): void
    {
        $store->update(['status' => 'active']);

        $owner = $store->owner;

        if (! $owner || $owner->role === 'admin') {
            return;
        }

        $role = Role::where('name', 'store_owner')->first();

        if (! $role) {
            // Without the role row the dashboard login gate would turn them
            // away, so this is worth shouting about rather than swallowing.
            \Log::error('store_owner role is missing; approved pharmacy cannot sign in', [
                'store_id' => $store->id,
                'owner_id' => $owner->id,
            ]);
        }

        $owner->update([
            'role' => 'store_owner',
            'role_id' => $role?->id ?? $owner->role_id,
            'store_id' => $owner->store_id ?: $store->id,
        ]);
    }
}
