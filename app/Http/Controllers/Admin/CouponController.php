<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ScopesToStore;
use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class CouponController extends Controller
{
    use ScopesToStore;

    /**
     * Get all coupons with pagination
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Coupon::with('store');

        // A shop sees only its own coupons — keyed on the store they belong to,
        // not on their role's name.
        if ($user && ! $user->isPlatformAdmin()) {
            $storeId = $user->storeScopeId();

            $storeId === null
                ? $query->whereRaw('1 = 0')
                : $query->byStore($storeId);
        }
        // Filter by scope (platform-wide or store-specific) for admins
        elseif ($request->has('scope')) {
            if ($request->scope === 'platform') {
                $query->platformWide();
            } elseif ($request->scope === 'store' && $request->has('store_id')) {
                $query->byStore($request->store_id);
            }
        }

        // Apply filters
        if ($request->has('status')) {
            if ($request->status === 'active') {
                $query->active();
            } elseif ($request->status === 'inactive') {
                $query->where('is_active', false);
            } elseif ($request->status === 'expired') {
                $query->where('valid_until', '<', now());
            }
        }

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('search')) {
            $query->where(function($q) use ($request) {
                $q->where('code', 'like', '%' . $request->search . '%')
                  ->orWhere('name', 'like', '%' . $request->search . '%');
            });
        }

        $coupons = $query->orderBy('created_at', 'desc')
                        ->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $coupons
        ]);
    }

    /**
     * Create new coupon
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|max:50|unique:coupons,code',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => ['required', Rule::in([Coupon::TYPE_PERCENTAGE, Coupon::TYPE_FIXED_AMOUNT, Coupon::TYPE_FREE_SHIPPING])],
            'value' => 'required|numeric|min:0',
            'minimum_amount' => 'nullable|numeric|min:0',
            'maximum_discount' => 'nullable|numeric|min:0',
            'usage_limit' => 'nullable|integer|min:1',
            'user_limit' => 'nullable|integer|min:1',
            'valid_from' => 'nullable|date',
            'valid_until' => 'nullable|date|after_or_equal:valid_from',
            'applicable_to' => ['required', Rule::in([Coupon::APPLICABLE_ALL, Coupon::APPLICABLE_PRODUCTS, Coupon::APPLICABLE_CATEGORIES])],
            'applicable_ids' => 'nullable|array',
            'exclude_sale_items' => 'boolean',
            'first_order_only' => 'boolean',
            'auto_apply' => 'boolean'
        ]);

        // Validate percentage value
        if ($request->type === Coupon::TYPE_PERCENTAGE && $request->value > 100) {
            return response()->json([
                'success' => false,
                'message' => 'Percentage discount cannot exceed 100%'
            ], 422);
        }

        // Validate applicable IDs
        if ($request->applicable_to !== Coupon::APPLICABLE_ALL && empty($request->applicable_ids)) {
            return response()->json([
                'success' => false,
                'message' => 'Please select applicable products or categories'
            ], 422);
        }

        $user = $request->user();
        
        // A shop's coupon belongs to that shop, whatever it asked for. Only a
        // platform admin may choose, including a platform-wide coupon.
        $storeId = $user && $user->isPlatformAdmin()
            ? ($request->store_id ?? null)
            : $user?->storeScopeId();

        if ($user && ! $user->isPlatformAdmin() && $storeId === null) {
            return response()->json([
                'success' => false,
                'message' => 'Your account is not linked to a store, so it cannot create coupons.',
                'code' => 'no_store_scope',
            ], 403);
        }


        $coupon = Coupon::create([
            'store_id' => $storeId,
            'code' => strtoupper($request->code),
            'name' => $request->name,
            'description' => $request->description,
            'type' => $request->type,
            'value' => $request->value,
            'minimum_amount' => $request->minimum_amount,
            'maximum_discount' => $request->maximum_discount,
            'usage_limit' => $request->usage_limit,
            'user_limit' => $request->user_limit,
            'valid_from' => $request->valid_from,
            'valid_until' => $request->valid_until,
            'is_active' => $request->get('is_active', true),
            'applicable_to' => $request->applicable_to,
            'applicable_ids' => $request->applicable_ids,
            'exclude_sale_items' => $request->get('exclude_sale_items', false),
            'first_order_only' => $request->get('first_order_only', false),
            'auto_apply' => $request->get('auto_apply', false)
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Coupon created successfully',
            'data' => $coupon
        ], 201);
    }

    /**
     * Get single coupon
     */
    public function show(Request $request, $id): JsonResponse
    {
        $coupon = Coupon::with(['orders', 'usages.user'])->find($id);

        if (!$coupon) {
            return response()->json([
                'success' => false,
                'message' => 'Coupon not found'
            ], 404);
        }

        // One check for every store role, not just the two named ones.
        if (! $this->callerOwnsStore($request, $coupon->store_id)) {
            return $this->notFoundForCaller('Coupon');
        }

        // Add usage statistics
        $coupon->usage_stats = [
            'total_used' => $coupon->used_count,
            'total_revenue' => $coupon->orders()->where('payment_status', 'paid')->sum('coupon_discount'),
            'unique_users' => $coupon->usages()->distinct('user_id')->count(),
            'recent_uses' => $coupon->usages()->with('user')->latest()->limit(10)->get()
        ];

        return response()->json([
            'success' => true,
            'data' => $coupon
        ]);
    }

    /**
     * Update coupon
     */
    public function update(Request $request, $id): JsonResponse
    {
        $user = $request->user();
        $coupon = Coupon::find($id);

        if (!$coupon) {
            return response()->json([
                'success' => false,
                'message' => 'Coupon not found'
            ], 404);
        }

        // One check for every store role, not just the two named ones.
        if (! $this->callerOwnsStore($request, $coupon->store_id)) {
            return $this->notFoundForCaller('Coupon');
        }

        $request->validate([
            'code' => 'required|string|max:50|unique:coupons,code,' . $id,
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => ['required', Rule::in([Coupon::TYPE_PERCENTAGE, Coupon::TYPE_FIXED_AMOUNT, Coupon::TYPE_FREE_SHIPPING])],
            'value' => 'required|numeric|min:0',
            'minimum_amount' => 'nullable|numeric|min:0',
            'maximum_discount' => 'nullable|numeric|min:0',
            'usage_limit' => 'nullable|integer|min:1',
            'user_limit' => 'nullable|integer|min:1',
            'valid_from' => 'nullable|date',
            'valid_until' => 'nullable|date|after_or_equal:valid_from',
            'is_active' => 'boolean',
            'applicable_to' => ['required', Rule::in([Coupon::APPLICABLE_ALL, Coupon::APPLICABLE_PRODUCTS, Coupon::APPLICABLE_CATEGORIES])],
            'applicable_ids' => 'nullable|array',
            'exclude_sale_items' => 'boolean',
            'first_order_only' => 'boolean',
            'auto_apply' => 'boolean'
        ]);

        $coupon->update([
            'code' => strtoupper($request->code),
            'name' => $request->name,
            'description' => $request->description,
            'type' => $request->type,
            'value' => $request->value,
            'minimum_amount' => $request->minimum_amount,
            'maximum_discount' => $request->maximum_discount,
            'usage_limit' => $request->usage_limit,
            'user_limit' => $request->user_limit,
            'valid_from' => $request->valid_from,
            'valid_until' => $request->valid_until,
            'is_active' => $request->get('is_active', true),
            'applicable_to' => $request->applicable_to,
            'applicable_ids' => $request->applicable_ids,
            'exclude_sale_items' => $request->get('exclude_sale_items', false),
            'first_order_only' => $request->get('first_order_only', false),
            'auto_apply' => $request->get('auto_apply', false)
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Coupon updated successfully',
            'data' => $coupon
        ]);
    }

    /**
     * Toggle coupon active status
     */
    public function toggleStatus($id): JsonResponse
    {
        $coupon = Coupon::find($id);

        if (!$coupon) {
            return response()->json([
                'success' => false,
                'message' => 'Coupon not found'
            ], 404);
        }

        $coupon->update(['is_active' => !$coupon->is_active]);

        return response()->json([
            'success' => true,
            'message' => 'Coupon status updated successfully',
            'data' => $coupon
        ]);
    }

    /**
     * Delete coupon
     */
    public function destroy($id): JsonResponse
    {
        $user = request()->user();
        $coupon = Coupon::find($id);

        if (!$coupon) {
            return response()->json([
                'success' => false,
                'message' => 'Coupon not found'
            ], 404);
        }

        // One check for every store role, not just the two named ones.
        if (! $this->callerOwnsStore($request, $coupon->store_id)) {
            return $this->notFoundForCaller('Coupon');
        }

        // Check if coupon has been used
        if ($coupon->used_count > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete coupon that has been used. Deactivate it instead.'
            ], 422);
        }

        $coupon->delete();

        return response()->json([
            'success' => true,
            'message' => 'Coupon deleted successfully'
        ]);
    }

    /**
     * Get coupon options for forms
     */
    public function getOptions(): JsonResponse
    {
        // Categories are real rows, not a settings list. The old
        // `product_attributes.product_categories` key is deleted by
        // SystemSettingsSeeder on every run, so reading it left this picker
        // permanently empty.
        //
        // `id` carries the SLUG, not the primary key. Everything that consumes
        // this picker stores the chosen value and later matches it against
        // Product::$product_category, which is an accessor for the category's
        // slug -- see Coupon::196, SaleEvent::123 and PricingConfiguration.
        // Handing out numeric ids here saves rows that can never match a
        // product, and the failure is silent: the coupon simply never applies.
        $categories = \App\Models\Category::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'slug'])
            ->map(fn ($category) => [
                'id' => $category->slug,
                'name' => $category->name,
            ])
            ->all();
        
        // Filter products based on user role
        $user = auth()->user();
        $productsQuery = Product::active();
        
        // Only the caller's own products can be attached to their coupon.
        if ($user && ! $user->isPlatformAdmin()) {
            $storeId = $user->storeScopeId();

            $storeId === null
                ? $productsQuery->whereRaw('1 = 0')
                : $productsQuery->where('store_id', $storeId);
        }


        $products = $productsQuery->with('category')->get(['id', 'name', 'price', 'category_id']);

        // Get dynamic coupon types from system settings
        $couponTypes = \App\Models\SystemSetting::getCouponTypes();
        $typesArray = [];
        foreach ($couponTypes as $key => $typeData) {
            if (is_array($typeData) && isset($typeData['value'], $typeData['label'])) {
                $typesArray[] = $typeData;
            } else {
                // Fallback for simple string values
                $typesArray[] = ['value' => $key, 'label' => ucwords(str_replace('_', ' ', $key))];
            }
        }

        // Fallback to hardcoded values if no settings found
        if (empty($typesArray)) {
            $typesArray = [
                ['value' => Coupon::TYPE_PERCENTAGE, 'label' => 'Percentage Discount'],
                ['value' => Coupon::TYPE_FIXED_AMOUNT, 'label' => 'Fixed Amount Discount'],
                ['value' => Coupon::TYPE_FREE_SHIPPING, 'label' => 'Free Shipping']
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'types' => $typesArray,
                'applicable_to' => [
                    ['value' => Coupon::APPLICABLE_ALL, 'label' => 'All Products'],
                    ['value' => Coupon::APPLICABLE_PRODUCTS, 'label' => 'Specific Products'],
                    ['value' => Coupon::APPLICABLE_CATEGORIES, 'label' => 'Specific Categories']
                ],
                'categories' => $categories,
                'products' => $products
            ]
        ]);
    }

    /**
     * Create special event coupons
     */
    public function createSpecialEvent(Request $request): JsonResponse
    {
        $request->validate([
            'event_type' => 'required|in:black_friday,cyber_monday,new_year,valentines,mothers_day,custom',
            'discount_value' => 'required|numeric|min:1|max:90',
            'duration_days' => 'required|integer|min:1|max:30'
        ]);

        $eventType = $request->event_type;
        $discountValue = $request->discount_value;
        $durationDays = $request->duration_days;

        switch ($eventType) {
            case 'black_friday':
                $coupon = Coupon::create([
                    'code' => 'BLACKFRIDAY' . date('Y'),
                    'name' => 'Black Friday Sale ' . date('Y'),
                    'description' => "Massive Black Friday discount - {$discountValue}% off everything!",
                    'type' => Coupon::TYPE_PERCENTAGE,
                    'value' => $discountValue,
                    'minimum_amount' => 50,
                    'valid_from' => now(),
                    'valid_until' => now()->addDays($durationDays),
                    'is_active' => true,
                    'applicable_to' => Coupon::APPLICABLE_ALL,
                    'auto_apply' => true
                ]);
                break;

            case 'cyber_monday':
                $coupon = Coupon::create([
                    'code' => 'CYBERMONDAY' . date('Y'),
                    'name' => 'Cyber Monday Sale ' . date('Y'),
                    'description' => "Cyber Monday special - {$discountValue}% off all online orders!",
                    'type' => Coupon::TYPE_PERCENTAGE,
                    'value' => $discountValue,
                    'minimum_amount' => 75,
                    'valid_from' => now(),
                    'valid_until' => now()->addDays($durationDays),
                    'is_active' => true,
                    'applicable_to' => Coupon::APPLICABLE_ALL,
                    'auto_apply' => true
                ]);
                break;

            default:
                return response()->json([
                    'success' => false,
                    'message' => 'Event type not implemented yet'
                ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Special event coupon created successfully',
            'data' => $coupon
        ]);
    }
}
