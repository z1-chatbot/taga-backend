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

        // The same window the platform dashboard uses, so a shop owner can ask
        // about a date range on their own figures too.
        $window = $this->reportWindow();
        $startDate = $window['start'];
        $endDate = $window['end'];

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

        // Qualified with the table name: these queries join through order_items
        // and products, all three of which have a created_at.
        $windowRevenue = (float) $paid()->whereBetween('orders.created_at', [$startDate, $endDate])->sum('total_amount');
        $previousWindowRevenue = (float) $paid()
            ->whereBetween('orders.created_at', [$window['previous_start'], $window['previous_end']])
            ->sum('total_amount');

        $windowOrders = $orders()->whereBetween('orders.created_at', [$startDate, $endDate])->count();
        $previousWindowOrders = $orders()
            ->whereBetween('orders.created_at', [$window['previous_start'], $window['previous_end']])
            ->count();

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
                'window' => [
                    'from' => $startDate->toDateString(),
                    'to' => $endDate->toDateString(),
                    'days' => $window['days'],
                    'revenue' => $windowRevenue,
                    'revenue_growth' => $this->growth($windowRevenue, $previousWindowRevenue),
                    'orders' => $windowOrders,
                    'orders_growth' => $this->growth($windowOrders, $previousWindowOrders),
                ],
                'recent_orders' => $orders()->with('user')->latest()->limit(5)->get(),
                'sales_chart' => $paid()
                    ->whereBetween('orders.created_at', [$startDate, $endDate])
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

        /*
         * The dashboard answers two different questions and must not mix them.
         *
         * "How is the shop right now" is stock levels, pending orders, unapproved
         * reviews — the state of things, which no date range applies to. Filtering
         * "products out of stock" by last March is meaningless.
         *
         * "How did we trade" is revenue, orders placed, customers gained. Those
         * are events, they happened on a date, and they are what the window
         * below covers. The named cards (today, this month, this year) stay as
         * they are; the window is reported alongside them.
         */
        $window = $this->reportWindow();
        $startDate = $window['start'];
        $endDate = $window['end'];

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

        // Sales chart data, over the window rather than a fixed 30 days.
        $salesChart = Order::where('payment_status', Order::PAYMENT_PAID)
                          ->whereBetween('created_at', [$startDate, $endDate])
                          ->select(
                              DB::raw('DATE(created_at) as date'),
                              DB::raw('SUM(total_amount) as revenue'),
                              DB::raw('COUNT(*) as orders')
                          )
                          ->groupBy('date')
                          ->orderBy('date')
                          ->get();

        /*
         * Top sellers over the window.
         *
         * This counted every line ever sold, which is a different question and a
         * misleading one to put beside a date filter: the same five products
         * would sit there whatever range was picked, because a product that sold
         * well two years ago outranks one selling well this week.
         */
        $topProducts = Product::withCount(['orderItems as total_sold' => function ($query) use ($startDate, $endDate) {
                                $query->select(DB::raw('SUM(quantity)'))
                                      ->whereHas('order', fn ($order) => $order
                                          ->whereBetween('created_at', [$startDate, $endDate]));
                            }])
                            // Sold at least once inside the window. Without this
                            // an empty window still returns five products, each
                            // with a null total — a "top sellers" list of things
                            // that sold nothing.
                            ->whereHas('orderItems.order', fn ($order) => $order
                                ->whereBetween('orders.created_at', [$startDate, $endDate]))
                            ->orderBy('total_sold', 'desc')
                            ->limit(5)
                            ->get();

        // Trade over the window, against the window of equal length before it.
        $windowRevenue = (float) Order::where('payment_status', Order::PAYMENT_PAID)
                                      ->whereBetween('created_at', [$startDate, $endDate])
                                      ->sum('total_amount');

        $previousRevenue = (float) Order::where('payment_status', Order::PAYMENT_PAID)
                                        ->whereBetween('created_at', [$window['previous_start'], $window['previous_end']])
                                        ->sum('total_amount');

        $windowOrders = Order::whereBetween('created_at', [$startDate, $endDate])->count();
        $previousOrders = Order::whereBetween('created_at', [$window['previous_start'], $window['previous_end']])->count();

        $windowCustomers = User::where('role', 'customer')
                               ->whereBetween('created_at', [$startDate, $endDate])
                               ->count();

        $previousCustomers = User::where('role', 'customer')
                                 ->whereBetween('created_at', [$window['previous_start'], $window['previous_end']])
                                 ->count();

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
                ],
                'window' => [
                    'from' => $startDate->toDateString(),
                    'to' => $endDate->toDateString(),
                    'days' => $window['days'],
                    'revenue' => $windowRevenue,
                    'revenue_growth' => $this->growth($windowRevenue, $previousRevenue),
                    'orders' => $windowOrders,
                    'orders_growth' => $this->growth($windowOrders, $previousOrders),
                    'new_customers' => $windowCustomers,
                    'new_customers_growth' => $this->growth($windowCustomers, $previousCustomers),
                ]
            ]
        ]);
    }

    /**
     * Minutes as something a person reads at a glance: "40 minutes", "2.3
     * hours", "1.5 days". Whole units below ten, one decimal above, because
     * "2.3 hours" is useful and "2.34 hours" is false precision on an average.
     */
    private function humanDuration(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes . ' ' . ($minutes === 1 ? 'minute' : 'minutes');
        }

        $hours = $minutes / 60;
        if ($hours < 48) {
            return round($hours, 1) . ' hours';
        }

        return round($hours / 24, 1) . ' days';
    }

    /**
     * Get sales analytics
     */
    /**
     * Movement between two windows of equal length, as a percentage.
     *
     * Nothing to compare against reads as no movement rather than as infinite
     * growth: a first week of trade is not "+100%", it is the first week.
     */
    private function growth(float $current, float $previous): float
    {
        if ($previous <= 0) {
            return 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * The window a report covers.
     *
     * Every figure on the insights page used to be "the last N days", which
     * answers how trade is going but not how it went — there was no way to ask
     * about a campaign week, a month that has closed, or the same fortnight a
     * year ago. An explicit `from`/`to` pair now does that, and `period` stays
     * as the shorthand so a caller that sends neither keeps the last 30 days.
     *
     * Both ends are inclusive whole days: `to=2026-08-28` means up to the last
     * second of the 28th, not midnight at its start, which would silently drop
     * a day of trade.
     *
     * Returns the window, the window of equal length immediately before it (so
     * growth compares like with like), and the day count.
     */
    private function reportWindow(): array
    {
        $from = request('from');
        $to = request('to');

        if ($from || $to) {
            // One end alone is a legitimate question — "since we launched",
            // "everything up to the audit". The other end fills itself in.
            $start = $from ? Carbon::parse($from)->startOfDay() : Carbon::parse($to)->startOfDay()->subDays(29);
            $end = $to ? Carbon::parse($to)->endOfDay() : Carbon::now()->endOfDay();

            // Handed to us backwards, which is a slip rather than a request for
            // an empty report.
            if ($start->greaterThan($end)) {
                [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
            }
        } else {
            $days = max(1, (int) request('period', 30));
            $end = Carbon::now();
            $start = Carbon::now()->subDays($days);
        }

        // Whole days, and never zero — a single-day window compares against the
        // day before it rather than against itself.
        $days = max(1, (int) ceil($start->diffInDays($end)));

        return [
            'start' => $start,
            'end' => $end,
            'days' => $days,
            'previous_start' => $start->copy()->subDays($days),
            'previous_end' => $start->copy(),
        ];
    }

    public function salesAnalytics(): JsonResponse
    {
        if ($denied = $this->denyNonPlatformAdmin()) {
            return $denied;
        }

        $window = $this->reportWindow();
        $startDate = $window['start'];
        $endDate = $window['end'];
        $period = $window['days'];

        // Sales by period
        $salesData = Order::where('payment_status', Order::PAYMENT_PAID)
                         ->whereBetween('created_at', [$startDate, $endDate])
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
                         ->whereBetween('orders.created_at', [$startDate, $endDate])
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
                                          ->whereBetween('created_at', [$startDate, $endDate])
                                          ->select('gateway', DB::raw('COUNT(*) as count'), DB::raw('SUM(amount) as total'))
                                          ->groupBy('gateway')
                                          ->get();

        /*
         * Repeat purchase rate: of the customers who bought in this period, how
         * many had bought before.
         *
         * Two things were wrong with the previous version. It counted every
         * customer who had ever placed more than one order, against every
         * customer account ever created — so it ignored the period filter
         * entirely and sat unchanged next to figures that did move. And it
         * counted orders regardless of whether they were ever paid for, so an
         * abandoned checkout made someone a repeat customer.
         */
        $buyerIds = Order::where('payment_status', Order::PAYMENT_PAID)
                         ->whereBetween('created_at', [$startDate, $endDate])
                         ->whereNotNull('user_id')
                         ->distinct()
                         ->pluck('user_id');

        $returningCustomers = Order::where('payment_status', Order::PAYMENT_PAID)
                                   ->whereIn('user_id', $buyerIds)
                                   ->where('created_at', '<', $startDate)
                                   ->distinct()
                                   ->count('user_id');

        $returnCustomerRate = $buyerIds->count() > 0
            ? ($returningCustomers / $buyerIds->count()) * 100
            : 0;
        
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
                             ->whereBetween('orders.created_at', [$startDate, $endDate])
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
        $previousPeriodStart = $window['previous_start'];
        $previousPeriodEnd = $window['previous_end'];
        
        $previousRevenue = Order::where('payment_status', Order::PAYMENT_PAID)
                                ->whereBetween('created_at', [$previousPeriodStart, $previousPeriodEnd])
                                ->sum('total_amount');
        
        $currentRevenue = $salesData->sum('revenue');
        $revenueGrowth = $previousRevenue > 0 ? (($currentRevenue - $previousRevenue) / $previousRevenue) * 100 : 0;
        
        $previousCustomers = User::where('role', 'customer')
                                ->whereBetween('created_at', [$previousPeriodStart, $previousPeriodEnd])
                                ->count();
        
        $currentCustomers = User::where('role', 'customer')
                               ->whereBetween('created_at', [$startDate, $endDate])
                               ->count();
        
        $customerGrowth = $previousCustomers > 0 ? (($currentCustomers - $previousCustomers) / $previousCustomers) * 100 : 0;
        
        $previousOrders = Order::where('payment_status', Order::PAYMENT_PAID)
                              ->whereBetween('created_at', [$previousPeriodStart, $previousPeriodEnd])
                              ->count();
        
        $currentOrders = $salesData->sum('orders');
        $orderGrowth = $previousOrders > 0 ? (($currentOrders - $previousOrders) / $previousOrders) * 100 : 0;

        /*
         * Time-based insights, measured rather than asserted.
         *
         * These three were hardcoded to "2:00 PM", "Saturday" and "2.3 hours",
         * with a comment saying detailed analytics would be needed. They would
         * not: every figure here comes from columns the orders table already
         * carries. The invented values were also plausible enough to be acted
         * on, which is the dangerous kind of wrong — a merchandiser could have
         * scheduled a campaign around a peak hour that was never measured.
         *
         * Each returns null when the period holds nothing to measure, and the
         * dashboard shows "Not enough data" rather than a confident figure
         * standing on one order.
         */
        $paidInPeriod = fn () => Order::where('payment_status', Order::PAYMENT_PAID)
                                      ->whereBetween('created_at', [$startDate, $endDate]);

        $peakHourRow = $paidInPeriod()
            ->select(DB::raw('HOUR(created_at) as hour'), DB::raw('COUNT(*) as orders'))
            ->groupBy('hour')
            ->orderByDesc('orders')
            ->first();

        $peakSalesHour = $peakHourRow
            ? Carbon::createFromTime((int) $peakHourRow->hour)->format('g:00 A')
            : null;

        $bestDayRow = $paidInPeriod()
            ->select(DB::raw('DAYOFWEEK(created_at) as weekday'), DB::raw('COUNT(*) as orders'))
            ->groupBy('weekday')
            ->orderByDesc('orders')
            ->first();

        // MySQL's DAYOFWEEK is 1-indexed from Sunday; Carbon's dayOfWeek is
        // 0-indexed from Sunday.
        $bestSalesDay = $bestDayRow
            ? Carbon::now()->startOfWeek(Carbon::SUNDAY)
                           ->addDays((int) $bestDayRow->weekday - 1)
                           ->format('l')
            : null;

        // Order placed to order dispatched. `shipped_at` is the first moment
        // the pharmacy has genuinely finished with it, and orders still sitting
        // unshipped are excluded rather than counted as instant.
        $avgProcessingMinutes = $paidInPeriod()
            ->whereNotNull('shipped_at')
            ->avg(DB::raw('TIMESTAMPDIFF(MINUTE, created_at, shipped_at)'));

        $avgOrderProcessing = $avgProcessingMinutes !== null
            ? $this->humanDuration((int) round($avgProcessingMinutes))
            : null;

        return response()->json([
            'success' => true,
            'data' => [
                'period' => $period,
                // Echoed back so the page labels what it is showing rather than
                // assume it got the window it asked for.
                'from' => $startDate->toDateString(),
                'to' => $endDate->toDateString(),
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
                /*
                 * `conversion_rate` and `cart_abandonment_rate` used to sit
                 * here and are deliberately gone rather than fixed.
                 *
                 * Conversion needs a visitor count, and nothing on this
                 * platform counts visitors — the old figure divided orders by a
                 * literal 1000. Abandonment needs carts that were started and
                 * never completed, but a cart row is deleted once it becomes an
                 * order, so the converted half leaves no trace to measure
                 * against. Both are answerable, and both need data we do not
                 * yet collect. A blank is honest; 68.2% was not.
                 */
                'performance_metrics' => [
                    'return_customer_rate' => round($returnCustomerRate, 1),
                    'repeat_customers' => $returningCustomers,
                    'period_customers' => $buyerIds->count(),
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

        $window = $this->reportWindow();
        $startDate = $window['start'];
        $endDate = $window['end'];
        $period = $window['days'];

        // Customer acquisition
        $newCustomers = User::where('role', 'customer')
                           ->whereBetween('created_at', [$startDate, $endDate])
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
                'from' => $startDate->toDateString(),
                'to' => $endDate->toDateString(),
                'acquisition_timeline' => $newCustomers,
                'top_customers' => $topCustomers,
                'average_lifetime_value' => $avgLifetimeValue,
                'total_customers' => User::where('role', 'customer')->count()
            ]
        ]);
    }
}
