<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Support\PharmacyPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The pharmacy business-policy settings: admin management, and a read-only
 * view for the pharmacies those settings govern.
 *
 * Only the tunable policy knobs are exposed here. The safety and legal invariants
 * documented in App\Support\PharmacyPolicy are not settings and cannot be changed
 * through this endpoint.
 *
 * The read-only half exists because every one of these rules is enforced against
 * the pharmacy, not against the platform. Minimum shelf life decides which of
 * their stock is sellable; prescription validity decides when a customer's
 * prescription stops covering their medicines. A store that cannot see the
 * numbers finds out what they are by having a sale refused.
 */
class PharmacyPolicyController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'policy' => PharmacyPolicy::all(),
                // Surfaced so the admin UI can show operators what is enforced
                // unconditionally, rather than leaving it invisible.
                'enforced_always' => $this->enforcedAlways(),
            ],
        ]);
    }

    /**
     * The same policy, for a pharmacy, with no way to change it.
     *
     * Deliberately a separate method rather than opening show() to store
     * accounts. It answers a different question — not "what are the platform's
     * settings" but "what applies to me" — and so it carries the store's own
     * standing alongside the numbers: whether it may sell prescription or
     * controlled medicines today, and when its licence runs out. Those are the
     * facts that make the shelf-life and validity figures actionable rather
     * than trivia.
     *
     * Mounted outside the /stores/{slug} prefix on purpose. That prefix is a
     * public wildcard route carrying a hand-maintained negative-lookahead list
     * of every non-slug path under it, and adding a sixth entry to that regex
     * is one more thing to forget.
     */
    public function forStore(Request $request): JsonResponse
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
            'data' => [
                'policy' => PharmacyPolicy::all(),
                'enforced_always' => $this->enforcedAlways(),
                // Where this store stands against the rules above.
                'store' => [
                    'name' => $store->name,
                    'verification_status' => $store->verification_status,
                    'can_sell_prescription' => (bool) $store->can_sell_prescription,
                    'can_sell_controlled' => (bool) $store->can_sell_controlled,
                    'licence_expiry' => $store->pharmacy_license_expiry?->toDateString(),
                ],
            ],
        ]);
    }

    /**
     * The store behind the calling account: its owner, or the staff they hired.
     */
    private function resolveStore(Request $request): ?\App\Models\Store
    {
        $user = $request->user();

        if (! $user) {
            return null;
        }

        if ($user->store_id) {
            return \App\Models\Store::find($user->store_id);
        }

        return \App\Models\Store::where('owner_id', $user->id)->first();
    }

    /**
     * The invariants, in one place.
     *
     * Both audiences are shown the same list, and a rule that was added for one
     * and not the other would be exactly the kind of divergence nobody notices
     * until a pharmacy is surprised by an enforcement it was never told about.
     */
    private function enforcedAlways(): array
    {
        return [
            'Expired stock can never be sold.',
            'Controlled substances always require explicit, separate permission.',
            'A lapsed pharmacy licence revokes regulated-selling permission.',
            'Orders with prescription items cannot ship until every prescription is approved.',
            'Prescription files are stored privately and served only to authorised users.',
        ];
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Capped at ~2 years: a larger value would silently make most stock
            // unsellable, which is far more likely to be a typo than an intent.
            'min_shelf_life_days' => 'sometimes|integer|min:0|max:730',
            'prescription_validity_days' => 'sometimes|integer|min:1|max:1095',
            'stock_expiry_warning_days' => 'sometimes|integer|min:1|max:365',
            'grant_prescription_on_approval' => 'sometimes|boolean',
            'allow_admin_prescription_override' => 'sometimes|boolean',
        ]);

        $types = [
            'min_shelf_life_days' => SystemSetting::TYPE_NUMBER,
            'prescription_validity_days' => SystemSetting::TYPE_NUMBER,
            'stock_expiry_warning_days' => SystemSetting::TYPE_NUMBER,
            'grant_prescription_on_approval' => SystemSetting::TYPE_BOOLEAN,
            'allow_admin_prescription_override' => SystemSetting::TYPE_BOOLEAN,
        ];

        foreach ($validated as $key => $value) {
            SystemSetting::setValue(
                PharmacyPolicy::CATEGORY,
                $key,
                $value,
                null,
                null,
                $types[$key]
            );
        }

        \Log::info('Pharmacy policy updated', [
            'admin_id' => $request->user()?->id,
            'changes' => $validated,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Pharmacy policy updated successfully',
            'data' => PharmacyPolicy::all(),
        ]);
    }
}
