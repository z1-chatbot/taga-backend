<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\PaymentTransaction;
use App\Models\Review;
use App\Models\SaleEvent;
use App\Models\Coupon;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Refuses the deep analytics endpoints to anyone but a platform admin.
     *
     * salesAnalytics, inventoryAlerts and customerInsights are platform-wide
     * aggregates — every customer on the site, the whole catalogue, all
     * pharmacies together — and none of them was scoped. The store roles reach
     * them because a `store.reports.view` permission satisfies a
     * `permission:reports.view` guard, so a shop account could read the
     * platform's figures.
     *
     * The main dashboard has a per-store version (see storeOverview); these
     * three do not yet, so they stay closed rather than leak.
     */
    private function denyNonPlatformAdmin(): ?JsonResponse
    {
        $user = request()->user();

        if ($user && $user->isPlatformAdmin()) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'Platform reporting is not available to store accounts. Your own figures are on the Orders and Products pages.',
            'code' => 'platform_reporting_only',
        ], 403);
    }

    /**
     * The same dashboard, showing only one shop's own figures.
     *
     * A store account is given its own revenue, its own orders and its own
     * stock. The platform-wide blocks — every customer on the site, all active
     * promotions, the whole catalogue's top sellers — are simply absent rather
     * than filled with somebody else's numbers.
     */
    private function storeOverview(User $user): JsonResponse
    {
        $storeId = $user->storeScopeId();

        if ($storeId === null) {
            return response()->json([
                'success' => false,
                'message' => 'Your account is not linked to a store yet, so there are no figures to show.',
                'code' => 'no_store_scope',
            ], 403);
        }

        $today = Carbon::today();
        $thisMonth = Carbon::now()->startOfMonth();
        $lastMonth = Carbon::now()->subMonth()->startOfMonth();
        $thisYear = Carbon::now()->startOfYear();

        // An order belongs to a shop through the products on its lines.
        $orders = fn () => Order::whereHas(
            'items.product',
            fn ($q) => $q->where('store_id', $storeId)
        );

        $paid = fn () => $orders()->where('payment_status', Order::PAYMENT_PAID);
        $products = fn () => Product::active()->where('store_id', $storeId);
        $reviews = fn () => Review::whereHas('product', fn ($q) => $q->where('store_id', $storeId));

        $monthRevenue = $paid()->where('created_at', '>=', $thisMonth)->sum('total_amount');
        $lastMonthRevenue = $paid()->whereBetween('created_at', [$lastMonth, $thisMonth])->sum('total_amount');

        return response()->json([
            'success' => true,
            'scope' => 'store',
            'data' => [
                'revenue' => [
                    'today' => $paid()->whereDate('created_at', $today)->sum('total_amount'),
                    'month' => $monthRevenue,
                    'last_month' => $lastMonthRevenue,
                    'year' => $paid()->where('created_at', '>=', $thisYear)->sum('total_amount'),
                    'month_growth' => $lastMonthRevenue > 0
                        ? (($monthRevenue - $lastMonthRevenue) / $lastMonthRevenue) * 100
                        : 0,
                ],
                'orders' => [
                    'total' => $orders()->count(),
                    'today' => $orders()->whereDate('created_at', $today)->count(),
                    'month' => $orders()->where('created_at', '>=', $thisMonth)->count(),
                    'pending' => $orders()->where('status', Order::STATUS_PENDING)->count(),
                    'processing' => $orders()->where('status', Order::STATUS_PROCESSING)->count(),
                    'shipped' => $orders()->where('status', Order::STATUS_SHIPPED)->count(),
                    'delivered' => $orders()->where('status', Order::STATUS_DELIVERED)->count(),
                ],
                'products' => [
                    'total' => $products()->count(),
                    'low_stock' => $products()->where('stock_quantity', '<=', 5)->count(),
                    'out_of_stock' => $products()->where('stock_quantity', 0)->count(),
                    'featured' => $products()->featured()->count(),
                ],
                'reviews' => [
                    'total' => $reviews()->count(),
                    'pending' => $reviews()->where('is_approved', false)->count(),
                    'average_rating' => round((float) $reviews()->where('is_approved', true)->avg('rating'), 2),
                ],
                'recent_orders' => $orders()->with('user')->latest()->limit(5)->get(),
                'sales_chart' => $paid()
                    ->where('created_at', '>=', Carbon::now()->subDays(30))
                    ->select(
                        DB::raw('DATE(orders.created_at) as date'),
                        DB::raw('SUM(orders.total_amount) as revenue'),
                        DB::raw('COUNT(*) as orders')
                    )
                    ->groupBy('date')
                    ->orderBy('date')
                    ->get(),
            ],
        ]);
    }

    /**
     * Format category name from snake_case to Title Case
     */
    private function formatCategoryName(string $category): string
    {
        return ucwords(str_replace('_', ' ', $category));
    }

    /**
     * Get dashboard overview statistics
     */
    public function overview(): JsonResponse
    {
        // The store roles legitimately hold dashboard.view, so a shop gets a
        // dashboard — of its own figures. Everything below this point is a
        // platform-wide aggregate and is not theirs to see.
        $user = request()->user();

        if ($user && ! $user->isPlatformAdmin()) {
            return $this->storeOverview($user);
        }

        $today = Carbon::today();
        $thisMonth = Carbon::now()->startOfMonth();
        $lastMonth = Carbon::now()->subMonth()->startOfMonth();
        $thisYear = Carbon::now()->startOfYear();

        // Revenue statistics
        $todayRevenue = Order::where('payment_status', Order::PAYMENT_PAID)
                            ->whereDate('created_at', $today)
                            ->sum('total_amount');

        $monthRevenue = Order::where('payment_status', Order::PAYMENT_PAID)
                            ->where('created_at', '>=', $thisMonth)
                            ->sum('total_amount');

        $lastMonthRevenue = Order::where('payment_status', Order::PAYMENT_PAID)
                                ->whereBetween('created_at', [$lastMonth, $thisMonth])
                                ->sum('total_amount');

        $yearRevenue = Order::where('payment_status', Order::PAYMENT_PAID)
                           ->where('created_at', '>=', $thisYear)
                           ->sum('total_amount');

        // Order statistics
        $totalOrders = Order::count();
        $todayOrders = Order::whereDate('created_at', $today)->count();
        $monthOrders = Order::where('created_at', '>=', $thisMonth)->count();
        $pendingOrders = Order::where('status', Order::STATUS_PENDING)->count();
        $processingOrders = Order::where('status', Order::STATUS_PROCESSING)->count();
        $shippedOrders = Order::where('status', Order::STATUS_SHIPPED)->count();
        $deliveredOrders = Order::where('status', Order::STATUS_DELIVERED)->count();

        // Product statistics
        $totalProducts = Product::active()->count();
        // The Settings page owns this number — see SystemSetting::lowStockThreshold().
        $lowStockProducts = Product::active()
            ->where('stock_quantity', '<=', \App\Models\SystemSetting::lowStockThreshold())
            ->count();
        $outOfStockProducts = Product::active()->where('stock_quantity', 0)->count();
        $featuredProducts = Product::active()->featured()->count();

        // Customer statistics
        $totalCustomers = User::where('role', 'customer')->count();
        $newCustomersToday = User::where('role', 'customer')
                                ->whereDate('created_at', $today)
                                ->count();
        $newCustomersMonth = User::where('role', 'customer')
                               ->where('created_at', '>=', $thisMonth)
                               ->count();

        // Customer lifetime value
        $avgLifetimeValue = $totalCustomers > 0 ? $yearRevenue / $totalCustomers : 0;

        // Review statistics
        $totalReviews = Review::count();
        $pendingReviews = Review::where('is_approved', false)->count();
        $averageRating = Review::where('is_approved', true)->avg('rating');

        // Active promotions
        $activeCoupons = Coupon::active()->count();
        $activeSales = SaleEvent::active()->count();

        // Recent activity
        $recentOrders = Order::with('user')
                            ->latest()
                            ->limit(5)
                            ->get();

        $recentReviews = Review::with(['user', 'product'])
                             ->latest()
                             ->limit(5)
                             ->get();

        // Sales chart data (last 30 days)
        $salesChart = Order::where('payment_status', Order::PAYMENT_PAID)
                          ->where('created_at', '>=', Carbon::now()->subDays(30))
                          ->select(
                              DB::raw('DATE(created_at) as date'),
                              DB::raw('SUM(total_amount) as revenue'),
                              DB::raw('COUNT(*) as orders')
                          )
                          ->groupBy('date')
                          ->orderBy('date')
                          ->get();

        // Top selling products
        $topProducts = Product::withCount(['orderItems as total_sold' => function($query) {
                                $query->select(DB::raw('SUM(quantity)'));
                            }])
                            ->orderBy('total_sold', 'desc')
                            ->limit(5)
                            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'revenue' => [
                    'today' => $todayRevenue,
                    'month' => $monthRevenue,
                    'last_month' => $lastMonthRevenue,
                    'year' => $yearRevenue,
                    'month_growth' => $lastMonthRevenue > 0 ? 
                        (($monthRevenue - $lastMonthRevenue) / $lastMonthRevenue) * 100 : 0
                ],
                'orders' => [
                    'total' => $totalOrders,
                    'today' => $todayOrders,
                    'month' => $monthOrders,
                    'pending' => $pendingOrders,
                    'processing' => $processingOrders,
                    'shipped' => $shippedOrders,
                    'delivered' => $deliveredOrders
                ],
                'products' => [
                    'total' => $totalProducts,
                    'low_stock' => $lowStockProducts,
                    'out_of_stock' => $outOfStockProducts,
                    'featured' => $featuredProducts
                ],
                'customers' => [
                    'total' => $totalCustomers,
                    'new_today' => $newCustomersToday,
                    'new_month' => $newCustomersMonth,
                    'lifetime_value' => $avgLifetimeValue
                ],
                'reviews' => [
                    'total' => $totalReviews,
                    'pending' => $pendingReviews,
                    'average_rating' => round($averageRating, 2)
                ],
                'promotions' => [
                    'active_coupons' => $activeCoupons,
                    'active_sales' => $activeSales
                ],
                'recent_activity' => [
                    'orders' => $recentOrders,
                    'reviews' => $recentReviews
                ],
                'charts' => [
                    'sales' => $salesChart,
                    'top_products' => $topProducts
                ]
            ]
        ]);
    }

    /**
     * Get sales analytics
     */
    public function salesAnalytics(): JsonResponse
    {
        if ($denied = $this->denyNonPlatformAdmin()) {
            return $denied;
        }

        $period = request('period', '30'); // days
        $startDate = Carbon::now()->subDays($period);

        // Sales by period
        $salesData = Order::where('payment_status', Order::PAYMENT_PAID)
                         ->where('created_at', '>=', $startDate)
                         ->select(
                             DB::raw('DATE(created_at) as date'),
                             DB::raw('SUM(total_amount) as revenue'),
                             DB::raw('COUNT(*) as orders'),
                             DB::raw('AVG(total_amount) as avg_order_value')
                         )
                         ->groupBy('date')
                         ->orderBy('date')
                         ->get();

        // Category performance
        $categoryPerformance = DB::table('orders')
                         ->join('order_items', 'orders.id', '=', 'order_items.order_id')
                         ->join('products', 'order_items.product_id', '=', 'products.id')
                         ->join('categories', 'products.category_id', '=', 'categories.id')
                         ->where('orders.payment_status', Order::PAYMENT_PAID)
                         ->where('orders.created_at', '>=', $startDate)
                         ->select(
                             'categories.name as name',
                             'categories.slug as slug',
                             DB::raw('SUM(order_items.total) as revenue'),
                             DB::raw('SUM(order_items.quantity) as quantity_sold')
                         )
                         ->groupBy('categories.id', 'categories.name', 'categories.slug')
                         ->orderBy('revenue', 'desc')
                         ->get()
                         ->map(function($category) {
                             return [
                                 'name' => $category->name,
                                 'slug' => $category->slug,
                                 'revenue' => (float) $category->revenue,
                                 'quantity_sold' => (int) $category->quantity_sold
                             ];
                         });

        // Payment method statistics
        $paymentMethods = PaymentTransaction::where('status', PaymentTransaction::STATUS_SUCCESS)
                                          ->where('created_at', '>=', $startDate)
                                          ->select('gateway', DB::raw('COUNT(*) as count'), DB::raw('SUM(amount) as total'))
                                          ->groupBy('gateway')
                                          ->get();

        // Calculate performance metrics
        $totalVisitors = 1000; // This would come from analytics
        $totalOrders = $salesData->sum('orders');
        $conversionRate = $totalVisitors > 0 ? ($totalOrders / $totalVisitors) * 100 : 0;
        
        $returningCustomers = User::where('role', 'customer')
                                 ->whereHas('orders', function($q) {
                                     $q->havingRaw('COUNT(*) > 1');
                                 })->count();
        $totalCustomers = User::where('role', 'customer')->count();
        $returnCustomerRate = $totalCustomers > 0 ? ($returningCustomers / $totalCustomers) * 100 : 0;
        
        // Cart abandonment (mock data - would need cart tracking)
        $cartAbandonmentRate = 68.2;
        
        // Top selling products
        $topProducts = Product::select([
                                 'products.id',
                                 'products.name',
                                 'products.price',
                                 'products.category_id',
                                 'products.stock_quantity'
                             ])
                             ->join('order_items', 'products.id', '=', 'order_items.product_id')
                             ->join('orders', 'order_items.order_id', '=', 'orders.id')
                             ->where('orders.payment_status', Order::PAYMENT_PAID)
                             ->where('orders.created_at', '>=', $startDate)
                             ->selectRaw('SUM(order_items.quantity) as total_sold, SUM(order_items.total) as revenue')
                             ->groupBy('products.id', 'products.name', 'products.price', 'products.category_id', 'products.stock_quantity')
                             ->orderBy('total_sold', 'desc')
                             ->limit(10)
                             ->with('category')
                             ->get()
                             ->map(function($product) {
                                 // Get the full product with images
                                 $fullProduct = Product::find($product->id);
                                 return [
                                     'id' => $product->id,
                                     'name' => $product->name,
                                     'price' => $product->price,
                                     'image' => $fullProduct && $fullProduct->images ? (is_array($fullProduct->images) ? ($fullProduct->images[0] ?? null) : $fullProduct->images) : null,
                                     'category' => $product->category?->name ?? 'Uncategorized',
                                     'category_slug' => $product->category?->slug,
                                     'images' => $fullProduct ? $fullProduct->images : null,
                                     'stock_quantity' => $product->stock_quantity,
                                     'total_sold' => (int) $product->total_sold,
                                     'revenue' => (float) $product->revenue
                                 ];
                             });

        // Calculate growth indicators based on actual data
        $previousPeriodStart = Carbon::now()->subDays($period * 2);
        $previousPeriodEnd = $startDate;
        
        $previousRevenue = Order::where('payment_status', Order::PAYMENT_PAID)
                                ->whereBetween('created_at', [$previousPeriodStart, $previousPeriodEnd])
                                ->sum('total_amount');
        
        $currentRevenue = $salesData->sum('revenue');
        $revenueGrowth = $previousRevenue > 0 ? (($currentRevenue - $previousRevenue) / $previousRevenue) * 100 : 0;
        
        $previousCustomers = User::where('role', 'customer')
                                ->whereBetween('created_at', [$previousPeriodStart, $previousPeriodEnd])
                                ->count();
        
        $currentCustomers = User::where('role', 'customer')
                               ->where('created_at', '>=', $startDate)
                               ->count();
        
        $customerGrowth = $previousCustomers > 0 ? (($currentCustomers - $previousCustomers) / $previousCustomers) * 100 : 0;
        
        $previousOrders = Order::where('payment_status', Order::PAYMENT_PAID)
                              ->whereBetween('created_at', [$previousPeriodStart, $previousPeriodEnd])
                              ->count();
        
        $currentOrders = $salesData->sum('orders');
        $orderGrowth = $previousOrders > 0 ? (($currentOrders - $previousOrders) / $previousOrders) * 100 : 0;

        // Time-based insights (mock data - would need detailed analytics)
        $peakSalesHour = "2:00 PM";
        $bestSalesDay = "Saturday";
        $avgOrderProcessing = "2.3 hours";

        return response()->json([
            'success' => true,
            'data' => [
                'period' => $period,
                'sales_timeline' => $salesData,
                'category_performance' => $categoryPerformance,
                'payment_methods' => $paymentMethods,
                'top_products' => $topProducts,
                'top_products_summary' => [
                    'total_products' => $topProducts->count(),
                    'total_revenue' => $topProducts->sum('revenue'),
                    'total_units_sold' => $topProducts->sum('total_sold'),
                    'best_seller' => $topProducts->first()
                ],
                'summary' => [
                    'total_revenue' => (float) $salesData->sum('revenue'),
                    'total_orders' => (int) $salesData->sum('orders'),
                    'average_order_value' => (float) $salesData->avg('avg_order_value')
                ],
                'performance_metrics' => [
                    'conversion_rate' => round($conversionRate, 1),
                    'return_customer_rate' => round($returnCustomerRate, 1),
                    'cart_abandonment_rate' => $cartAbandonmentRate
                ],
                'time_insights' => [
                    'peak_sales_hour' => $peakSalesHour,
                    'best_sales_day' => $bestSalesDay,
                    'avg_order_processing' => $avgOrderProcessing
                ],
                'growth_indicators' => [
                    'revenue_growth' => round($revenueGrowth, 1),
                    'customer_growth' => round($customerGrowth, 1),
                    'order_volume_growth' => round($orderGrowth, 1),
                    'previous_period_revenue' => (float) $previousRevenue,
                    'current_period_revenue' => (float) $currentRevenue
                ]
            ]
        ]);
    }

    /**
     * Get inventory alerts
     */
    public function inventoryAlerts(): JsonResponse
    {
        if ($denied = $this->denyNonPlatformAdmin()) {
            return $denied;
        }

        $lowStockThreshold = \App\Models\SystemSetting::lowStockThreshold();
        
        $lowStockProducts = Product::active()
                                 ->where('stock_quantity', '>', 0)
                                 ->where('stock_quantity', '<=', $lowStockThreshold)
                                 ->select('id', 'name', 'stock_quantity', 'price', 'category_id', 'images')
                                 ->get();

        $outOfStockProducts = Product::active()
                                   ->where('stock_quantity', 0)
                                   ->select('id', 'name', 'stock_quantity', 'price', 'category_id', 'images')
                                   ->get();

        $overstockProducts = Product::active()
                                  ->where('stock_quantity', '>', 100)
                                  ->select('id', 'name', 'stock_quantity', 'price', 'category_id', 'images')
                                  ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'low_stock' => $lowStockProducts,
                'out_of_stock' => $outOfStockProducts,
                'overstock' => $overstockProducts,
                'alerts_count' => [
                    'low_stock' => $lowStockProducts->count(),
                    'out_of_stock' => $outOfStockProducts->count(),
                    'overstock' => $overstockProducts->count()
                ]
            ]
        ]);
    }

    /**
     * Get customer insights
     */
    public function customerInsights(): JsonResponse
    {
        if ($denied = $this->denyNonPlatformAdmin()) {
            return $denied;
        }

        $period = request('period', '30');
        $startDate = Carbon::now()->subDays($period);

        // Customer acquisition
        $newCustomers = User::where('role', 'customer')
                           ->where('created_at', '>=', $startDate)
                           ->select(
                               DB::raw('DATE(created_at) as date'),
                               DB::raw('COUNT(*) as new_customers')
                           )
                           ->groupBy('date')
                           ->orderBy('date')
                           ->get();

        // Top customers by spending
        $topCustomers = User::where('role', 'customer')
                           ->withSum(['orders as total_spent' => function($query) {
                               $query->where('payment_status', Order::PAYMENT_PAID);
                           }], 'total_amount')
                           ->withCount(['orders as total_orders' => function($query) {
                               $query->where('payment_status', Order::PAYMENT_PAID);
                           }])
                           ->orderBy('total_spent', 'desc')
                           ->limit(10)
                           ->get();

        // Customer lifetime value - calculate properly
        $customerTotals = User::where('role', 'customer')
                             ->withSum(['orders as total_spent' => function($query) {
                                 $query->where('payment_status', Order::PAYMENT_PAID);
                             }], 'total_amount')
                             ->get()
                             ->pluck('total_spent')
                             ->filter(); // Remove null values
        
        $avgLifetimeValue = $customerTotals->count() > 0 ? $customerTotals->avg() : 0;

        return response()->json([
            'success' => true,
            'data' => [
                'acquisition_timeline' => $newCustomers,
                'top_customers' => $topCustomers,
                'average_lifetime_value' => $avgLifetimeValue,
                'total_customers' => User::where('role', 'customer')->count()
            ]
        ]);
    }
}
