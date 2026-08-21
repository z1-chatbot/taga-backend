<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShippingZone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;

class ShippingZoneController extends Controller
{
    /**
     * Get all shipping zones
     */
    public function index(Request $request): JsonResponse
    {
        $query = ShippingZone::query();

        if ($request->has('state')) {
            $query->where('state', $request->state);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $zones = $query->orderBy('state')->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data' => $zones
        ]);
    }

    /**
     * Create shipping zone
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'origin_state' => 'nullable|string|max:255',
            'state' => 'required|string|max:255',
            'type' => 'nullable|in:intrastate,interstate,any',
            'cities' => 'nullable|array',
            'postal_codes' => 'nullable|array',
            'shipping_fee' => 'required|numeric|min:0',
            'estimated_delivery_days' => 'nullable|integer|min:1',
            'estimated_days' => 'nullable|integer|min:1',
            'is_active' => 'boolean',
            'is_free_shipping' => 'boolean'
        ]);

        // Handle estimated_days vs estimated_delivery_days
        if (isset($validated['estimated_days']) && !isset($validated['estimated_delivery_days'])) {
            $validated['estimated_delivery_days'] = $validated['estimated_days'];
            unset($validated['estimated_days']);
        }

        $zone = ShippingZone::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Shipping zone created successfully',
            'data' => $zone
        ], 201);
    }

    /**
     * Update shipping zone
     */
    public function update(Request $request, $id): JsonResponse
    {
        $zone = ShippingZone::find($id);

        if (!$zone) {
            return response()->json([
                'success' => false,
                'message' => 'Shipping zone not found'
            ], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'origin_state' => 'nullable|string|max:255',
            'state' => 'sometimes|string|max:255',
            'type' => 'nullable|in:intrastate,interstate,any',
            'cities' => 'nullable|array',
            'postal_codes' => 'nullable|array',
            'shipping_fee' => 'sometimes|numeric|min:0',
            'estimated_delivery_days' => 'nullable|integer|min:1',
            'estimated_days' => 'nullable|integer|min:1',
            'is_active' => 'boolean',
            'is_free_shipping' => 'boolean'
        ]);

        // Handle estimated_days vs estimated_delivery_days
        if (isset($validated['estimated_days']) && !isset($validated['estimated_delivery_days'])) {
            $validated['estimated_delivery_days'] = $validated['estimated_days'];
            unset($validated['estimated_days']);
        }

        $zone->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Shipping zone updated successfully',
            'data' => $zone
        ]);
    }

    /**
     * Delete shipping zone
     */
    public function destroy($id): JsonResponse
    {
        $zone = ShippingZone::find($id);

        if (!$zone) {
            return response()->json([
                'success' => false,
                'message' => 'Shipping zone not found'
            ], 404);
        }

        $zone->delete();

        return response()->json([
            'success' => true,
            'message' => 'Shipping zone deleted successfully'
        ]);
    }

    /**
     * Every state Taga can deliver to.
     */
    public const NIGERIAN_STATES = [
        'Abia', 'Adamawa', 'Akwa Ibom', 'Anambra', 'Bauchi', 'Bayelsa', 'Benue', 'Borno',
        'Cross River', 'Delta', 'Ebonyi', 'Edo', 'Ekiti', 'Enugu', 'Gombe', 'Imo',
        'Jigawa', 'Kaduna', 'Kano', 'Katsina', 'Kebbi', 'Kogi', 'Kwara', 'Lagos',
        'Nasarawa', 'Niger', 'Ogun', 'Ondo', 'Osun', 'Oyo', 'Plateau', 'Rivers',
        'Sokoto', 'Taraba', 'Yobe', 'Zamfara', 'FCT',
    ];

    /**
     * Lay down a whole delivery map from one origin in a single action.
     *
     * Checkout refuses any route no zone covers, so a pharmacy in Lagos that can
     * ship nationwide needs 37 zones before it can sell to the whole country.
     * Creating those one form at a time is why this database still had none.
     *
     * Re-running is safe: an existing zone for the same route is updated rather
     * than duplicated, so this doubles as a way to reprice a map.
     */
    public function bulkStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'origin_state' => 'required|string|max:255',
            'intrastate_fee' => 'required|numeric|min:0',
            'interstate_fee' => 'required|numeric|min:0',
            'intrastate_days' => 'nullable|integer|min:1|max:60',
            'interstate_days' => 'nullable|integer|min:1|max:60',
            // Leave empty to cover the whole country, or name the states served.
            'destination_states' => 'nullable|array',
            'destination_states.*' => 'string|max:255',
            'is_active' => 'boolean',
        ]);

        $origin = $validated['origin_state'];
        // Absent means the whole country; validate() omits the key entirely
        // rather than passing null.
        $destinations = ! empty($validated['destination_states'])
            ? $validated['destination_states']
            : self::NIGERIAN_STATES;

        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($validated, $origin, $destinations, &$created, &$updated) {
            foreach ($destinations as $destination) {
                $isIntrastate = strcasecmp($origin, $destination) === 0;

                $attributes = [
                    'name' => "{$origin} to {$destination}",
                    'type' => $isIntrastate ? 'intrastate' : 'interstate',
                    'shipping_fee' => $isIntrastate
                        ? $validated['intrastate_fee']
                        : $validated['interstate_fee'],
                    'estimated_delivery_days' => $isIntrastate
                        ? ($validated['intrastate_days'] ?? 2)
                        : ($validated['interstate_days'] ?? 5),
                    'is_active' => $validated['is_active'] ?? true,
                    'is_free_shipping' => false,
                ];

                // Match on the route, case-insensitively — the same lookup
                // findByRoute() uses, so this cannot create a zone the checkout
                // will not find.
                $existing = ShippingZone::whereRaw('LOWER(origin_state) = ?', [strtolower($origin)])
                    ->whereRaw('LOWER(state) = ?', [strtolower($destination)])
                    ->first();

                if ($existing) {
                    $existing->update($attributes);
                    $updated++;
                } else {
                    ShippingZone::create($attributes + [
                        'origin_state' => $origin,
                        'state' => $destination,
                    ]);
                    $created++;
                }
            }
        });

        return response()->json([
            'success' => true,
            'message' => "Delivery map saved for {$origin}: {$created} route(s) added, {$updated} updated.",
            'data' => [
                'origin_state' => $origin,
                'created' => $created,
                'updated' => $updated,
                'total_routes' => count($destinations),
            ],
        ], 201);
    }

    /**
     * Calculate shipping fee
     */
    public function calculateFee(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'state' => 'required|string',
            'city' => 'nullable|string',
            'postal_code' => 'nullable|string'
        ]);

        $zone = ShippingZone::findByLocation(
            $validated['state'],
            $validated['city'] ?? null,
            $validated['postal_code'] ?? null
        );

        if (!$zone) {
            return response()->json([
                'success' => false,
                'message' => 'No shipping zone found for this location'
            ], 404);
        }

        $fee = $zone->calculateShippingFee();

        return response()->json([
            'success' => true,
            'data' => [
                'zone' => $zone->name,
                'fee' => $fee,
                'days' => $zone->estimated_delivery_days
            ]
        ]);
    }

    /**
     * Debug: Get all zones
     */
    public function debugZones(): JsonResponse
    {
        $zones = ShippingZone::select('id', 'name', 'state', 'cities', 'is_active')
            ->get()
            ->map(function($zone) {
                return [
                    'id' => $zone->id,
                    'name' => $zone->name,
                    'state' => $zone->state,
                    'cities' => $zone->cities,
                    'is_active' => $zone->is_active
                ];
            });

        return response()->json([
            'success' => true,
            'total' => $zones->count(),
            'data' => $zones
        ]);
    }

    /**
     * Get available cities for a state
     */
    public function getCitiesForState(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'state' => 'required|string',
            'origin_state' => 'nullable|string'
        ]);

        $state = $validated['state'];
        $originState = $validated['origin_state'] ?? null;

        \Log::info('getCitiesForState called', [
            'state' => $state,
            'origin_state' => $originState
        ]);

        // Find all active zones for this destination state (case-insensitive)
        $query = ShippingZone::whereRaw('LOWER(state) = ?', [strtolower($state)])
            ->where('is_active', true);

        // If origin state is provided, filter by route
        if ($originState) {
            $query->where(function($q) use ($originState) {
                $q->whereRaw('LOWER(origin_state) = ?', [strtolower($originState)])
                  ->orWhereNull('origin_state');
            });
        }

        $zones = $query->get();
        
        \Log::info('Zones found', [
            'count' => $zones->count(),
            'zones' => $zones->pluck('name', 'id')->toArray()
        ]);

        if ($zones->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => 'No shipping zones found for this state',
                'data' => [
                    'state' => $state,
                    'cities' => [],
                    'covers_all_cities' => false,
                    'message' => "We don't currently deliver to {$state}"
                ]
            ]);
        }

        // Collect all cities from all zones
        $allCities = [];
        $hasZoneWithoutCities = false;

        foreach ($zones as $zone) {
            if (empty($zone->cities) || count($zone->cities) === 0) {
                // Zone covers all cities in the state
                $hasZoneWithoutCities = true;
                break;
            }
            $allCities = array_merge($allCities, $zone->cities);
        }

        // Remove duplicates and sort
        $allCities = array_unique($allCities);
        sort($allCities);

        return response()->json([
            'success' => true,
            'data' => [
                'state' => $state,
                'cities' => array_values($allCities),
                'covers_all_cities' => $hasZoneWithoutCities,
                'message' => $hasZoneWithoutCities 
                    ? "We deliver to all cities in {$state}" 
                    : "We deliver to " . count($allCities) . " cities in {$state}"
            ]
        ]);
    }

    /**
     * Get suggested shipping fee for a route based on delivery company rates.
     * Returns the highest base_rate among all active delivery companies for the route,
     * so the platform doesn't set a fee lower than what it pays any company.
     */
    public function getSuggestedRate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'origin_state' => 'required|string',
            'destination_state' => 'required|string',
        ]);

        $origin = $validated['origin_state'];
        $destination = $validated['destination_state'];

        // Find all active shipping rates for this route from all companies
        $rates = \App\Models\ShippingRate::active()
            ->where(function ($q) use ($origin, $destination) {
                $q->where(function ($q2) use ($origin, $destination) {
                    $q2->whereRaw('LOWER(from_state) = ?', [strtolower($origin)])
                       ->whereRaw('LOWER(to_state) = ?', [strtolower($destination)]);
                })->orWhere(function ($q2) use ($origin, $destination) {
                    // Also check reverse route
                    $q2->whereRaw('LOWER(from_state) = ?', [strtolower($destination)])
                       ->whereRaw('LOWER(to_state) = ?', [strtolower($origin)]);
                });
            })
            ->with('logisticsCompany:id,name')
            ->get();

        if ($rates->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'suggested_fee' => null,
                    'highest_rate' => null,
                    'rates' => [],
                    'message' => 'No delivery company rates found for this route.',
                ]
            ]);
        }

        // Build breakdown per company
        $breakdown = $rates->map(function ($rate) {
            return [
                'company_id' => $rate->logistics_company_id,
                'company_name' => $rate->logisticsCompany->name ?? 'Global',
                'base_rate' => (float) $rate->base_rate,
                'per_kg_rate' => (float) $rate->per_kg_rate,
                'estimated_days' => $rate->getEstimatedDeliveryRange(),
            ];
        });

        $highestBaseRate = $rates->max('base_rate');

        return response()->json([
            'success' => true,
            'data' => [
                'suggested_fee' => (float) $highestBaseRate,
                'highest_rate' => (float) $highestBaseRate,
                'rates' => $breakdown,
                'message' => "Suggested minimum fee: ₦" . number_format($highestBaseRate) . " (highest delivery company rate for this route). Setting your fee below this may result in a loss.",
            ]
        ]);
    }
}
