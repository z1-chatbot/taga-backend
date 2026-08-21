<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SaleEvent;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class SaleEventController extends Controller
{
    /**
     * Get all sale events with pagination
     */
    public function index(Request $request): JsonResponse
    {
        $query = SaleEvent::query();

        // Apply filters
        if ($request->has('status')) {
            if ($request->status === 'active') {
                $query->active();
            } elseif ($request->status === 'inactive') {
                $query->where('is_active', false);
            } elseif ($request->status === 'upcoming') {
                $query->upcoming();
            } elseif ($request->status === 'expired') {
                $query->where('end_date', '<', now());
            }
        }

        if ($request->has('type')) {
            $query->byType($request->type);
        }

        if ($request->has('search')) {
            $query->where(function($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%')
                  ->orWhere('description', 'like', '%' . $request->search . '%');
            });
        }

        $saleEvents = $query->orderBy('priority', 'desc')
                           ->orderBy('created_at', 'desc')
                           ->paginate($request->get('per_page', 15));

        // Add computed fields
        $saleEvents->getCollection()->transform(function ($event) {
            $event->is_currently_active = $event->isCurrentlyActive();
            $event->time_remaining = $event->getTimeRemaining();
            return $event;
        });

        return response()->json([
            'success' => true,
            'data' => $saleEvents
        ]);
    }

    /**
     * Create new sale event
     */
    public function store(Request $request): JsonResponse
    {
        // Get valid sale event types from system settings
        $validTypes = \App\Models\SystemSetting::getSaleEventTypes();
        $allowedTypes = array_column($validTypes, 'key');

        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => ['required', Rule::in($allowedTypes)],
            'discount_type' => ['required', Rule::in([SaleEvent::DISCOUNT_PERCENTAGE, SaleEvent::DISCOUNT_FIXED])],
            'discount_value' => 'required|numeric|min:0',
            'start_date' => 'required|date|after_or_equal:now',
            'end_date' => 'required|date|after:start_date',
            'banner_image' => 'nullable|string',
            'banner_text' => 'nullable|string|max:255',
            'applicable_to' => ['required', Rule::in(['all', 'specific_products', 'specific_categories'])],
            'applicable_ids' => 'nullable|array',
            'minimum_purchase' => 'nullable|numeric|min:0',
            'maximum_discount' => 'nullable|numeric|min:0',
            'priority' => 'nullable|integer|min:0|max:100'
        ]);

        // Validate percentage value
        if ($request->discount_type === SaleEvent::DISCOUNT_PERCENTAGE && $request->discount_value > 100) {
            return response()->json([
                'success' => false,
                'message' => 'Percentage discount cannot exceed 100%'
            ], 422);
        }

        // Validate applicable IDs
        if ($request->applicable_to !== 'all' && empty($request->applicable_ids)) {
            return response()->json([
                'success' => false,
                'message' => 'Please select applicable products or categories'
            ], 422);
        }

        $saleEvent = SaleEvent::create([
            'name' => $request->name,
            'description' => $request->description,
            'type' => $request->type,
            'discount_type' => $request->discount_type,
            'discount_value' => $request->discount_value,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'is_active' => $request->get('is_active', true),
            'banner_image' => $request->banner_image,
            'banner_text' => $request->banner_text,
            'applicable_to' => $request->applicable_to,
            'applicable_ids' => $request->applicable_ids,
            'minimum_purchase' => $request->minimum_purchase,
            'maximum_discount' => $request->maximum_discount,
            'auto_activate' => $request->get('auto_activate', false),
            'priority' => $request->get('priority', 50)
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Sale event created successfully',
            'data' => $saleEvent
        ], 201);
    }

    /**
     * Get single sale event
     */
    public function show($id): JsonResponse
    {
        $saleEvent = SaleEvent::find($id);

        if (!$saleEvent) {
            return response()->json([
                'success' => false,
                'message' => 'Sale event not found'
            ], 404);
        }

        // Add computed fields and statistics
        $saleEvent->is_currently_active = $saleEvent->isCurrentlyActive();
        $saleEvent->time_remaining = $saleEvent->getTimeRemaining();
        
        // Get sales statistics if event is/was active
        $saleEvent->statistics = [
            'orders_count' => 0, // You can implement order tracking by sale event
            'revenue_generated' => 0,
            'products_sold' => 0,
            'conversion_rate' => 0
        ];

        return response()->json([
            'success' => true,
            'data' => $saleEvent
        ]);
    }

    /**
     * Update sale event
     */
    public function update(Request $request, $id): JsonResponse
    {
        $saleEvent = SaleEvent::find($id);

        if (!$saleEvent) {
            return response()->json([
                'success' => false,
                'message' => 'Sale event not found'
            ], 404);
        }

        // Get valid sale event types from system settings
        $validTypes = \App\Models\SystemSetting::getSaleEventTypes();
        $allowedTypes = array_column($validTypes, 'key');

        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => ['required', Rule::in($allowedTypes)],
            'discount_type' => ['required', Rule::in([SaleEvent::DISCOUNT_PERCENTAGE, SaleEvent::DISCOUNT_FIXED])],
            'discount_value' => 'required|numeric|min:0',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'banner_image' => 'nullable|string',
            'banner_text' => 'nullable|string|max:255',
            'applicable_to' => ['required', Rule::in(['all', 'specific_products', 'specific_categories'])],
            'applicable_ids' => 'nullable|array',
            'minimum_purchase' => 'nullable|numeric|min:0',
            'maximum_discount' => 'nullable|numeric|min:0',
            'priority' => 'nullable|integer|min:0|max:100'
        ]);

        $saleEvent->update([
            'name' => $request->name,
            'description' => $request->description,
            'type' => $request->type,
            'discount_type' => $request->discount_type,
            'discount_value' => $request->discount_value,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'banner_image' => $request->banner_image,
            'banner_text' => $request->banner_text,
            'applicable_to' => $request->applicable_to,
            'applicable_ids' => $request->applicable_ids,
            'minimum_purchase' => $request->minimum_purchase,
            'maximum_discount' => $request->maximum_discount,
            'auto_activate' => $request->get('auto_activate', false),
            'priority' => $request->get('priority', 50)
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Sale event updated successfully',
            'data' => $saleEvent
        ]);
    }

    /**
     * Toggle sale event active status
     */
    public function toggleStatus($id): JsonResponse
    {
        $saleEvent = SaleEvent::find($id);

        if (!$saleEvent) {
            return response()->json([
                'success' => false,
                'message' => 'Sale event not found'
            ], 404);
        }

        $saleEvent->update(['is_active' => !$saleEvent->is_active]);

        return response()->json([
            'success' => true,
            'message' => 'Sale event status updated successfully',
            'data' => $saleEvent
        ]);
    }

    /**
     * Delete sale event
     */
    public function destroy($id): JsonResponse
    {
        $saleEvent = SaleEvent::find($id);

        if (!$saleEvent) {
            return response()->json([
                'success' => false,
                'message' => 'Sale event not found'
            ], 404);
        }

        $saleEvent->delete();

        return response()->json([
            'success' => true,
            'message' => 'Sale event deleted successfully'
        ]);
    }

    /**
     * Get sale event options for forms
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
        
        // If user is a store owner, only show their store's products
        if ($user && $user->role === 'store_owner') {
            $store = \App\Models\Store::where('owner_id', $user->id)->first();
            if ($store) {
                $productsQuery->where('store_id', $store->id);
            }
        }
        // Admin sees all products
        
        $products = $productsQuery->with('category')->get(['id', 'name', 'price', 'category_id']);

        // Get dynamic sale event types from system settings
        $saleEventTypes = \App\Models\SystemSetting::getSaleEventTypes();
        $typesArray = [];
        foreach ($saleEventTypes as $key => $typeData) {
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
                ['value' => SaleEvent::TYPE_FLASH_SALE, 'label' => 'Flash Sale'],
                ['value' => SaleEvent::TYPE_SEASONAL, 'label' => 'Seasonal Sale'],
                ['value' => SaleEvent::TYPE_CLEARANCE, 'label' => 'Clearance Sale'],
                ['value' => SaleEvent::TYPE_BLACK_FRIDAY, 'label' => 'Black Friday'],
                ['value' => SaleEvent::TYPE_CYBER_MONDAY, 'label' => 'Cyber Monday'],
                ['value' => SaleEvent::TYPE_NEW_YEAR, 'label' => 'New Year Sale'],
                ['value' => SaleEvent::TYPE_VALENTINES, 'label' => "Valentine's Day"],
                ['value' => SaleEvent::TYPE_MOTHERS_DAY, 'label' => "Mother's Day"]
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'types' => $typesArray,
                'discount_types' => [
                    ['value' => SaleEvent::DISCOUNT_PERCENTAGE, 'label' => 'Percentage Discount'],
                    ['value' => SaleEvent::DISCOUNT_FIXED, 'label' => 'Fixed Amount Discount']
                ],
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

    /**
     * Create quick sale events
     */
    public function createQuickSale(Request $request): JsonResponse
    {
        $request->validate([
            'sale_type' => 'required|in:flash_24h,weekend_sale,clearance_week,seasonal',
            'discount_percentage' => 'required|numeric|min:5|max:80',
            'target_products' => 'nullable|array'
        ]);

        $saleType = $request->sale_type;
        $discountPercentage = $request->discount_percentage;
        $targetProducts = $request->target_products ?? [];

        switch ($saleType) {
            case 'flash_24h':
                $saleEvent = SaleEvent::create([
                    'name' => 'Flash Sale - 24 Hours Only',
                    'description' => "Limited time flash sale - {$discountPercentage}% off!",
                    'type' => SaleEvent::TYPE_FLASH_SALE,
                    'discount_type' => SaleEvent::DISCOUNT_PERCENTAGE,
                    'discount_value' => $discountPercentage,
                    'start_date' => now(),
                    'end_date' => now()->addHours(24),
                    'is_active' => true,
                    'banner_text' => "FLASH SALE: {$discountPercentage}% OFF - 24 HOURS ONLY!",
                    'applicable_to' => empty($targetProducts) ? 'all' : 'specific_products',
                    'applicable_ids' => $targetProducts,
                    'auto_activate' => true,
                    'priority' => 95
                ]);
                break;

            case 'weekend_sale':
                $friday = now()->next('Friday')->startOfDay();
                $sunday = $friday->copy()->addDays(2)->endOfDay();
                
                $saleEvent = SaleEvent::create([
                    'name' => 'Weekend Special Sale',
                    'description' => "Weekend only - {$discountPercentage}% off selected items!",
                    'type' => SaleEvent::TYPE_SEASONAL,
                    'discount_type' => SaleEvent::DISCOUNT_PERCENTAGE,
                    'discount_value' => $discountPercentage,
                    'start_date' => $friday,
                    'end_date' => $sunday,
                    'is_active' => true,
                    'banner_text' => "WEEKEND SALE: {$discountPercentage}% OFF!",
                    'applicable_to' => empty($targetProducts) ? 'all' : 'specific_products',
                    'applicable_ids' => $targetProducts,
                    'auto_activate' => true,
                    'priority' => 80
                ]);
                break;

            case 'clearance_week':
                $saleEvent = SaleEvent::create([
                    'name' => 'Clearance Week - Limited Stock',
                    'description' => "One week clearance sale - {$discountPercentage}% off to clear inventory!",
                    'type' => SaleEvent::TYPE_CLEARANCE,
                    'discount_type' => SaleEvent::DISCOUNT_PERCENTAGE,
                    'discount_value' => $discountPercentage,
                    'start_date' => now(),
                    'end_date' => now()->addWeek(),
                    'is_active' => true,
                    'banner_text' => "CLEARANCE: {$discountPercentage}% OFF - LIMITED STOCK!",
                    'applicable_to' => empty($targetProducts) ? 'all' : 'specific_products',
                    'applicable_ids' => $targetProducts,
                    'auto_activate' => true,
                    'priority' => 70
                ]);
                break;

            default:
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid sale type'
                ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Quick sale event created successfully',
            'data' => $saleEvent
        ]);
    }

    /**
     * Get active sale events for frontend
     */
    public function getActiveSales(): JsonResponse
    {
        try {
            // Only get manual sale events - holiday automation removed for simplicity
            $activeSales = SaleEvent::active()
                                   ->byPriority()
                                   ->get()
                                   ->map(function ($sale) {
                                       return [
                                           'id' => $sale->id,
                                           'name' => $sale->name,
                                           'description' => $sale->description,
                                           'banner_text' => $sale->banner_text,
                                           'banner_image' => $sale->banner_image,
                                           'discount_value' => $sale->discount_value,
                                           'discount_type' => $sale->discount_type,
                                           'end_date' => $sale->end_date,
                                           'time_remaining' => $sale->getTimeRemaining(),
                                           'type' => $sale->type,
                                           'source' => 'manual'
                                       ];
                                   });

            return response()->json([
                'success' => true,
                'data' => $activeSales
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch active sales',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get products on sale for frontend
     */
    public function getSaleProducts(Request $request): JsonResponse
    {
        try {
            $activeSales = SaleEvent::active()->get();
            
            if ($activeSales->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'message' => 'No active sales at the moment'
                ]);
            }

            $saleProductIds = [];
            foreach ($activeSales as $sale) {
                if ($sale->applicable_to === 'all') {
                    $allProductIds = Product::where('is_active', true)->pluck('id')->toArray();
                    $saleProductIds = array_merge($saleProductIds, $allProductIds);
                } elseif ($sale->applicable_to === 'specific_products') {
                    $saleProductIds = array_merge($saleProductIds, $sale->applicable_ids ?? []);
                } elseif ($sale->applicable_to === 'specific_categories') {
                    // applicable_ids holds category slugs (or ids, for older records).
                    $categoryProductIds = Product::where('is_active', true)
                        ->whereHas('category', function ($q) use ($sale) {
                            $ids = $sale->applicable_ids ?? [];
                            $q->whereIn('slug', $ids)->orWhereIn('id', array_filter($ids, 'is_numeric'));
                        })
                        ->pluck('id')
                        ->toArray();
                    $saleProductIds = array_merge($saleProductIds, $categoryProductIds);
                }
            }

            $saleProductIds = array_unique($saleProductIds);

            $products = Product::whereIn('id', $saleProductIds)
                ->where('is_active', true)
                ->paginate($request->get('per_page', 12));

            // Add sale information to each product
            $products->getCollection()->transform(function ($product) use ($activeSales) {
                $bestSale = null;
                $bestPrice = $product->price;
                $bestDiscount = 0;

                foreach ($activeSales as $sale) {
                    if ($sale->appliesToProduct($product)) {
                        $salePrice = $sale->applyToProduct($product);
                        $discount = $product->price - $salePrice;
                        
                        if ($discount > $bestDiscount) {
                            $bestDiscount = $discount;
                            $bestPrice = $salePrice;
                            $bestSale = $sale;
                        }
                    }
                }

                $product->sale_price = $bestPrice;
                $product->original_price = $product->price;
                $product->discount_amount = $bestDiscount;
                $product->discount_percentage = $product->price > 0 ? round(($bestDiscount / $product->price) * 100, 1) : 0;
                $product->current_sale = $bestSale ? [
                    'id' => $bestSale->id,
                    'name' => $bestSale->name,
                    'type' => $bestSale->type,
                    'banner_text' => $bestSale->banner_text,
                    'end_date' => $bestSale->end_date
                ] : null;

                return $product;
            });

            return response()->json([
                'success' => true,
                'data' => $products
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch sale products',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get sale information for a specific product
     */
    public function getProductSaleInfo($productId): JsonResponse
    {
        try {
            $product = Product::find($productId);
            
            if (!$product) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product not found'
                ], 404);
            }

            $activeSales = SaleEvent::active()->get();
            $bestSale = null;
            $bestPrice = $product->price;
            $bestDiscount = 0;

            foreach ($activeSales as $sale) {
                if ($sale->appliesToProduct($product)) {
                    $salePrice = $sale->applyToProduct($product);
                    $discount = $product->price - $salePrice;
                    
                    if ($discount > $bestDiscount) {
                        $bestDiscount = $discount;
                        $bestPrice = $salePrice;
                        $bestSale = $sale;
                    }
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'product_id' => $product->id,
                    'original_price' => $product->price,
                    'sale_price' => $bestPrice,
                    'discount_amount' => $bestDiscount,
                    'discount_percentage' => $product->price > 0 ? round(($bestDiscount / $product->price) * 100, 1) : 0,
                    'is_on_sale' => $bestDiscount > 0,
                    'current_sale' => $bestSale ? [
                        'id' => $bestSale->id,
                        'name' => $bestSale->name,
                        'type' => $bestSale->type,
                        'banner_text' => $bestSale->banner_text,
                        'end_date' => $bestSale->end_date,
                        'time_remaining' => $bestSale->getTimeRemaining()
                    ] : null
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch product sale info',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
