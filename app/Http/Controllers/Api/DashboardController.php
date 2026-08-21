<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Product;
use App\Models\Order;
use App\Models\Review;
use App\Models\Coupon;
use App\Models\SaleEvent;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    /**
     * Get dashboard overview data
     */
    public function overview(): JsonResponse
    {
        try {
            // Calculate revenue (mock data for now)
            $todayRevenue = 1250.00;
            $monthRevenue = 45000.00;
            $growth = 12.5;

            // Get order statistics
            $totalOrders = Order::count();
            $pendingOrders = Order::where('status', 'pending')->count();
            $processingOrders = Order::where('status', 'processing')->count();
            $shippedOrders = Order::where('status', 'shipped')->count();

            // Get product statistics
            $totalProducts = Product::count();
            // `<=`, not `<`: a product sitting exactly on the threshold is low.
            $lowStockProducts = Product::where('stock_quantity', '<=', \App\Models\SystemSetting::lowStockThreshold())->count();
            $outOfStockProducts = Product::where('stock_quantity', 0)->count();

            // Get customer statistics
            $totalCustomers = User::where('role', 'customer')->count();
            $newTodayCustomers = User::where('role', 'customer')
                                   ->whereDate('created_at', today())
                                   ->count();
            $avgLifetimeValue = 299.99; // Mock data

            // Get review statistics
            $totalReviews = Review::count();
            $avgRating = Review::avg('rating') ?? 0;
            $pendingReviews = Review::where('is_approved', false)->count();

            // Get promotion statistics
            $activeCoupons = Coupon::where('is_active', true)->count();
            $activeSales = SaleEvent::where('is_active', true)->count();

            $data = [
                'revenue' => [
                    'today' => $todayRevenue,
                    'month' => $monthRevenue,
                    'growth' => $growth
                ],
                'orders' => [
                    'total' => $totalOrders,
                    'pending' => $pendingOrders,
                    'processing' => $processingOrders,
                    'shipped' => $shippedOrders
                ],
                'products' => [
                    'total' => $totalProducts,
                    'low_stock' => $lowStockProducts,
                    'out_of_stock' => $outOfStockProducts
                ],
                'customers' => [
                    'total' => $totalCustomers,
                    'new_today' => $newTodayCustomers,
                    'lifetime_value' => $avgLifetimeValue
                ],
                'reviews' => [
                    'total' => $totalReviews,
                    'average_rating' => round($avgRating, 1),
                    'pending' => $pendingReviews
                ],
                'promotions' => [
                    'active_coupons' => $activeCoupons,
                    'active_sales' => $activeSales
                ]
            ];

            return response()->json([
                'success' => true,
                'data' => $data
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch dashboard data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get sales analytics
     */
    public function salesAnalytics(): JsonResponse
    {
        // Mock sales analytics data
        $data = [
            'daily_sales' => [
                ['date' => '2024-01-01', 'amount' => 1200],
                ['date' => '2024-01-02', 'amount' => 1500],
                ['date' => '2024-01-03', 'amount' => 980],
                ['date' => '2024-01-04', 'amount' => 2100],
                ['date' => '2024-01-05', 'amount' => 1800],
            ],
            'top_products' => [
                ['name' => 'Brazilian Straight Wig', 'sales' => 45],
                ['name' => 'Curly Lace Front Wig', 'sales' => 32],
                ['name' => 'Body Wave Hair Bundle', 'sales' => 28],
            ]
        ];

        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Get inventory alerts
     */
    public function inventoryAlerts(): JsonResponse
    {
        $lowStockProducts = Product::where('stock_quantity', '<', 10)
                                  ->select('id', 'name', 'stock_quantity')
                                  ->get();

        $outOfStockProducts = Product::where('stock_quantity', 0)
                                    ->select('id', 'name', 'stock_quantity')
                                    ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'low_stock' => $lowStockProducts,
                'out_of_stock' => $outOfStockProducts
            ]
        ]);
    }

    /**
     * Get customer insights
     */
    public function customerInsights(): JsonResponse
    {
        $totalCustomers = User::where('role', 'customer')->count();
        $newThisMonth = User::where('role', 'customer')
                           ->whereMonth('created_at', now()->month)
                           ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'total_customers' => $totalCustomers,
                'new_this_month' => $newThisMonth,
                'growth_rate' => $totalCustomers > 0 ? ($newThisMonth / $totalCustomers) * 100 : 0
            ]
        ]);
    }
}
