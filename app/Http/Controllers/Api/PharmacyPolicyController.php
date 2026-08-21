<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Support\PharmacyPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin management of the pharmacy business-policy settings.
 *
 * Only the tunable policy knobs are exposed here. The safety and legal invariants
 * documented in App\Support\PharmacyPolicy are not settings and cannot be changed
 * through this endpoint.
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
                'enforced_always' => [
                    'Expired stock can never be sold.',
                    'Controlled substances always require explicit, separate permission.',
                    'A lapsed pharmacy licence revokes regulated-selling permission.',
                    'Orders with prescription items cannot ship until every prescription is approved.',
                    'Prescription files are stored privately and served only to authorised users.',
                ],
            ],
        ]);
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
