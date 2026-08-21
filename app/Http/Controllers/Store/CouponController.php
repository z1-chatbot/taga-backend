<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class CouponController extends Controller
{
    /**
     * Get store owner's coupons
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        $store = Store::where('owner_id', $user->id)->first();

        if (!$store) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found'
            ], 404);
        }

        $query = Coupon::byStore($store->id)->with('usages');

        // Filters
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        $coupons = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $coupons
        ]);
    }

    /**
     * Create store coupon
     */
    public function store(Request $request): JsonResponse
    {
        $user = Auth::user();
        $store = Store::where('owner_id', $user->id)->first();

        if (!$store) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found'
            ], 404);
        }

        if (!$store->is_verified) {
            return response()->json([
                'success' => false,
                'message' => 'Store must be verified to create coupons'
            ], 403);
        }

        $validated = $request->validate([
            'code' => 'required|string|max:50|unique:coupons,code',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|in:percentage,fixed_amount,free_shipping',
            'value' => 'required|numeric|min:0',
            'minimum_amount' => 'nullable|numeric|min:0',
            'maximum_discount' => 'nullable|numeric|min:0',
            'usage_limit' => 'nullable|integer|min:1',
            'user_limit' => 'nullable|integer|min:1',
            'valid_from' => 'required|date',
            'valid_until' => 'required|date|after:valid_from',
            'applicable_to' => 'required|in:all,specific_products,specific_categories',
            'applicable_ids' => 'nullable|array',
            'exclude_sale_items' => 'boolean',
            'first_order_only' => 'boolean'
        ]);

        // Ensure coupon only applies to store's products
        if ($validated['applicable_to'] === 'specific_products') {
            $storeProductIds = $store->products()->pluck('id')->toArray();
            $requestedIds = $validated['applicable_ids'] ?? [];
            
            // Validate that all product IDs belong to this store
            $invalidIds = array_diff($requestedIds, $storeProductIds);
            if (!empty($invalidIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Some products do not belong to your store'
                ], 422);
            }
        }

        $validated['store_id'] = $store->id;
        $validated['code'] = strtoupper($validated['code']);
        $validated['is_active'] = true;
        $validated['auto_apply'] = false; // Store coupons don't auto-apply

        $coupon = Coupon::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Coupon created successfully',
            'data' => $coupon
        ], 201);
    }

    /**
     * Update store coupon
     */
    public function update(Request $request, $id): JsonResponse
    {
        $user = Auth::user();
        $store = Store::where('owner_id', $user->id)->first();

        if (!$store) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found'
            ], 404);
        }

        $coupon = Coupon::where('id', $id)
                       ->where('store_id', $store->id)
                       ->first();

        if (!$coupon) {
            return response()->json([
                'success' => false,
                'message' => 'Coupon not found or does not belong to your store'
            ], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'value' => 'sometimes|numeric|min:0',
            'minimum_amount' => 'nullable|numeric|min:0',
            'maximum_discount' => 'nullable|numeric|min:0',
            'usage_limit' => 'nullable|integer|min:1',
            'user_limit' => 'nullable|integer|min:1',
            'valid_from' => 'sometimes|date',
            'valid_until' => 'sometimes|date|after:valid_from',
            'applicable_to' => 'sometimes|in:all,specific_products,specific_categories',
            'applicable_ids' => 'nullable|array',
            'is_active' => 'boolean'
        ]);

        $coupon->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Coupon updated successfully',
            'data' => $coupon
        ]);
    }

    /**
     * Delete store coupon
     */
    public function destroy($id): JsonResponse
    {
        $user = Auth::user();
        $store = Store::where('owner_id', $user->id)->first();

        if (!$store) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found'
            ], 404);
        }

        $coupon = Coupon::where('id', $id)
                       ->where('store_id', $store->id)
                       ->first();

        if (!$coupon) {
            return response()->json([
                'success' => false,
                'message' => 'Coupon not found or does not belong to your store'
            ], 404);
        }

        $coupon->delete();

        return response()->json([
            'success' => true,
            'message' => 'Coupon deleted successfully'
        ]);
    }

    /**
     * Toggle coupon status
     */
    public function toggleStatus($id): JsonResponse
    {
        $user = Auth::user();
        $store = Store::where('owner_id', $user->id)->first();

        if (!$store) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found'
            ], 404);
        }

        $coupon = Coupon::where('id', $id)
                       ->where('store_id', $store->id)
                       ->first();

        if (!$coupon) {
            return response()->json([
                'success' => false,
                'message' => 'Coupon not found or does not belong to your store'
            ], 404);
        }

        $coupon->update(['is_active' => !$coupon->is_active]);

        return response()->json([
            'success' => true,
            'message' => 'Coupon status updated',
            'data' => $coupon
        ]);
    }

    /**
     * Get coupon statistics
     */
    public function statistics($id): JsonResponse
    {
        $user = Auth::user();
        $store = Store::where('owner_id', $user->id)->first();

        if (!$store) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found'
            ], 404);
        }

        $coupon = Coupon::where('id', $id)
                       ->where('store_id', $store->id)
                       ->with(['usages', 'orders'])
                       ->first();

        if (!$coupon) {
            return response()->json([
                'success' => false,
                'message' => 'Coupon not found or does not belong to your store'
            ], 404);
        }

        $stats = [
            'total_uses' => $coupon->used_count,
            'unique_users' => $coupon->usages()->distinct('user_id')->count(),
            'total_discount_given' => $coupon->orders()->sum('coupon_discount'),
            'total_revenue' => $coupon->orders()->where('payment_status', 'paid')->sum('total_amount'),
            'recent_uses' => $coupon->usages()->with('user')->latest()->limit(10)->get()
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    /**
     * Get coupon options for forms (store owner version)
     */
    public function getOptions(): JsonResponse
    {
        $user = Auth::user();
        $store = Store::where('owner_id', $user->id)->first();

        if (!$store) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found'
            ], 404);
        }

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
        
        // Only get products from this store
        $products = \App\Models\Product::where('store_id', $store->id)
            ->where('is_active', true)
            ->with('category')
            ->get(['id', 'name', 'price', 'category_id']);

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
                ['value' => 'percentage', 'label' => 'Percentage Discount'],
                ['value' => 'fixed_amount', 'label' => 'Fixed Amount Discount'],
                ['value' => 'free_shipping', 'label' => 'Free Shipping']
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'types' => $typesArray,
                'applicable_to' => [
                    ['value' => 'all', 'label' => 'All Products'],
                    ['value' => 'specific_products', 'label' => 'Specific Products'],
                    ['value' => 'specific_categories', 'label' => 'Specific Categories']
                ],
                'categories' => $categories,
                'products' => $products
            ]
        ]);
    }
}
