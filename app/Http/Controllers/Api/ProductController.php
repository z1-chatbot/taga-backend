<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ScopesToStore;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\CategoryAttribute;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ProductController extends Controller
{
    use ScopesToStore;

    /**
     * Get all products with filtering and pagination
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        
        // Log all request parameters for debugging
        \Log::info('Product Filter Request:', [
            'all_params' => $request->all(),
            'query_params' => $request->query(),
        ]);
        
        // sellable(), not active(): a listing is only public once the shop
        // behind it holds an approved, current pharmacy licence.
        $query = Product::with(['reviews', 'category'])
                       ->sellable();

        // Filter by store for store owners (exclude null store_id products)
        if ($user && $user->role === 'store_owner' && $user->store) {
            $query->where('store_id', $user->store->id)
                  ->whereNotNull('store_id');
        }
        
        // Filter by store for store staff (only see their assigned store's products)
        if ($user && $user->store_id && $user->role === 'staff') {
            $query->where('store_id', $user->store_id)
                  ->whereNotNull('store_id');
        }
        
        // Admin can filter by specific store
        if ($user && $user->role === 'admin' && $request->has('store_id') && $request->store_id !== '') {
            $query->where('store_id', $request->store_id);
        }

        // --- Category ---------------------------------------------------------
        // Accepts an id or a slug. Browsing a parent category includes everything in
        // its subtree, so "Prescription Medicines" returns Cardiovascular, Neurology, ...
        $categoryParam = $request->input('category', $request->input('product_category'));

        if ($categoryParam !== null && $categoryParam !== '') {
            $category = Category::findBySlugOrId($categoryParam);

            if ($category) {
                $request->boolean('exact_category')
                    ? $query->where('category_id', $category->id)
                    : $query->inCategoryTree($category);
            } else {
                // Unknown category should return nothing rather than everything.
                $query->whereRaw('1 = 0');
            }
        }

        // --- Pharmacy filters ---------------------------------------------------
        foreach ([
            'manufacturer' => 'manufacturer',
            'dosage_form' => 'dosage_form',
            'strength' => 'strength',
            'route_of_administration' => 'route_of_administration',
            'drug_schedule' => 'drug_schedule',
        ] as $param => $column) {
            if ($request->filled($param)) {
                $query->where($column, $request->input($param));
            }
        }

        if ($request->filled('generic_name')) {
            $query->where('generic_name', 'like', "%{$request->input('generic_name')}%");
        }

        if ($request->filled('brand_name')) {
            $query->where('brand_name', 'like', "%{$request->input('brand_name')}%");
        }

        if ($request->has('requires_prescription') && $request->input('requires_prescription') !== '') {
            $query->where('requires_prescription', $request->boolean('requires_prescription'));
        }

        if ($request->has('is_controlled_substance') && $request->input('is_controlled_substance') !== '') {
            $query->where('is_controlled_substance', $request->boolean('is_controlled_substance'));
        }

        // Hide stock that has already expired. Opt-out so vendors/admin can still see it.
        if (! $request->boolean('include_expired')) {
            $query->notExpired();
        }

        // --- Flexible per-category attribute filters ------------------------------
        // Passed as attr[key]=value, e.g. attr[skin_type]=Sensitive&attr[spf]=30
        foreach ((array) $request->input('attr', []) as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $query->whereHas('attributeValues', function ($q) use ($key, $value) {
                $q->whereHas('attribute', fn ($a) => $a->where('key', $key))
                  ->where('value', $value);
            });
        }

        if ($request->has('min_price') && $request->has('max_price')) {
            $query->byPriceRange($request->min_price, $request->max_price);
        }

        if ($request->has('featured') && $request->featured) {
            $query->featured();
        }

        // Flash Sale filter (products with sale_price)
        if ($request->has('on_sale') && $request->on_sale) {
            $query->whereNotNull('sale_price')
                  ->where('sale_price', '>', 0)
                  ->whereRaw('sale_price < price');
        }

        // New Arrivals filter (products from last X days)
        if ($request->has('days') && $request->days) {
            $days = (int) $request->days;
            $query->where('created_at', '>=', now()->subDays($days));
        }

        // Filter by store state (location-based filtering)
        if ($request->has('state') && $request->state !== '') {
            $query->whereHas('store', function($q) use ($request) {
                $q->where('state', $request->state);
            });
        }

        // Top Rated filter (minimum rating)
        if ($request->has('min_rating') && $request->min_rating) {
            $minRating = (float) $request->min_rating;
            // approvedReviews, not reviews: filtering on submissions nobody
            // has cleared lets a pending review pull a product into a
            // "4 stars and up" search.
            $query->whereHas('approvedReviews', function($q) use ($minRating) {
                $q->havingRaw('AVG(rating) >= ?', [$minRating]);
            });
        }

        // Search functionality
        if ($request->has('search') && $request->search !== '') {
            $searchTerm = $request->search;
            $query->where(function ($q) use ($searchTerm) {
                $q->where('name', 'like', "%{$searchTerm}%")
                  ->orWhere('description', 'like', "%{$searchTerm}%")
                  ->orWhere('short_description', 'like', "%{$searchTerm}%")
                  ->orWhere('sku', 'like', "%{$searchTerm}%");
            });
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        
        switch ($sortBy) {
            case 'price_low_high':
                $query->orderBy('price', 'asc');
                break;
            case 'price_high_low':
                $query->orderBy('price', 'desc');
                break;
            case 'name':
                $query->orderBy('name', 'asc');
                break;
            case 'rating':
                // Approved only. Ranking by every submission made "Best rated"
                // something a single unmoderated five-star review could win.
                $query->withAvg('approvedReviews', 'rating')
                      ->orderBy('approved_reviews_avg_rating', 'desc');
                break;
            case 'sales':
                // Best Sellers - sort by number of orders
                $query->withCount(['orderItems' => function($q) {
                    $q->whereHas('order', function($orderQuery) {
                        $orderQuery->whereIn('status', ['confirmed', 'processing', 'shipped', 'delivered']);
                    });
                }])->orderBy('order_items_count', 'desc');
                break;
            case 'popularity':
                // Trending - combination of recent sales + views (if you have views tracking)
                // For now, we'll use recent orders + rating
                $query->withCount(['orderItems' => function($q) {
                    $q->whereHas('order', function($orderQuery) {
                        $orderQuery->whereIn('status', ['confirmed', 'processing', 'shipped', 'delivered'])
                                   ->where('created_at', '>=', now()->subDays(30));
                    });
                }])
                ->withAvg('approvedReviews', 'rating')
                ->orderByRaw('(order_items_count * 2 + COALESCE(approved_reviews_avg_rating, 0)) DESC');
                break;
            default:
                // Whitelisted: this used to pass the raw `sort_by` value straight
                // to orderBy(), so any unrecognised value — a stale bookmark, a
                // mistyped link, a crawler — returned a 500 for the whole
                // catalogue. Anything unknown now falls back to newest first.
                $allowedColumns = ['created_at', 'updated_at', 'price', 'name', 'stock_quantity'];
                $column = in_array($sortBy, $allowedColumns, true) ? $sortBy : 'created_at';
                $direction = strtolower($sortOrder) === 'asc' ? 'asc' : 'desc';

                $query->orderBy($column, $direction);
        }

        // Pagination. Clamped so a crafted per_page cannot ask for the entire
        // table in one response.
        $perPage = min(max((int) $request->get('per_page', 12), 1), 100);
        $products = $query->paginate($perPage);

        // Add computed attributes
        $products->getCollection()->transform(function ($product) {
            return [
                'id' => $product->id,
                'name' => $product->name,
                'generic_name' => $product->generic_name,
                'brand_name' => $product->brand_name,
                'manufacturer' => $product->manufacturer,
                'short_description' => $product->short_description,
                'price' => $product->original_price, // Regular price with markup (for crossed-out display)
                'original_price' => $product->original_price,
                'sale_price' => $product->is_on_sale ? $product->current_price : null, // Sale price = current_price when on sale
                'current_price' => $product->current_price, // What customer actually pays
                'platform_markup' => $product->platform_markup,
                'is_on_sale' => $product->is_on_sale,
                'sku' => $product->sku,
                'stock_quantity' => $product->stock_quantity,
                'strength' => $product->strength,
                'dosage_form' => $product->dosage_form,
                'pack_size' => $product->pack_size,
                'requires_prescription' => $product->requires_prescription,
                'is_controlled_substance' => $product->is_controlled_substance,
                'expiry_date' => $product->expiry_date?->toDateString(),
                'images' => $product->images,
                'average_rating' => $product->average_rating,
                'review_count' => $product->review_count,
                'category' => $product->relationLoaded('category') && $product->category
                    ? [
                        'id' => $product->category->id,
                        'name' => $product->category->name,
                        'slug' => $product->category->slug,
                        'product_type' => $product->category->product_type,
                    ]
                    : null,
                'payment_method_restriction' => $product->payment_method_restriction,
                'created_at' => $product->created_at,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $products,
            'filters' => $this->getActiveFilterOptions($request)
        ]);
    }

    /**
     * Filter facets for the storefront.
     *
     * Returns the shared option lists plus, when a category is being browsed, the
     * filterable per-category attributes that apply to it (including inherited ones).
     */
    private function getActiveFilterOptions(?Request $request = null): array
    {
        $setting = fn (string $key, array $default = []) => $this->getSettingArray($key, $default);

        $filters = [
            'dosage_forms' => $setting('dosage_forms'),
            'routes_of_administration' => $setting('routes_of_administration'),
            'storage_conditions' => $setting('storage_conditions'),
            'drug_schedules' => $setting('drug_schedules'),
            'pack_sizes' => $setting('pack_sizes'),
            // Manufacturers are drawn from live data rather than a maintained list.
            'manufacturers' => Product::active()
                ->whereNotNull('manufacturer')
                ->distinct()
                ->orderBy('manufacturer')
                ->pluck('manufacturer')
                ->values()
                ->all(),
            'attributes' => [],
        ];

        $categoryParam = $request?->input('category', $request?->input('product_category'));

        if ($categoryParam) {
            $category = is_numeric($categoryParam)
                ? Category::find($categoryParam)
                : Category::where('slug', $categoryParam)->first();

            if ($category) {
                $filters['attributes'] = $category->resolvedAttributes()
                    ->where('is_filterable', true)
                    ->map(fn ($attribute) => [
                        'key' => $attribute->key,
                        'label' => $attribute->label,
                        'type' => $attribute->type,
                        'unit' => $attribute->unit,
                        'options' => $attribute->options ?? [],
                    ])
                    ->values()
                    ->all();
            }
        }

        return $filters;
    }

    /**
     * Get a single product by ID
     */
    public function show($id): JsonResponse
    {
        $product = Product::with([
                            'reviews.user', 'store', 'activeVariations',
                            'category', 'attributeValues.attribute',
                         ])
                         ->sellable()
                         ->find($id);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }

        // Get related products
        $relatedProducts = Product::active()
                                ->inStock()
                                ->notExpired()
                                ->where('category_id', $product->category_id)
                                ->where('id', '!=', $product->id)
                                ->limit(4)
                                ->get()
                                ->map(function ($relatedProduct) {
                                    return [
                                        'id' => $relatedProduct->id,
                                        'name' => $relatedProduct->name,
                                        'price' => $relatedProduct->original_price,
                                        'original_price' => $relatedProduct->original_price,
                                        'current_price' => $relatedProduct->current_price,
                                        'sale_price' => $relatedProduct->is_on_sale ? $relatedProduct->current_price : null,
                                        'is_on_sale' => $relatedProduct->is_on_sale,
                                        'platform_markup' => $relatedProduct->platform_markup,
                                        'images' => $relatedProduct->images,
                                        'average_rating' => $relatedProduct->average_rating,
                                        'stock_quantity' => $relatedProduct->stock_quantity,
                                        'payment_method_restriction' => $relatedProduct->payment_method_restriction,
                                    ];
                                });

        // Debug pricing
        \Log::info('Product pricing debug', [
            'product_id' => $product->id,
            'db_price' => $product->attributes['price'] ?? null,
            'db_sale_price' => $product->attributes['sale_price'] ?? null,
            'effective_sale_price' => $product->effective_sale_price,
            'current_price' => $product->current_price,
            'original_price' => $product->original_price,
            'sale_price_accessor' => $product->sale_price,
            'is_on_sale' => $product->is_on_sale,
            'best_active_sale' => $product->best_active_sale ? get_class($product->best_active_sale) : null,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'product' => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'description' => $product->description,
                    'short_description' => $product->short_description,
                    'price' => $product->original_price, // Regular price with markup (for crossed-out display)
                    'original_price' => $product->original_price, // Same as price
                    'sale_price' => $product->is_on_sale ? $product->current_price : null, // Sale price when on sale
                    'current_price' => $product->current_price, // What customer actually pays
                    'platform_markup' => $product->platform_markup,
                    'is_on_sale' => $product->is_on_sale,
                    'sku' => $product->sku,
                    'stock_quantity' => $product->stock_quantity,
                    // Medicine identity
                    'generic_name' => $product->generic_name,
                    'brand_name' => $product->brand_name,
                    'manufacturer' => $product->manufacturer,
                    'active_ingredients' => $product->active_ingredients,
                    // Dosing
                    'strength' => $product->strength,
                    'dosage_form' => $product->dosage_form,
                    'pack_size' => $product->pack_size,
                    'route_of_administration' => $product->route_of_administration,
                    // Regulatory / safety
                    'requires_prescription' => $product->requires_prescription,
                    'is_controlled_substance' => $product->is_controlled_substance,
                    'drug_schedule' => $product->drug_schedule,
                    'nafdac_number' => $product->nafdac_number,
                    'storage_conditions' => $product->storage_conditions,
                    'batch_number' => $product->batch_number,
                    'expiry_date' => $product->expiry_date?->toDateString(),
                    'is_expired' => $product->isExpired(),
                    // Clinical guidance
                    'directions_for_use' => $product->directions_for_use,
                    'side_effects' => $product->side_effects,
                    'warnings' => $product->warnings,
                    'contraindications' => $product->contraindications,
                    // Flexible per-category attributes
                    'category' => $product->category ? [
                        'id' => $product->category->id,
                        'name' => $product->category->name,
                        'slug' => $product->category->slug,
                        'product_type' => $product->category->product_type,
                    ] : null,
                    'attributes' => $product->attributeValues
                        ->filter(fn ($value) => $value->attribute !== null)
                        ->map(fn ($value) => [
                            'key' => $value->attribute->key,
                            'label' => $value->attribute->label,
                            'unit' => $value->attribute->unit,
                            'value' => $value->typedValue(),
                        ])
                        ->values(),
                    'highlighted_features' => $product->highlighted_features,
                    'weight_kg' => $product->weight_kg,
                    'package_dimensions' => $product->package_dimensions,
                    'is_featured' => $product->is_featured,
                    'is_active' => $product->is_active,
                    'payment_method_restriction' => $product->payment_method_restriction,
                    'images' => $product->images,
                    'average_rating' => $product->average_rating,
                    'rating_count' => $product->rating_count,
                    'reviews' => $product->reviews->where('is_approved', true)->take(10),
                    'created_at' => $product->created_at,
                    'has_variations' => $product->has_variations,
                    'active_variations' => $product->activeVariations,
                    'store' => $product->store ? [
                        'id' => $product->store->id,
                        'name' => $product->store->name,
                        'slug' => $product->store->slug,
                        'logo' => $product->store->logo,
                    ] : null,
                ],
                'related_products' => $relatedProducts
            ]
        ]);
    }

    /**
     * Get featured products
     */
    public function featured(): JsonResponse
    {
        $products = Product::sellable()
                          ->featured()
                          ->inStock()
                          ->limit(8)
                          ->get()
                          ->map(function ($product) {
                              return [
                                  'id' => $product->id,
                                  'name' => $product->name,
                                  'short_description' => $product->short_description,
                                  'price' => $product->original_price,
                                  'original_price' => $product->original_price,
                                  'sale_price' => $product->is_on_sale ? $product->current_price : null,
                                  'current_price' => $product->current_price,
                                  'is_on_sale' => $product->is_on_sale,
                                  'platform_markup' => $product->platform_markup,
                                  'images' => $product->images,
                                  'average_rating' => $product->average_rating,
                                  'stock_quantity' => $product->stock_quantity,
                                  'strength' => $product->strength,
                                  'dosage_form' => $product->dosage_form,
                                  'requires_prescription' => $product->requires_prescription,
                                  'category' => $product->category?->only(['id', 'name', 'slug']),
                                  'payment_method_restriction' => $product->payment_method_restriction,
                              ];
                          });

        return response()->json([
            'success' => true,
            'data' => $products
        ]);
    }

    /**
     * Search products
     */
    public function search(Request $request): JsonResponse
    {
        $query = $request->get('q');
        $user = $request->user();
        
        if (!$query) {
            return response()->json([
                'success' => false,
                'message' => 'Search query is required'
            ], 400);
        }

        // Generic name matters as much as brand here: shoppers search both
        // "Panadol" and "paracetamol" for the same product.
        $productsQuery = Product::sellable()
                          ->with('category')
                          ->where(function ($q) use ($query) {
                              $q->where('name', 'like', "%{$query}%")
                                ->orWhere('description', 'like', "%{$query}%")
                                ->orWhere('generic_name', 'like', "%{$query}%")
                                ->orWhere('brand_name', 'like', "%{$query}%")
                                ->orWhere('manufacturer', 'like', "%{$query}%")
                                ->orWhere('sku', 'like', "%{$query}%");
                          });
        
        $this->scopeQueryToStore($productsQuery, $request);


        $products = $productsQuery->paginate(12);

        return response()->json([
            'success' => true,
            'data' => $products
        ]);
    }

    /**
     * Admin: Get all products with filtering and pagination
     */
    public function adminIndex(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            \Log::info('AdminIndex called', [
                'user_id' => $user?->id,
                'user_role' => $user?->role,
                'has_store' => $user?->store ? true : false,
                'request_params' => $request->all(),
                'total_products_in_db' => Product::count(),
                'total_including_deleted' => Product::withTrashed()->count(),
                'products_with_store' => Product::whereNotNull('store_id')->count(),
                'products_without_store' => Product::whereNull('store_id')->count(),
                'all_product_ids' => Product::pluck('id', 'store_id')->toArray(),
                'deleted_product_ids' => Product::onlyTrashed()->pluck('id')->toArray()
            ]);
            
            $query = Product::query();

            // One rule for whose products these are, applied the same way
            // everywhere — see ScopesToStore. The role-name comparisons this
            // replaces matched only 'store_owner' and 'staff', so every other
            // store role saw the whole platform's catalogue.
            $this->scopeQueryToStore($query, $request);

            // --- Category -------------------------------------------------------
            $categoryParam = $request->input('category_id', $request->input('category'));

            if ($categoryParam !== null && $categoryParam !== '') {
                $category = Category::findBySlugOrId($categoryParam);

                if ($category) {
                    $request->boolean('exact_category')
                        ? $query->where('category_id', $category->id)
                        : $query->inCategoryTree($category);
                }
            }

            // --- Pharmacy filters -------------------------------------------------
            foreach ([
                'manufacturer' => 'manufacturer',
                'dosage_form' => 'dosage_form',
                'strength' => 'strength',
                'drug_schedule' => 'drug_schedule',
                'batch_number' => 'batch_number',
            ] as $param => $column) {
                if ($request->filled($param)) {
                    $query->where($column, $request->input($param));
                }
            }

            if ($request->filled('generic_name')) {
                $query->where('generic_name', 'like', "%{$request->input('generic_name')}%");
            }

            if ($request->has('requires_prescription') && $request->input('requires_prescription') !== '') {
                $query->where('requires_prescription', $request->boolean('requires_prescription'));
            }

            if ($request->has('is_controlled_substance') && $request->input('is_controlled_substance') !== '') {
                $query->where('is_controlled_substance', $request->boolean('is_controlled_substance'));
            }

            // Stock-management views vendors need: what has expired, and what is close to.
            if ($request->boolean('expired')) {
                $query->whereNotNull('expiry_date')->where('expiry_date', '<', now()->toDateString());
            }

            if ($request->filled('expiring_within_days')) {
                $days = (int) $request->input('expiring_within_days');
                $query->whereNotNull('expiry_date')
                      ->whereBetween('expiry_date', [now()->toDateString(), now()->addDays($days)->toDateString()]);
            }

            if ($request->filled('price_min')) {
                $query->where('price', '>=', $request->price_min);
            }

            if ($request->filled('price_max')) {
                $query->where('price', '<=', $request->price_max);
            }

            if ($request->boolean('in_stock')) {
                $query->where('stock_quantity', '>', 0);
            }

            if ($request->boolean('featured')) {
                $query->where('is_featured', true);
            }

            if ($request->has('active')) {
                $query->where('is_active', $request->boolean('active'));
            }

            // Search functionality
            if ($request->filled('search')) {
                $searchTerm = $request->search;
                $query->where(function ($q) use ($searchTerm) {
                    $q->where('name', 'like', "%{$searchTerm}%")
                      ->orWhere('description', 'like', "%{$searchTerm}%")
                      ->orWhere('sku', 'like', "%{$searchTerm}%")
                      ->orWhere('generic_name', 'like', "%{$searchTerm}%")
                      ->orWhere('brand_name', 'like', "%{$searchTerm}%")
                      ->orWhere('manufacturer', 'like', "%{$searchTerm}%");
                });
            }

            // Order by
            $query->orderBy('created_at', 'desc');

            // Paginate
            $perPage = $request->get('per_page', 12);
            $products = $query->paginate($perPage);
            
            \Log::info('Products query result', [
                'total_found' => $products->total(),
                'current_page' => $products->currentPage(),
                'per_page' => $products->perPage(),
                'query_sql' => $query->toSql()
            ]);

            // Load relationships for admin view
            $products->load(['store', 'approvedReviews']);
            
            // Transform for admin view
            $transformedProducts = $products->getCollection()->map(function ($product) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'description' => $product->description,
                    'short_description' => $product->short_description,
                    'price' => $product->getAttributes()['price'] ?? $product->getRawOriginal('price') ?? 0, // Raw store price (no markup)
                    'sale_price' => $product->getAttributes()['sale_price'] ?? $product->getRawOriginal('sale_price') ?? null, // Raw store sale price (no markup)
                    'current_price' => $product->current_price, // With markup - what customer pays
                    'original_price' => $product->original_price, // Regular price with markup
                    'is_on_sale' => $product->is_on_sale,
                    'platform_markup' => $product->platform_markup, // Markup amount
                    'sku' => $product->sku,
                    'stock_quantity' => $product->stock_quantity,
                    'category_id' => $product->category_id,
                    'category' => $product->category?->only(['id', 'name', 'slug', 'product_type']),
                    'store' => $product->store ? [
                        'id' => $product->store->id,
                        'name' => $product->store->name,
                        'slug' => $product->store->slug,
                    ] : null,
                    'reviews_count' => $product->approvedReviews->count(),
                    'average_rating' => $product->approvedReviews->count() > 0
                        ? round($product->approvedReviews->avg('rating'), 1)
                        : 0,
                    // Pharmacy attributes
                    'generic_name' => $product->generic_name,
                    'brand_name' => $product->brand_name,
                    'manufacturer' => $product->manufacturer,
                    'active_ingredients' => $product->active_ingredients,
                    'strength' => $product->strength,
                    'dosage_form' => $product->dosage_form,
                    'pack_size' => $product->pack_size,
                    'route_of_administration' => $product->route_of_administration,
                    'requires_prescription' => $product->requires_prescription,
                    'is_controlled_substance' => $product->is_controlled_substance,
                    'drug_schedule' => $product->drug_schedule,
                    'nafdac_number' => $product->nafdac_number,
                    'storage_conditions' => $product->storage_conditions,
                    'batch_number' => $product->batch_number,
                    'expiry_date' => $product->expiry_date?->toDateString(),
                    'is_expired' => $product->isExpired(),
                    'highlighted_features' => $product->highlighted_features,
                    'weight_kg' => $product->weight_kg,
                    'package_dimensions' => $product->package_dimensions,
                    'is_active' => $product->is_active,
                    'is_featured' => $product->is_featured,
                    'payment_method_restriction' => $product->payment_method_restriction,
                    'images' => $product->images,
                    'meta_title' => $product->meta_title,
                    'meta_description' => $product->meta_description,
                    'store_id' => $product->store_id,
                    'created_at' => $product->created_at,
                    'updated_at' => $product->updated_at,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $transformedProducts,
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch products',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Admin: Store a new product
     */
    public function adminStore(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            // A shop's staff can only ever stock their own shelves; a platform
            // admin says which shop. Staff used to fall through both arms and
            // create a product belonging to no store at all.
            $storeId = $user->storeScopeId();

            if ($user->isPlatformAdmin()) {
                $storeId = $request->input('store_id');
            } elseif ($storeId === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Your account is not linked to a store, so it cannot add products.',
                    'code' => 'no_store_scope',
                ], 403);
            }


            // Check max products per store limit
            if ($storeId) {
                $maxProducts = \App\Models\SystemSetting::getValue(
                    \App\Models\SystemSetting::CATEGORY_GENERAL, 
                    'max_products_per_store', 
                    1000
                );
                
                $currentCount = Product::where('store_id', $storeId)->count();
                
                if ($currentCount >= $maxProducts) {
                    return response()->json([
                        'success' => false,
                        'message' => "Store has reached maximum product limit of {$maxProducts} products"
                    ], 422);
                }
            }

            $category = Category::find($request->input('category_id'));

            $validated = $request->validate(
                $this->productRules($category) + [
                    'sku' => 'nullable|string|unique:products,sku',
                ]
            );

            // Set store_id for store owners
            if ($storeId && !isset($validated['store_id'])) {
                $validated['store_id'] = $storeId;
            }

            // A vendor may only list Rx / controlled stock once the platform has
            // approved its pharmacy licence for that class of product.
            if ($denial = $this->assertStoreMayList($validated, $category, $validated['store_id'] ?? null)) {
                return $denial;
            }

            // Auto-generate SKU if not provided
            if (empty($validated['sku'])) {
                $validated['sku'] = $this->generateSKU($validated['name']);
            }

            // Regulatory flags default to the category's, unless explicitly supplied.
            $validated = $this->applyCategoryDefaults($validated, $category, $request);

            // Process highlighted features images (convert base64 to files)
            if (isset($validated['highlighted_features']) && is_array($validated['highlighted_features'])) {
                $validated['highlighted_features'] = $this->processHighlightedFeaturesImages($validated['highlighted_features']);
            }

            // `attributes` is handled separately — it is not a products column.
            $attributeInput = $validated['attributes'] ?? [];
            unset($validated['attributes']);

            $product = Product::create($validated);

            $this->syncCategoryAttributes($product, $category, $attributeInput);

            return response()->json([
                'success' => true,
                'message' => 'Product created successfully',
                'data' => $product->load(['category', 'attributeValues.attribute'])
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Rethrown so it still renders as a 422 carrying field errors.
            // The generic catch below would otherwise turn every validation
            // failure into a 500, leaving the client unable to tell the user
            // which field was wrong.
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create product',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Admin: Get a single product by ID (for editing)
     */
    public function adminShow(Request $request, $id): JsonResponse
    {
        try {
            $user = $request->user();
            
            $query = Product::with(['category', 'attributeValues.attribute'])
                ->where('id', $id);

            // Confine this to the caller's own store. Keyed on the store they
            // belong to, not on their role's name, so every store role is
            // covered rather than just 'store_owner'.
            $this->scopeQueryToStore($query, $request);

            $product = $query->first();

            if (!$product) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'product' => [
                        'id' => $product->id,
                        'name' => $product->name,
                        'description' => $product->description,
                        'short_description' => $product->short_description,
                        'price' => $product->getAttributes()['price'] ?? $product->getRawOriginal('price') ?? 0, // Raw database value (store owner's price)
                        'sale_price' => $product->getAttributes()['sale_price'] ?? $product->getRawOriginal('sale_price') ?? null, // Raw database value (store owner's sale price)
                        'current_price' => $product->current_price, // With markup
                        'original_price' => $product->original_price, // Regular price with markup
                        'is_on_sale' => $product->is_on_sale,
                        'platform_markup' => $product->platform_markup, // Markup amount
                        'sku' => $product->sku,
                        'stock_quantity' => $product->stock_quantity,
                        'category_id' => $product->category_id,
                        // The category itself, not just the id: callers showing a
                        // product need its name, and its type/flags tell them which
                        // fields apply.
                        'category' => $product->category ? [
                            'id' => $product->category->id,
                            'name' => $product->category->name,
                            'slug' => $product->category->slug,
                            'product_type' => $product->category->product_type,
                            'requires_prescription' => $product->category->requires_prescription,
                            'is_controlled_substance' => $product->category->is_controlled_substance,
                        ] : null,
                        'generic_name' => $product->generic_name,
                        'brand_name' => $product->brand_name,
                        'manufacturer' => $product->manufacturer,
                        'active_ingredients' => $product->active_ingredients,
                        'strength' => $product->strength,
                        'dosage_form' => $product->dosage_form,
                        'pack_size' => $product->pack_size,
                        'route_of_administration' => $product->route_of_administration,
                        'requires_prescription' => $product->requires_prescription,
                        'is_controlled_substance' => $product->is_controlled_substance,
                        'drug_schedule' => $product->drug_schedule,
                        'nafdac_number' => $product->nafdac_number,
                        'storage_conditions' => $product->storage_conditions,
                        'batch_number' => $product->batch_number,
                        'expiry_date' => $product->expiry_date?->toDateString(),
                        'directions_for_use' => $product->directions_for_use,
                        'side_effects' => $product->side_effects,
                        'warnings' => $product->warnings,
                        'contraindications' => $product->contraindications,
                        'attributes' => $product->attributes_map,
                        'highlighted_features' => $product->highlighted_features,
                        'weight_kg' => $product->weight_kg,
                        'package_dimensions' => $product->package_dimensions,
                        'is_active' => $product->is_active,
                        'is_featured' => $product->is_featured,
                        'payment_method_restriction' => $product->payment_method_restriction,
                        'images' => $product->images,
                        'meta_title' => $product->meta_title,
                        'meta_description' => $product->meta_description,
                        'created_at' => $product->created_at,
                        'updated_at' => $product->updated_at,
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch product',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Admin: Update a product
     */
    public function adminUpdate(Request $request, $id): JsonResponse
    {
        try {
            $user = $request->user();
            
            $query = Product::where('id', $id);
            
            // Confine this to the caller's own store. Keyed on the store they
            // belong to, not on their role's name, so every store role is
            // covered rather than just 'store_owner'.
            $this->scopeQueryToStore($query, $request);
            
            $product = $query->firstOrFail();

            // The category being moved to, or the one it already has.
            $category = $request->has('category_id')
                ? Category::find($request->input('category_id'))
                : $product->category;

            $validated = $request->validate(
                $this->productRules($category, isUpdate: true) + [
                    'sku' => 'nullable|string|unique:products,sku,' . $id,
                ]
            );

            if ($denial = $this->assertStoreMayList($validated, $category, $product->store_id)) {
                return $denial;
            }

            $validated = $this->applyCategoryDefaults($validated, $category, $request);

            $attributeInput = $validated['attributes'] ?? null;
            unset($validated['attributes']);

            // Debug: Log highlighted features before processing
            // Process highlighted features images (convert base64 to files)
            if (isset($validated['highlighted_features']) && is_array($validated['highlighted_features'])) {
                $validated['highlighted_features'] = $this->processHighlightedFeaturesImages($validated['highlighted_features']);
            }

            // `validate()` only returns keys that were actually present in the request,
            // so this already updates just the supplied fields.
            $product->update($validated);

            // Only touch attributes when the caller sent them, so a partial update
            // does not silently wipe existing values.
            if ($attributeInput !== null) {
                $this->syncCategoryAttributes($product, $category, $attributeInput);
            }

            return response()->json([
                'success' => true,
                'message' => 'Product updated successfully',
                'data' => $product->fresh(['category', 'attributeValues.attribute'])
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Rethrown so it still renders as a 422 carrying field errors.
            // The generic catch below would otherwise turn every validation
            // failure into a 500, leaving the client unable to tell the user
            // which field was wrong.
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update product',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Admin: Delete a product
     */
    public function adminDestroy(Request $request, $id): JsonResponse
    {
        try {
            $user = $request->user();
            
            $query = Product::where('id', $id);
            
            // Confine this to the caller's own store. Keyed on the store they
            // belong to, not on their role's name, so every store role is
            // covered rather than just 'store_owner'.
            $this->scopeQueryToStore($query, $request);
            
            $product = $query->firstOrFail();
            $productName = $product->name;
            
            $product->delete();

            return response()->json([
                'success' => true,
                'message' => "Product '{$productName}' deleted successfully"
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete product',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Admin: Toggle featured status
     */
    public function toggleFeatured(Request $request, $id): JsonResponse
    {
        try {
            $user = $request->user();
            
            $query = Product::where('id', $id);
            
            // Confine this to the caller's own store. Keyed on the store they
            // belong to, not on their role's name, so every store role is
            // covered rather than just 'store_owner'.
            $this->scopeQueryToStore($query, $request);
            
            $product = $query->firstOrFail();
            $product->is_featured = !$product->is_featured;
            $product->save();

            return response()->json([
                'success' => true,
                'message' => $product->is_featured ? 'Product marked as featured' : 'Product removed from featured',
                'data' => ['is_featured' => $product->is_featured]
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to toggle featured status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Admin: Toggle active status
     */
    public function toggleActive(Request $request, $id): JsonResponse
    {
        try {
            $user = $request->user();
            
            $query = Product::where('id', $id);
            
            // Confine this to the caller's own store. Keyed on the store they
            // belong to, not on their role's name, so every store role is
            // covered rather than just 'store_owner'.
            $this->scopeQueryToStore($query, $request);
            
            $product = $query->firstOrFail();
            $product->is_active = !$product->is_active;
            $product->save();

            return response()->json([
                'success' => true,
                'message' => $product->is_active ? 'Product activated' : 'Product deactivated',
                'data' => ['is_active' => $product->is_active]
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to toggle active status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate a unique SKU based on product name
     */
    private function generateSKU(string $productName): string
    {
        // Clean the product name and create base SKU
        $baseSku = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $productName));
        $baseSku = substr($baseSku, 0, 8); // Limit to 8 characters
        
        // Add timestamp for uniqueness
        $timestamp = date('ymd');
        $baseSku = $baseSku . $timestamp;
        
        // Check if SKU exists and add counter if needed
        $counter = 1;
        $finalSku = $baseSku;
        
        while (Product::where('sku', $finalSku)->exists()) {
            $finalSku = $baseSku . sprintf('%02d', $counter);
            $counter++;
        }
        
        return $finalSku;
    }

    /**
     * Helper method to get setting values as array (handles JSON strings)
     */
    private function getSettingArray(string $key, array $default = []): array
    {
        $value = \App\Models\SystemSetting::getValue('product_attributes', $key, $default);
        
        // If it's a string (JSON), decode it
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : $default;
        }
        
        // If it's already an array, return it
        return is_array($value) ? $value : $default;
    }

    /**
     * Validation rules for creating/updating a product.
     *
     * Dosing fields are only meaningful for "dosable" categories (medication and
     * wellness). For devices and supplies — the blueprint's "(No consumables)" groups —
     * they are rejected outright rather than silently stored, which is what stops the
     * catalogue drifting back into one-size-fits-all columns.
     *
     * Rules for the category's flexible attributes are appended dynamically.
     */
    private function productRules(?Category $category, bool $isUpdate = false): array
    {
        $required = $isUpdate ? 'sometimes|required' : 'required';

        $rules = [
            'store_id' => 'nullable|exists:stores,id',
            'category_id' => ($isUpdate ? 'sometimes|required' : 'required') . '|exists:categories,id',
            'name' => "{$required}|string|max:255",
            'description' => 'nullable|string',
            'short_description' => 'nullable|string|max:500',
            'price' => "{$required}|numeric|min:0",
            'sale_price' => 'nullable|numeric|min:0|lte:price',
            'stock_quantity' => 'nullable|integer|min:0',

            // Medicine identity
            'generic_name' => 'nullable|string|max:255',
            'brand_name' => 'nullable|string|max:255',
            'manufacturer' => 'nullable|string|max:255',
            'active_ingredients' => 'nullable|array',
            'active_ingredients.*' => 'string|max:255',

            // Regulatory / safety
            'requires_prescription' => 'nullable|boolean',
            'is_controlled_substance' => 'nullable|boolean',
            'drug_schedule' => 'nullable|string|max:100',
            'nafdac_number' => 'nullable|string|max:100',
            'storage_conditions' => 'nullable|string|max:1000',

            // Clinical guidance
            'directions_for_use' => 'nullable|string|max:5000',
            'side_effects' => 'nullable|string|max:5000',
            'warnings' => 'nullable|string|max:5000',
            'contraindications' => 'nullable|string|max:5000',

            // Common
            'highlighted_features' => 'nullable|array',
            'highlighted_features.*.title' => 'required_with:highlighted_features|string|max:255',
            'highlighted_features.*.description' => 'required_with:highlighted_features|string',
            'highlighted_features.*.image' => 'nullable|string',
            'weight_kg' => 'nullable|numeric|min:0',
            'package_dimensions' => 'nullable|array',
            'free_shipping' => 'nullable|boolean',
            'is_featured' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'payment_method_restriction' => 'nullable|in:any,payment_before_delivery',
            'images' => 'nullable|array',
            'images.*' => 'nullable|string',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:500',
        ];

        $isDosable = $category?->isDosable() ?? true;

        // Dosing + batch tracking
        $rules['strength'] = $isDosable ? 'nullable|string|max:100' : 'prohibited';
        $rules['dosage_form'] = $isDosable ? 'nullable|string|max:100' : 'prohibited';
        $rules['route_of_administration'] = $isDosable ? 'nullable|string|max:100' : 'prohibited';
        $rules['pack_size'] = 'nullable|string|max:100';
        $rules['batch_number'] = $isDosable ? 'nullable|string|max:100' : 'prohibited';
        $rules['expiry_date'] = $isDosable ? 'nullable|date|after:today' : 'prohibited';

        // Flexible per-category attributes, submitted as attributes[key] = value.
        $rules['attributes'] = 'nullable|array';

        if ($category) {
            foreach ($category->resolvedAttributes() as $attribute) {
                $rule = $attribute->validationRules();

                // On update the caller may omit the attributes block entirely.
                if ($isUpdate) {
                    array_unshift($rule, 'sometimes');
                }

                $rules["attributes.{$attribute->key}"] = $rule;

                if ($attribute->type === CategoryAttribute::TYPE_MULTISELECT && ! empty($attribute->options)) {
                    $rules["attributes.{$attribute->key}.*"] = 'in:' . implode(',', $attribute->options);
                }
            }
        }

        return $rules;
    }

    /**
     * Applies the category's regulatory defaults when the caller did not set them
     * explicitly, so an Rx category cannot yield a non-Rx listing by omission.
     */
    private function applyCategoryDefaults(array $validated, ?Category $category, Request $request): array
    {
        if (! $category) {
            return $validated;
        }

        if (! $request->has('requires_prescription')) {
            $validated['requires_prescription'] = $category->requires_prescription;
        }

        if (! $request->has('is_controlled_substance')) {
            $validated['is_controlled_substance'] = $category->is_controlled_substance;
        }

        return $validated;
    }

    /**
     * Blocks a store from listing Rx or controlled stock it is not licensed for.
     * Returns a JSON response to short-circuit with, or null when allowed.
     */
    private function assertStoreMayList(array $validated, ?Category $category, $storeId): ?JsonResponse
    {
        if (! $storeId) {
            return null; // Platform-owned listing; admin-gated by middleware.
        }

        $store = \App\Models\Store::find($storeId);

        if (! $store) {
            return null;
        }

        $needsRx = $validated['requires_prescription'] ?? $category?->requires_prescription ?? false;
        $needsControlled = $validated['is_controlled_substance'] ?? $category?->is_controlled_substance ?? false;

        if (! $needsRx && ! $needsControlled) {
            return null;
        }

        if (! $store->isLicenceValid()) {
            return response()->json([
                'success' => false,
                'message' => $store->verification_status === \App\Models\Store::VERIFICATION_APPROVED
                    ? 'This store\'s pharmacy licence has expired. Renew it to list regulated products.'
                    : 'This store must complete pharmacy verification before listing regulated products.',
            ], 403);
        }

        if ($needsControlled && ! $store->can_sell_controlled) {
            return response()->json([
                'success' => false,
                'message' => 'This store is not licensed to list controlled substances.',
            ], 403);
        }

        if ($needsRx && ! $store->can_sell_prescription) {
            return response()->json([
                'success' => false,
                'message' => 'This store is not licensed to list prescription medicines.',
            ], 403);
        }

        return null;
    }

    /**
     * Writes the flexible attribute values for a product, replacing any that no longer
     * apply (e.g. after the product is moved to a different category).
     */
    private function syncCategoryAttributes(Product $product, ?Category $category, array $input): void
    {
        if (! $category) {
            return;
        }

        $applicable = $category->resolvedAttributes()->keyBy('key');

        foreach ($input as $key => $value) {
            $attribute = $applicable->get($key);

            if (! $attribute) {
                continue; // Ignore keys that do not apply to this category.
            }

            $record = ProductAttributeValue::firstOrNew([
                'product_id' => $product->id,
                'category_attribute_id' => $attribute->id,
            ]);

            $record->setFromInput($attribute, $value)->save();
        }

        // Drop values whose attribute no longer applies to the product's category.
        ProductAttributeValue::where('product_id', $product->id)
            ->whereNotIn('category_attribute_id', $applicable->pluck('id'))
            ->delete();
    }

    /**
     * Process highlighted features images - convert base64 to files
     */
    private function processHighlightedFeaturesImages(array $features): array
    {
        \Log::info('processHighlightedFeaturesImages called', [
            'feature_count' => count($features),
            'features_with_images' => collect($features)->filter(fn($f) => !empty($f['image']))->count()
        ]);

        foreach ($features as $index => &$feature) {
            if (!empty($feature['image'])) {
                $imageType = 'unknown';
                if (str_starts_with($feature['image'], 'data:image/')) {
                    $imageType = 'base64';
                } elseif (str_starts_with($feature['image'], 'http')) {
                    $imageType = 'url';
                }
                \Log::info("Processing feature image [$index]", [
                    'type' => $imageType,
                    'length' => strlen($feature['image'])
                ]);

                $result = $this->handleImageUpload($feature['image']);
                \Log::info("Feature image [$index] result", ['result' => $result]);
                // Only overwrite if processing succeeded; keep original if it failed
                if ($result !== null) {
                    $feature['image'] = $result;
                } else {
                    \Log::warning("Feature image [$index] processing returned null, keeping original");
                }
            }
        }
        return $features;
    }

    /**
     * Handle image upload - supports both base64 and URLs
     */
    private function handleImageUpload($imageData): ?string
    {
        // If it's already a URL (http/https), return it as-is
        if (str_starts_with($imageData, 'http://') || str_starts_with($imageData, 'https://')) {
            return $imageData;
        }

        // If it's base64 data, save it directly to public/uploads/
        if (preg_match('/^data:image\/(\w+);base64,/', $imageData, $type)) {
            try {
                // Extract base64 content
                $base64Data = substr($imageData, strpos($imageData, ',') + 1);
                $decodedData = base64_decode($base64Data);

                if ($decodedData === false) {
                    \Log::warning('handleImageUpload: base64_decode failed');
                    return null;
                }

                // Generate unique filename
                $extension = strtolower($type[1]);
                $filename = \Illuminate\Support\Str::random(40) . '.' . $extension;
                
                // Save directly to public/uploads/ (no symlink needed)
                $uploadDir = public_path('uploads/product_features');
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                file_put_contents($uploadDir . '/' . $filename, $decodedData);

                // Return the full URL
                $url = asset('uploads/product_features/' . $filename);
                \Log::info('handleImageUpload: saved base64 image', ['url' => $url]);
                return $url;
            } catch (\Exception $e) {
                \Log::error('handleImageUpload: failed to save image', ['error' => $e->getMessage()]);
                return null;
            }
        }

        \Log::warning('handleImageUpload: unrecognized image format', [
            'starts_with' => substr($imageData, 0, 50)
        ]);
        return null;
    }

    /**
     * Upload an image file and return its URL
     */
    public function uploadImage(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'image' => 'required|image|mimes:jpeg,jpg,png,gif,webp|max:5120', // 5MB max
            ]);

            $file = $request->file('image');
            $filename = \Illuminate\Support\Str::random(40) . '.' . $file->getClientOriginalExtension();
            
            // Save directly to public/uploads/ (no symlink needed)
            $uploadDir = public_path('uploads/product_features');
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $file->move($uploadDir, $filename);

            $url = asset('uploads/product_features/' . $filename);

            \Log::info('Image uploaded successfully', ['url' => $url, 'filename' => $filename]);

            return response()->json([
                'success' => true,
                'url' => $url,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Rethrown so it still renders as a 422 carrying field errors.
            // The generic catch below would otherwise turn every validation
            // failure into a 500, leaving the client unable to tell the user
            // which field was wrong.
            throw $e;
        } catch (\Exception $e) {
            \Log::error('Image upload failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload image: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get available states (states that have active stores with products)
     */
    public function getAvailableStates(Request $request): JsonResponse
    {
        try {
            $states = \DB::table('stores')
                ->join('products', 'stores.id', '=', 'products.store_id')
                ->where('stores.status', 'active')
                ->where('products.is_active', true)
                ->whereNotNull('stores.state')
                ->where('stores.state', '!=', '')
                ->distinct()
                ->pluck('stores.state')
                ->sort()
                ->values();

            return response()->json([
                'success' => true,
                'data' => $states,
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to fetch available states:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch available states',
            ], 500);
        }
    }
}
