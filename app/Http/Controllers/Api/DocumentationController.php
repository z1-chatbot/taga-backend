<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class DocumentationController extends Controller
{
    /**
     * Get API documentation
     */
    public function index(): JsonResponse
    {
        $documentation = [
            'title' => 'Hair Ecommerce API Documentation',
            'version' => '1.0.0',
            'description' => 'Complete API documentation for Hair Ecommerce Backend',
            'base_url' => url('/api/v1'),
            'endpoints' => [
                'Authentication' => [
                    'POST /api/v1/register' => 'Register new user',
                    'POST /api/v1/login' => 'Login user',
                    'POST /api/v1/logout' => 'Logout user (requires auth)',
                    'GET /api/v1/user' => 'Get authenticated user (requires auth)'
                ],
                'Products' => [
                    'GET /api/v1/products' => 'List all products with filters',
                    'GET /api/v1/products/{id}' => 'Get single product',
                    'GET /api/v1/products/featured' => 'Get featured products',
                    'GET /api/v1/products/search' => 'Search products'
                ],
                'Categories' => [
                    'GET /api/v1/categories' => 'List all categories',
                    'GET /api/v1/categories/{id}' => 'Get single category',
                    'GET /api/v1/categories/{id}/products' => 'Get products in category'
                ],
                'Cart (Guest)' => [
                    'GET /api/v1/cart' => 'Get cart items',
                    'POST /api/v1/cart' => 'Add item to cart',
                    'PUT /api/v1/cart/{id}' => 'Update cart item',
                    'DELETE /api/v1/cart/{id}' => 'Remove cart item',
                    'DELETE /api/v1/cart' => 'Clear cart'
                ],
                'Orders (Auth Required)' => [
                    'GET /api/v1/orders' => 'Get user orders',
                    'POST /api/v1/orders' => 'Create new order',
                    'GET /api/v1/orders/{id}' => 'Get single order',
                    'PUT /api/v1/orders/{id}/cancel' => 'Cancel order'
                ],
                'Reviews (Auth Required)' => [
                    'GET /api/v1/products/{id}/reviews' => 'Get product reviews',
                    'POST /api/v1/products/{id}/reviews' => 'Create review',
                    'PUT /api/v1/reviews/{id}' => 'Update review',
                    'DELETE /api/v1/reviews/{id}' => 'Delete review'
                ],
                'Wishlist (Auth Required)' => [
                    'GET /api/v1/wishlist' => 'Get wishlist items',
                    'POST /api/v1/wishlist' => 'Add to wishlist',
                    'DELETE /api/v1/wishlist/{id}' => 'Remove from wishlist',
                    'POST /api/v1/wishlist/toggle' => 'Toggle wishlist item'
                ],
                'Payments (Auth Required)' => [
                    'POST /api/v1/payments/initialize' => 'Initialize payment',
                    'POST /api/v1/payments/verify' => 'Verify payment',
                    'GET /api/v1/payments/{order}/status' => 'Get payment status'
                ],
                'Admin - Dashboard' => [
                    'GET /api/v1/admin/dashboard/overview' => 'Dashboard overview',
                    'GET /api/v1/admin/dashboard/sales-analytics' => 'Sales analytics',
                    'GET /api/v1/admin/dashboard/inventory-alerts' => 'Inventory alerts',
                    'GET /api/v1/admin/dashboard/customer-insights' => 'Customer insights'
                ],
                'Admin - Automation' => [
                    'GET /api/v1/admin/automation/settings' => 'Get automation settings',
                    'PUT /api/v1/admin/automation/settings' => 'Update automation settings',
                    'GET /api/v1/admin/automation/status' => 'Get automation status',
                    'PUT /api/v1/admin/automation/category/{category}/toggle' => 'Toggle category automation',
                    'PUT /api/v1/admin/automation/holiday/{holiday}' => 'Update holiday settings'
                ],
                'Admin - Coupons' => [
                    'GET /api/v1/admin/coupons' => 'List coupons',
                    'POST /api/v1/admin/coupons' => 'Create coupon',
                    'GET /api/v1/admin/coupons/{id}' => 'Get coupon',
                    'PUT /api/v1/admin/coupons/{id}' => 'Update coupon',
                    'DELETE /api/v1/admin/coupons/{id}' => 'Delete coupon'
                ],
                'Admin - Sale Events' => [
                    'GET /api/v1/admin/sale-events' => 'List sale events',
                    'POST /api/v1/admin/sale-events' => 'Create sale event',
                    'GET /api/v1/admin/sale-events/{id}' => 'Get sale event',
                    'PUT /api/v1/admin/sale-events/{id}' => 'Update sale event',
                    'DELETE /api/v1/admin/sale-events/{id}' => 'Delete sale event'
                ]
            ],
            'authentication' => [
                'type' => 'Bearer Token',
                'header' => 'Authorization: Bearer {token}',
                'note' => 'Get token from login endpoint'
            ],
            'sample_requests' => [
                'Login' => [
                    'method' => 'POST',
                    'url' => '/api/v1/login',
                    'body' => [
                        'email' => 'admin@hairlux.com',
                        'password' => 'password123'
                    ]
                ],
                'Get Products' => [
                    'method' => 'GET',
                    'url' => '/api/v1/products?category=pain-fever-relief&dosage_form=Tablet&min_price=100&max_price=5000'
                ],
                'Add to Cart' => [
                    'method' => 'POST',
                    'url' => '/api/v1/cart',
                    'body' => [
                        'product_id' => 1,
                        'quantity' => 2
                    ]
                ]
            ]
        ];

        return response()->json($documentation);
    }

    /**
     * Get API health status
     */
    public function health(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'timestamp' => now(),
            'version' => '1.0.0',
            'environment' => app()->environment(),
            'database' => 'connected'
        ]);
    }
}
