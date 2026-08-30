<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CategoryAttributeController;
use App\Http\Controllers\Api\PrescriptionController;
use App\Http\Controllers\Api\ConsultationController;
use App\Http\Controllers\Api\PharmacyPolicyController;
use App\Http\Controllers\Api\StoreVerificationController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\WishlistController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\DocumentationController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\CouponController;
use App\Http\Controllers\Admin\SaleEventController;
use App\Http\Controllers\Admin\ReviewController as AdminReviewController;
use App\Http\Controllers\Admin\EmailAutomationController;
use App\Http\Controllers\Api\StoreApplicationController;
use App\Http\Controllers\Api\StoreController;
use App\Http\Controllers\Admin\StoreManagementController;
use App\Http\Controllers\Admin\DeliveryManagementController;
use App\Http\Controllers\Admin\ShippingZoneController;
use App\Http\Controllers\Admin\PricingConfigurationController;
use App\Http\Controllers\Store\CouponController as StoreCouponController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// Documentation and health routes
Route::get('/docs', [DocumentationController::class, 'index']);
Route::get('/health', [DocumentationController::class, 'health']);

// CORS test route
Route::get('/test-cors', function () {
    return response()->json([
        'success' => true,
        'message' => 'CORS is working through Laravel!',
        'timestamp' => now(),
        'headers' => [
            'Origin' => request()->header('Origin'),
            'User-Agent' => request()->header('User-Agent'),
        ]
    ]);
});

// Public routes (no authentication required)
Route::prefix('v1')->group(function () {
    
    // Authentication routes
    Route::post('/register', [AuthController::class, 'register']);
    // A pharmacy applying with no Taga account yet: account, pharmacy and
    // licence in one submission. Creates an ordinary customer — approval is
    // still the only thing that promotes anyone.
    Route::post('/sell/register', [StoreApplicationController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/verify-email', [AuthController::class, 'verifyEmail']);
    Route::post('/resend-verification', [AuthController::class, 'resendVerification']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
    
    // Social Authentication
    Route::post('/auth/google', [App\Http\Controllers\Api\SocialAuthController::class, 'googleAuth']);
    
    // Public product routes
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/featured', [ProductController::class, 'featured']);
    Route::get('/products/search', [ProductController::class, 'search']);
    Route::get('/products/available-states', [ProductController::class, 'getAvailableStates']);
    Route::get('/products/{id}', [ProductController::class, 'show']);
    
    // Public product variation routes
    Route::get('/products/{productId}/variations', [App\Http\Controllers\Api\ProductVariationController::class, 'activeVariations']);
    Route::get('/products/{productId}/variations/{variationId}', [App\Http\Controllers\Api\ProductVariationController::class, 'show']);
    
    // Delivery Portal routes (token-based, no auth required)
    Route::prefix('delivery')->group(function () {
        Route::get('/order/{orderId}', [App\Http\Controllers\Api\DeliveryPortalController::class, 'getOrderByToken']);
        Route::post('/order/{orderId}/accept', [App\Http\Controllers\Api\DeliveryPortalController::class, 'acceptDelivery']);
        Route::post('/order/{orderId}/update-status', [App\Http\Controllers\Api\DeliveryPortalController::class, 'updateDeliveryStatus']);
    });
    
    // Public category routes ({id} accepts a numeric id or a slug)
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/categories/{id}/products', [CategoryController::class, 'products']);
    Route::get('/categories/{id}', [CategoryController::class, 'show']);
    
    // Public reviews (for specific product)
    Route::get('/products/{product}/reviews', [ReviewController::class, 'index']);
    
    // Public system settings (no authentication required)
    Route::get('/admin/settings/public', [App\Http\Controllers\Admin\SystemSettingsController::class, 'getPublicSettings']);
    Route::get('/settings/product-attributes', [App\Http\Controllers\Admin\SystemSettingsController::class, 'getProductAttributes']);
    Route::get('/settings/category/{category}', [App\Http\Controllers\Admin\SystemSettingsController::class, 'getByCategory']);
    
    // Public banners (no authentication required)
    Route::get('/banners/active', [App\Http\Controllers\Admin\BannerController::class, 'getActive']);

    // The partner-pharmacy logo wall on the homepage.
    Route::get('/partner-pharmacies', [App\Http\Controllers\Admin\PartnerPharmacyController::class, 'active']);
    
    /*
     * Cart routes, for guests and signed-in shoppers alike.
     *
     * These carried no auth middleware at all, so CartController's `Auth::id()`
     * was always null. A signed-in client that sent a token but no X-Guest-ID
     * header wrote rows with both user_id and session_id null — items that no
     * subsequent read could ever find, so the cart silently swallowed them.
     * auth.optional resolves the caller without shutting guests out.
     */
    Route::middleware('auth.optional')->group(function () {
        Route::get('/cart', [CartController::class, 'index']);
        Route::post('/cart', [CartController::class, 'store']);
        Route::delete('/cart', [CartController::class, 'clear']);
        Route::post('/cart/refresh-prices', [CartController::class, 'refreshPrices']);
        Route::post('/cart/apply-coupon', [OrderController::class, 'applyCouponToCart']);
    });

    /*
     * Literal cart paths must be declared before /cart/{id}: Laravel matches in
     * registration order, so with DELETE /cart/{id} first this route was
     * unreachable and removing a coupon answered "Cart item not found" —
     * "remove-coupon" was being read as an item id. The numeric constraint on
     * {id} below stops the same collision recurring.
     */
    Route::middleware('auth.optional')->group(function () {
        Route::delete('/cart/remove-coupon', [OrderController::class, 'removeCouponFromCart']);

        Route::put('/cart/{id}', [CartController::class, 'update'])->where('id', '[0-9]+');
        Route::delete('/cart/{id}', [CartController::class, 'destroy'])->where('id', '[0-9]+');

        // Attach a script to a line already in the basket — the storefront adds
        // the item first and uploads the prescription afterwards.
        Route::post('/cart/{id}/prescription', [CartController::class, 'attachPrescription'])->where('id', '[0-9]+');

        // Merge guest cart with user cart on login
        Route::post('/cart/merge', [CartController::class, 'mergeGuestCart']);
    });
    
    /*
     * Routes that serve guests and signed-in shoppers alike.
     *
     * `auth.optional` resolves the caller when a valid token is sent and lets
     * guests through untouched. Without it these routes were fully public and
     * $request->user() was always null, so a signed-in shopper could not read
     * their own prescriptions and their uploads were saved with no user_id.
     */
    Route::middleware('auth.optional')->group(function () {
        // Order creation (supports both guest and authenticated users)
        Route::post('/orders', [OrderController::class, 'store']);
        Route::post('/orders/buy-now', [OrderController::class, 'buyNow']);
        Route::post('/orders/estimate-shipping', [OrderController::class, 'estimateShipping']);

        // Prescriptions (guest checkout can upload before an account exists;
        // access to an individual record is checked per-request in the controller)
        Route::post('/prescriptions', [PrescriptionController::class, 'store']);
        Route::get('/prescriptions', [PrescriptionController::class, 'index']);
        Route::get('/prescriptions/{id}', [PrescriptionController::class, 'show']);
        Route::get('/prescriptions/{id}/download', [PrescriptionController::class, 'download']);

        // Order confirmation (for the guest order confirmation page)
        Route::get('/orders/{id}/confirmation', [OrderController::class, 'getOrderConfirmation']);
    });

    /*
     * Consultation requests raised from the storefront's floating widget.
     *
     * Guests may raise and follow one — the widget sits on every page and an
     * account is not a precondition for asking a question. Ownership is checked
     * per request in the controller, against the account or the guest id.
     *
     * The customer side addresses a request by its reference, never its id, so
     * the numeric constraint elsewhere does not apply here.
     */
    Route::get('/consultations/practitioner-types', [ConsultationController::class, 'practitionerTypes']);

    Route::middleware('auth.optional')->group(function () {
        Route::post('/consultations', [ConsultationController::class, 'store']);
        Route::get('/consultations', [ConsultationController::class, 'index']);
        Route::get('/consultations/{reference}', [ConsultationController::class, 'show']);
        Route::post('/consultations/{reference}/reply', [ConsultationController::class, 'reply']);
    });
    
    // Public order tracking by tracking number
    Route::post('/track-order', [OrderController::class, 'trackByNumber']);
    
    /*
     * Payment routes. Guests check out too, so these cannot require a token —
     * but they must still resolve the caller when one is signed in. Without
     * auth.optional the controllers' `auth()->check()` ownership guards were
     * always false, which made every order readable and payable by order id.
     */
    Route::middleware('auth.optional')->group(function () {
        Route::post('/payments/initialize', [PaymentController::class, 'initializePayment']);
        Route::post('/payments/verify', [PaymentController::class, 'verifyPayment']);
        Route::get('/payments/{order}/status', [PaymentController::class, 'getPaymentStatus']);
    });
    
    // Active sales and promotions
    Route::get('/sales/active', [SaleEventController::class, 'getActiveSales']);
    Route::get('/sales/products', [SaleEventController::class, 'getSaleProducts']);
    Route::get('/sales/product/{productId}', [SaleEventController::class, 'getProductSaleInfo']);
    
    // Payment webhook (public for payment gateways)
    Route::post('/payments/webhook', [PaymentController::class, 'handleWebhook']);
    
    // Public Store Browsing
    Route::get('/stores', [StoreController::class, 'index']);
    Route::get('/stores/{slug}/products', [StoreController::class, 'products'])->where('slug', '^(?!my-store|my-payouts|verification|bank-details|request-payout).*$');
    Route::get('/stores/{slug}', [StoreController::class, 'show'])->where('slug', '^(?!my-store|my-payouts|verification|bank-details|request-payout).*$');
    
    // Public Shipping Fee Calculator
    Route::post('/shipping/calculate', [OrderController::class, 'calculateShippingFee']);
    
    // Public Shipping Zones - Get available cities for a state (needed for checkout)
    Route::get('/shipping-zones/cities-for-state', [ShippingZoneController::class, 'getCitiesForState']);
});

// Protected routes (authentication required)
Route::prefix('v1')->middleware('auth.token')->group(function () {
    
    // User profile
    Route::get('/user', [AuthController::class, 'getProfile']);
    Route::put('/user', [AuthController::class, 'updateProfile']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);
    
    // Note: Cart routes are public (guest + auth), no need for duplicate auth-only routes
    
    // Order routes (authenticated users only)
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{id}', [OrderController::class, 'show']);
    Route::put('/orders/{id}/cancel', [OrderController::class, 'cancel']);
    
    // Address routes (authenticated users only)
    Route::get('/addresses', [App\Http\Controllers\Api\AddressController::class, 'index']);
    Route::post('/addresses', [App\Http\Controllers\Api\AddressController::class, 'store']);
    Route::get('/addresses/{id}', [App\Http\Controllers\Api\AddressController::class, 'show']);
    Route::put('/addresses/{id}', [App\Http\Controllers\Api\AddressController::class, 'update']);
    Route::delete('/addresses/{id}', [App\Http\Controllers\Api\AddressController::class, 'destroy']);
    Route::put('/addresses/{id}/default', [App\Http\Controllers\Api\AddressController::class, 'setDefault']);
    
    // Review routes
    Route::get('/products/{product}/can-review', [ReviewController::class, 'canReview']);
    Route::post('/products/{product}/reviews', [ReviewController::class, 'store']);
    Route::put('/reviews/{id}', [ReviewController::class, 'update']);
    Route::delete('/reviews/{id}', [ReviewController::class, 'destroy']);
    // Removed: ReviewController::markHelpful was never written, so this route 500d.
    
    // Wishlist routes
    Route::get('/wishlist', [WishlistController::class, 'index']);
    Route::post('/wishlist', [WishlistController::class, 'store']);
    Route::delete('/wishlist/{id}', [WishlistController::class, 'destroy']);
    
    // Export routes (requires reports.export permission)
    Route::middleware(['permission:reports.export'])->group(function () {
        Route::get('/export/orders', [App\Http\Controllers\Api\ExportController::class, 'exportOrders']);
        Route::get('/export/users', [App\Http\Controllers\Api\ExportController::class, 'exportUsers']);
        Route::get('/export/products', [App\Http\Controllers\Api\ExportController::class, 'exportProducts']);
        Route::get('/export/stats', [App\Http\Controllers\Api\ExportController::class, 'getExportStats']);
    });
    
    Route::post('/wishlist/toggle', [WishlistController::class, 'toggle']);
    
    // Payment routes (authenticated users only)
    Route::post('/payments/{order}/refund', [PaymentController::class, 'processRefund']);
    
    // Pharmacy application (storefront). An applicant is an ordinary customer:
    // no store role, no dashboard, until an admin approves the licence.
    Route::get('/sell/application', [StoreApplicationController::class, 'show']);
    Route::post('/sell/apply', [StoreApplicationController::class, 'apply']);

    // Store Owner Routes
    Route::prefix('stores')->group(function () {
        // Store Management
        Route::get('/my-store', [StoreController::class, 'getMyStore']);
        Route::put('/my-store', [StoreController::class, 'updateMyStore']);
        Route::put('/bank-details', [StoreController::class, 'updateBankDetails']);
        Route::post('/request-payout', [StoreController::class, 'requestPayout']);
        Route::get('/my-payouts', [StoreController::class, 'getMyPayouts']);

        // Pharmacy licence verification
        Route::get('/verification', [StoreVerificationController::class, 'show']);
        Route::post('/verification', [StoreVerificationController::class, 'submit']);
    });

    // The pharmacy policy as it applies to the calling pharmacy: read-only, and
    // outside the /stores/{slug} public wildcard rather than inside it.
    Route::get('/store/pharmacy-policy', [PharmacyPolicyController::class, 'forStore']);

    // Store-side prescription review queue
    Route::get('/store/prescriptions', [PrescriptionController::class, 'storeQueue']);
    Route::post('/prescriptions/{id}/review', [PrescriptionController::class, 'review']);
    
    // Legacy store routes (keeping for backward compatibility)
    Route::prefix('store')->group(function () {
        Route::get('/my-store', [StoreController::class, 'myStore']);
        Route::put('/{id}', [StoreController::class, 'update']);
        Route::get('/{id}/analytics', [StoreController::class, 'analytics']);
        
        // Store Owner Coupons
        Route::get('/coupons', [StoreCouponController::class, 'index']);
        Route::get('/coupons/options', [StoreCouponController::class, 'getOptions']);
        Route::post('/coupons', [StoreCouponController::class, 'store']);
        Route::put('/coupons/{id}', [StoreCouponController::class, 'update']);
        Route::delete('/coupons/{id}', [StoreCouponController::class, 'destroy']);
        Route::post('/coupons/{id}/toggle', [StoreCouponController::class, 'toggleStatus']);
        Route::get('/coupons/{id}/statistics', [StoreCouponController::class, 'statistics']);
        
        // Store Owner Reviews
        Route::get('/reviews', [App\Http\Controllers\Api\StoreOwnerReviewController::class, 'index']);
        Route::get('/reviews/stats', [App\Http\Controllers\Api\StoreOwnerReviewController::class, 'stats']);
        Route::get('/reviews/{id}', [App\Http\Controllers\Api\StoreOwnerReviewController::class, 'show']);
        
        // Store Owner Staff Management
        Route::get('/staff/roles', [App\Http\Controllers\StoreOwner\StaffController::class, 'getAvailableRoles']); // Must be before /staff/{id}
        Route::get('/staff', [App\Http\Controllers\StoreOwner\StaffController::class, 'index']);
        Route::post('/staff', [App\Http\Controllers\StoreOwner\StaffController::class, 'store']);
        Route::put('/staff/{id}', [App\Http\Controllers\StoreOwner\StaffController::class, 'update']);
        Route::delete('/staff/{id}', [App\Http\Controllers\StoreOwner\StaffController::class, 'destroy']);
        Route::post('/staff/{id}/reset-password', [App\Http\Controllers\StoreOwner\StaffController::class, 'resetPassword']);
    });
});

// Common routes for all authenticated users (customers, store owners, staff)
Route::prefix('v1')->middleware(['auth.token'])->group(function () {
    // Password change for all users
    Route::post('/change-password', [App\Http\Controllers\Admin\UserController::class, 'changePassword']);
    
    // Get current user's role and permissions
    Route::get('/my-role', [App\Http\Controllers\Admin\RoleController::class, 'getMyRole']);
});

// Staff-accessible admin routes (Permission-based operations)
// These routes are accessible to authenticated staff - controllers handle permission checks
Route::prefix('v1/admin')->middleware(['auth.token'])->group(function () {
    // Dashboard (permission: dashboard.view, reports.view)
    Route::get('/dashboard/overview', [App\Http\Controllers\Admin\DashboardController::class, 'overview'])->middleware('permission:dashboard.view');
    Route::get('/dashboard/sales-analytics', [App\Http\Controllers\Admin\DashboardController::class, 'salesAnalytics'])->middleware('permission:reports.view');
    Route::get('/dashboard/customer-insights', [App\Http\Controllers\Admin\DashboardController::class, 'customerInsights'])->middleware('permission:reports.view');
    Route::get('/dashboard/inventory-alerts', [App\Http\Controllers\Admin\DashboardController::class, 'inventoryAlerts'])->middleware('permission:reports.view');
    
    // Products (permission: products.view, products.create, products.edit, products.delete)
    Route::get('/products', [ProductController::class, 'adminIndex'])->middleware('permission:products.view');
    Route::post('/products', [ProductController::class, 'adminStore'])->middleware('permission:products.create');
    // Carried no permission check, so any signed-in customer could write files
    // into the public web root.
    Route::post('/upload-image', [ProductController::class, 'uploadImage'])->middleware('permission:products.create');
    Route::get('/products/{id}', [ProductController::class, 'adminShow'])->middleware('permission:products.view');
    Route::put('/products/{id}', [ProductController::class, 'adminUpdate'])->middleware('permission:products.edit');
    Route::delete('/products/{id}', [ProductController::class, 'adminDestroy'])->middleware('permission:products.delete');
    Route::post('/products/{id}/toggle-featured', [ProductController::class, 'toggleFeatured'])->middleware('permission:products.edit');
    Route::post('/products/{id}/toggle-active', [ProductController::class, 'toggleActive'])->middleware('permission:products.edit');
    
    // Product Variations (permission: products.edit)
    Route::get('/products/{productId}/variations', [App\Http\Controllers\Api\ProductVariationController::class, 'index'])->middleware('permission:products.view');
    Route::post('/products/{productId}/variations', [App\Http\Controllers\Api\ProductVariationController::class, 'store'])->middleware('permission:products.edit');
    Route::post('/products/{productId}/variations/bulk', [App\Http\Controllers\Api\ProductVariationController::class, 'bulkStore'])->middleware('permission:products.edit');
    Route::get('/products/{productId}/variations/generate-sku', [App\Http\Controllers\Api\ProductVariationController::class, 'generateSku'])->middleware('permission:products.edit');
    Route::put('/products/{productId}/variations/{variationId}', [App\Http\Controllers\Api\ProductVariationController::class, 'update'])->middleware('permission:products.edit');
    Route::delete('/products/{productId}/variations/{variationId}', [App\Http\Controllers\Api\ProductVariationController::class, 'destroy'])->middleware('permission:products.edit');
    Route::post('/products/{productId}/variations/{variationId}/set-default', [App\Http\Controllers\Api\ProductVariationController::class, 'setDefault'])->middleware('permission:products.edit');
    
    // Orders (permission: orders.view, orders.view_details, orders.update_status, orders.update_payment)
    Route::get('/orders', [App\Http\Controllers\Api\OrderController::class, 'adminIndex'])->middleware('permission:orders.view');
    Route::get('/orders/stats', [App\Http\Controllers\Api\OrderController::class, 'adminStats'])->middleware('permission:orders.view');
    Route::get('/orders/{id}', [App\Http\Controllers\Api\OrderController::class, 'adminShow'])->middleware('permission:orders.view_details');
    Route::put('/orders/{id}/status', [App\Http\Controllers\Api\OrderController::class, 'adminUpdateStatus'])->middleware('permission:orders.update_status');
    // Removed: OrderController::updatePaymentStatus does not exist. Payment status
    // is set by the Paystack webhook and by PaymentController.
    
    // Delivery Management for Orders
    Route::post('/orders/{id}/assign-delivery', [OrderController::class, 'assignDeliveryAgent'])->middleware('permission:orders.update_status');
    Route::get('/orders/{id}/available-agents', [OrderController::class, 'getAvailableAgents'])->middleware('permission:orders.view_details');
    Route::post('/orders/{id}/delivery-status', [OrderController::class, 'updateDeliveryStatus'])->middleware('permission:orders.update_status');
    
    // Payment verification and confirmation (permission: orders.update_payment)
    Route::post('/orders/{id}/verify-payment', [PaymentController::class, 'adminVerifyPayment'])->middleware('permission:orders.update_payment');
    Route::post('/orders/{id}/confirm-payment', [PaymentController::class, 'adminConfirmPayment'])->middleware('permission:orders.update_payment');
    
    // Coupons (permission: coupons.view, coupons.create, coupons.edit, coupons.delete)
    Route::get('/coupons', [App\Http\Controllers\Admin\CouponController::class, 'index'])->middleware('permission:coupons.view');
    Route::get('/coupons/options', [App\Http\Controllers\Admin\CouponController::class, 'getOptions'])->middleware('permission:coupons.view');
    Route::get('/coupons/{id}', [App\Http\Controllers\Admin\CouponController::class, 'show'])->middleware('permission:coupons.view');
    Route::post('/coupons', [App\Http\Controllers\Admin\CouponController::class, 'store'])->middleware('permission:coupons.create');
    Route::post('/coupons/special-event', [App\Http\Controllers\Admin\CouponController::class, 'createSpecialEvent'])->middleware('permission:coupons.create');
    Route::put('/coupons/{id}', [App\Http\Controllers\Admin\CouponController::class, 'update'])->middleware('permission:coupons.edit');
    Route::put('/coupons/{id}/toggle', [App\Http\Controllers\Admin\CouponController::class, 'toggleStatus'])->middleware('permission:coupons.edit');
    Route::delete('/coupons/{id}', [App\Http\Controllers\Admin\CouponController::class, 'destroy'])->middleware('permission:coupons.delete');
    
    // Sale Events (permission: sales.view, sales.create, sales.edit, sales.delete)
    Route::get('/sale-events', [App\Http\Controllers\Admin\SaleEventController::class, 'index'])->middleware('permission:sales.view');
    Route::get('/sale-events/options', [App\Http\Controllers\Admin\SaleEventController::class, 'getOptions'])->middleware('permission:sales.view');
    Route::get('/sale-events/{id}', [App\Http\Controllers\Admin\SaleEventController::class, 'show'])->middleware('permission:sales.view');
    Route::post('/sale-events', [App\Http\Controllers\Admin\SaleEventController::class, 'store'])->middleware('permission:sales.create');
    Route::post('/sale-events/quick-sale', [App\Http\Controllers\Admin\SaleEventController::class, 'createQuickSale'])->middleware('permission:sales.create');
    Route::put('/sale-events/{id}', [App\Http\Controllers\Admin\SaleEventController::class, 'update'])->middleware('permission:sales.edit');
    Route::put('/sale-events/{id}/toggle', [App\Http\Controllers\Admin\SaleEventController::class, 'toggleStatus'])->middleware('permission:sales.edit');
    Route::delete('/sale-events/{id}', [App\Http\Controllers\Admin\SaleEventController::class, 'destroy'])->middleware('permission:sales.delete');
    
    // Reviews (permission: reviews.view, reviews.approve, reviews.delete)
    Route::get('/reviews', [AdminReviewController::class, 'index'])->middleware('permission:reviews.view');
    Route::get('/reviews/stats', [AdminReviewController::class, 'stats'])->middleware('permission:reviews.view');
    Route::get('/reviews/{id}', [AdminReviewController::class, 'show'])->middleware('permission:reviews.view');
    Route::put('/reviews/{id}/approve', [AdminReviewController::class, 'approve'])->middleware('permission:reviews.approve');
    Route::put('/reviews/{id}/reject', [AdminReviewController::class, 'reject'])->middleware('permission:reviews.approve');
    Route::delete('/reviews/{id}', [AdminReviewController::class, 'destroy'])->middleware('permission:reviews.delete');
    Route::post('/reviews/bulk-approve', [AdminReviewController::class, 'bulkApprove'])->middleware('permission:reviews.approve');
    Route::post('/reviews/bulk-reject', [AdminReviewController::class, 'bulkReject'])->middleware('permission:reviews.approve');
    Route::post('/reviews/bulk-delete', [AdminReviewController::class, 'bulkDelete'])->middleware('permission:reviews.delete');
    
    // Users (permission: users.view, users.create, users.edit, users.manage_status, users.delete)
    Route::get('/users', [App\Http\Controllers\Admin\UserController::class, 'index'])->middleware('permission:users.view');
    Route::get('/users/roles', [App\Http\Controllers\Admin\UserController::class, 'getRoles'])->middleware('permission:users.view');
    Route::get('/users/{id}', [App\Http\Controllers\Admin\UserController::class, 'show'])->middleware('permission:users.view');
    Route::post('/users/staff', [App\Http\Controllers\Admin\UserController::class, 'createStaff'])->middleware('permission:users.create');
    Route::put('/users/{id}', [App\Http\Controllers\Admin\UserController::class, 'update'])->middleware('permission:users.edit');
    Route::put('/users/{id}/toggle-status', [App\Http\Controllers\Admin\UserController::class, 'toggleStatus'])->middleware('permission:users.manage_status');
    Route::delete('/users/{id}', [App\Http\Controllers\Admin\UserController::class, 'destroy'])->middleware('permission:users.delete');
    
    // Categories (permission: products.create, products.edit, products.delete)
    Route::post('/categories', [CategoryController::class, 'store'])->middleware('permission:products.create');
    Route::put('/categories/{id}', [CategoryController::class, 'update'])->middleware('permission:products.edit');
    Route::delete('/categories/{id}', [CategoryController::class, 'destroy'])->middleware('permission:products.delete');

    /*
     * Form reference data: the vocabularies a create/edit form fills its
     * dropdowns from.
     *
     * These sat in the admin-role group below, which meant a pharmacy opening
     * the product form got "Access denied. Admin privileges required." — and
     * because the form loads them in the same Promise.all as its categories,
     * one 403 took the whole form down and no categories appeared either.
     *
     * They were never privileged. `/api/v1/settings/product-attributes` has
     * always served the identical payload with no authentication at all, so the
     * admin gate on this copy protected nothing; it only locked out the people
     * who needed it. Keyed to the permission for the form each one feeds.
     */
    Route::get('/settings/product-attributes', [App\Http\Controllers\Admin\SystemSettingsController::class, 'getProductAttributes'])->middleware('permission:products.view');
    Route::get('/settings/coupon-types', [App\Http\Controllers\Admin\SystemSettingsController::class, 'getCouponTypes'])->middleware('permission:coupons.view');
    Route::get('/settings/sale-event-types', [App\Http\Controllers\Admin\SystemSettingsController::class, 'getSaleEventTypes'])->middleware('permission:sales.view');

    // Category attributes — the per-category flexible field definitions
    Route::get('/categories/{id}/attributes', [CategoryAttributeController::class, 'index'])->middleware('permission:products.view');
    Route::post('/categories/{id}/attributes', [CategoryAttributeController::class, 'store'])->middleware('permission:products.create');
    Route::put('/category-attributes/{id}', [CategoryAttributeController::class, 'update'])->middleware('permission:products.edit');
    Route::delete('/category-attributes/{id}', [CategoryAttributeController::class, 'destroy'])->middleware('permission:products.delete');
});

// Admin-only routes (Admin role required - not permission-based)
/*
 * Consultation requests raised from the storefront widget.
 *
 * These used to sit behind the blanket `admin` gate below, which meant the only
 * accounts that could answer a shopper's health question were the same ones
 * that could refund an order and delete a pharmacy. A practitioner now signs in
 * as themselves; the controller narrows the queue to the specialties they
 * actually answer for.
 */
Route::prefix('v1/admin')->middleware(['auth.token'])->group(function () {
    Route::get('/consultations', [ConsultationController::class, 'adminIndex'])->middleware('permission:consultations.view');
    Route::get('/consultations/stats', [ConsultationController::class, 'adminStats'])->middleware('permission:consultations.view');
    Route::get('/consultations/{id}', [ConsultationController::class, 'adminShow'])->where('id', '[0-9]+')->middleware('permission:consultations.view');
    Route::put('/consultations/{id}', [ConsultationController::class, 'adminUpdate'])->where('id', '[0-9]+')->middleware('permission:consultations.reply');
    Route::post('/consultations/{id}/reply', [ConsultationController::class, 'adminReply'])->where('id', '[0-9]+')->middleware('permission:consultations.reply');
});

Route::prefix('v1/admin')->middleware(['auth.token', 'admin'])->group(function () {
    
    // Pharmacy business policy (safety invariants are not settings — see PharmacyPolicy)
    Route::get('/pharmacy-policy', [PharmacyPolicyController::class, 'show']);
    Route::put('/pharmacy-policy', [PharmacyPolicyController::class, 'update']);

    // Pharmacy licence verification queue
    Route::get('/store-verifications', [StoreVerificationController::class, 'pending']);
    Route::post('/stores/{id}/verification/review', [StoreVerificationController::class, 'review']);
    Route::get('/stores/{id}/verification/document', [StoreVerificationController::class, 'document']);

    // Platform-level prescription oversight
    Route::get('/prescriptions', [PrescriptionController::class, 'adminQueue']);

    // Store Management
    Route::prefix('stores')->group(function () {
        // Store Payouts (must come BEFORE /{id} routes to avoid conflicts)
        Route::get('/payouts', [StoreManagementController::class, 'getAllPayouts']); // All payouts across stores
        Route::post('/payouts/{id}/process', [StoreManagementController::class, 'processPayout']);
        Route::post('/payouts/{id}/reject', [StoreManagementController::class, 'rejectPayout']);
        
        // Store routes
        Route::get('/', [StoreManagementController::class, 'index']);
        Route::get('/{id}', [StoreManagementController::class, 'show']);
        Route::post('/{id}/suspend', [StoreManagementController::class, 'suspend']);
        Route::post('/{id}/activate', [StoreManagementController::class, 'activate']);
        // Archive, not erase: orders and payouts point at these rows.
        Route::delete('/{id}', [StoreManagementController::class, 'destroy']);
        Route::post('/{id}/restore', [StoreManagementController::class, 'restore']);
        Route::put('/{id}/commission', [StoreManagementController::class, 'updateCommission']);
        Route::get('/{id}/payouts', [StoreManagementController::class, 'payouts']);
        Route::post('/{id}/payouts', [StoreManagementController::class, 'createPayout']);
    });
    
    // Logistics Companies
    Route::prefix('logistics-companies')->group(function () {
        Route::get('/', [DeliveryManagementController::class, 'getLogisticsCompanies']);
        Route::post('/', [DeliveryManagementController::class, 'createLogisticsCompany']);
        Route::put('/{id}', [DeliveryManagementController::class, 'updateLogisticsCompany']);
        Route::delete('/{id}', [DeliveryManagementController::class, 'deleteLogisticsCompany']);
        Route::post('/{id}/resend-credentials', [DeliveryManagementController::class, 'resendLogisticsCompanyCredentials']);
    });
    
    // Delivery Agents
    Route::prefix('delivery-agents')->group(function () {
        Route::get('/', [DeliveryManagementController::class, 'getDeliveryAgents']);
        Route::post('/', [DeliveryManagementController::class, 'createDeliveryAgent']);
        Route::put('/{id}', [DeliveryManagementController::class, 'updateDeliveryAgent']);
        Route::delete('/{id}', [DeliveryManagementController::class, 'deleteDeliveryAgent']);
        Route::post('/{id}/toggle-status', [DeliveryManagementController::class, 'toggleDeliveryAgentStatus']);
        Route::post('/{id}/resend-credentials', [DeliveryManagementController::class, 'resendDeliveryAgentCredentials']);
    });
    
    // Shipping Zones
    Route::prefix('shipping-zones')->group(function () {
        Route::get('/', [ShippingZoneController::class, 'index']);
        Route::post('/', [ShippingZoneController::class, 'store']);
        Route::get('/debug', [ShippingZoneController::class, 'debugZones']);
        Route::get('/cities-for-state', [ShippingZoneController::class, 'getCitiesForState']);
        Route::get('/suggested-rate', [ShippingZoneController::class, 'getSuggestedRate']);
        Route::post('/calculate-fee', [ShippingZoneController::class, 'calculateFee']);
        // Lays down every route from one origin at once. Checkout refuses any
        // route no zone covers, so a nationwide shop needs 37 of them.
        Route::post('/bulk', [ShippingZoneController::class, 'bulkStore']);
        // Removed: ShippingZoneController::show does not exist; the admin UI reads the index.
        Route::put('/{id}', [ShippingZoneController::class, 'update']);
        Route::delete('/{id}', [ShippingZoneController::class, 'destroy']);
    });
    
    // Pricing Configuration
    Route::prefix('pricing-configurations')->group(function () {
        Route::get('/', [PricingConfigurationController::class, 'index']);
        Route::post('/', [PricingConfigurationController::class, 'store']);
        // Removed: PricingConfigurationController::show does not exist; the admin UI reads the index.
        Route::put('/{id}', [PricingConfigurationController::class, 'update']);
        Route::delete('/{id}', [PricingConfigurationController::class, 'destroy']);
        Route::post('/{id}/toggle', [PricingConfigurationController::class, 'toggleStatus']);
        Route::post('/preview', [PricingConfigurationController::class, 'previewPricing']);
    });
    
    // Roles & Permissions Management (Admin only - ALL operations)
    Route::get('/roles', [App\Http\Controllers\Admin\RoleController::class, 'index']);
    Route::get('/roles/permissions', [App\Http\Controllers\Admin\RoleController::class, 'getPermissions']);
    Route::post('/roles', [App\Http\Controllers\Admin\RoleController::class, 'store']);
    Route::get('/roles/{id}', [App\Http\Controllers\Admin\RoleController::class, 'show']);
    Route::put('/roles/{id}', [App\Http\Controllers\Admin\RoleController::class, 'update']);
    Route::put('/roles/{id}/toggle-status', [App\Http\Controllers\Admin\RoleController::class, 'toggleStatus']);
    Route::delete('/roles/{id}', [App\Http\Controllers\Admin\RoleController::class, 'destroy']);
    
    // System Settings Management
    Route::get('/settings', [App\Http\Controllers\Admin\SystemSettingsController::class, 'index']);
    Route::get('/settings/admin/all', [App\Http\Controllers\Admin\SystemSettingsController::class, 'getAllForAdmin']);
    Route::get('/settings/category/{category}', [App\Http\Controllers\Admin\SystemSettingsController::class, 'getByCategory']);
    // product-attributes, coupon-types and sale-event-types are registered in
    // the permission-gated group above, not here: they are form reference data
    // rather than platform settings, and gating them on the admin role locked
    // pharmacies out of their own product and coupon forms.
    Route::post('/settings', [App\Http\Controllers\Admin\SystemSettingsController::class, 'store']);
    Route::post('/settings/initialize-defaults', [App\Http\Controllers\Admin\SystemSettingsController::class, 'initializeDefaults']);
    Route::post('/settings/bulk-update', [App\Http\Controllers\Admin\SystemSettingsController::class, 'bulkUpdate']);
    Route::put('/settings/{id}', [App\Http\Controllers\Admin\SystemSettingsController::class, 'update']);
    Route::put('/settings/{id}/toggle', [App\Http\Controllers\Admin\SystemSettingsController::class, 'toggleStatus']);
    Route::post('/settings/{id}/add-option', [App\Http\Controllers\Admin\SystemSettingsController::class, 'addArrayOption']);
    Route::delete('/settings/{id}/remove-option', [App\Http\Controllers\Admin\SystemSettingsController::class, 'removeArrayOption']);
    Route::delete('/settings/{id}', [App\Http\Controllers\Admin\SystemSettingsController::class, 'destroy']);

    // Email Automation Management
    Route::get('/email-automation', [EmailAutomationController::class, 'index']);
    Route::get('/email-automation/{key}', [EmailAutomationController::class, 'show']);
    Route::put('/email-automation/{key}', [EmailAutomationController::class, 'update']);
    Route::put('/email-automation/{key}/toggle', [EmailAutomationController::class, 'toggle']);
    Route::get('/email-automation/logs/all', [EmailAutomationController::class, 'logs']);
    Route::get('/email-automation/statistics/all', [EmailAutomationController::class, 'statistics']);
    Route::post('/email-automation/test', [EmailAutomationController::class, 'testEmail']);

    // Banner Management
    /*
     * Partner pharmacies — the logo wall.
     *
     * Platform admin only, and in this group rather than the permission-gated
     * one deliberately: this is the platform speaking about who it works with,
     * not a shop managing its own listing.
     */
    Route::get('/partner-pharmacies', [App\Http\Controllers\Admin\PartnerPharmacyController::class, 'index']);
    Route::post('/partner-pharmacies', [App\Http\Controllers\Admin\PartnerPharmacyController::class, 'store']);
    // POST rather than PUT for the update: multipart bodies do not survive PUT
    // in PHP, and this carries a file. Banners do the same.
    Route::post('/partner-pharmacies/{id}', [App\Http\Controllers\Admin\PartnerPharmacyController::class, 'update']);
    Route::put('/partner-pharmacies/{id}/toggle', [App\Http\Controllers\Admin\PartnerPharmacyController::class, 'toggleStatus']);
    Route::delete('/partner-pharmacies/{id}', [App\Http\Controllers\Admin\PartnerPharmacyController::class, 'destroy']);

    // The practitioner specialties a shopper can ask to speak to. The
    // storefront reads the active ones from /consultations/practitioner-types.
    Route::get('/practitioner-types', [App\Http\Controllers\Admin\PractitionerTypeController::class, 'index']);
    Route::post('/practitioner-types', [App\Http\Controllers\Admin\PractitionerTypeController::class, 'store']);
    Route::put('/practitioner-types/{id}', [App\Http\Controllers\Admin\PractitionerTypeController::class, 'update']);
    Route::put('/practitioner-types/{id}/toggle', [App\Http\Controllers\Admin\PractitionerTypeController::class, 'toggleStatus']);
    Route::delete('/practitioner-types/{id}', [App\Http\Controllers\Admin\PractitionerTypeController::class, 'destroy']);

    Route::get('/banners', [App\Http\Controllers\Admin\BannerController::class, 'index']);
    // Must stay above `/banners/{id}`, which would otherwise swallow "themes".
    Route::get('/banners/themes', [App\Http\Controllers\Admin\BannerController::class, 'themes']);
    Route::get('/banners/{id}', [App\Http\Controllers\Admin\BannerController::class, 'show']);
    Route::post('/banners', [App\Http\Controllers\Admin\BannerController::class, 'store']);
    Route::post('/banners/{id}', [App\Http\Controllers\Admin\BannerController::class, 'update']); // POST for file upload
    Route::put('/banners/{id}/toggle', [App\Http\Controllers\Admin\BannerController::class, 'toggleStatus']);
    Route::delete('/banners/{id}', [App\Http\Controllers\Admin\BannerController::class, 'destroy']);

    // Delivery System Management
    Route::prefix('delivery')->group(function () {
        Route::get('/settings', [App\Http\Controllers\Api\Admin\DeliveryManagementController::class, 'getSettings']);
        Route::put('/settings', [App\Http\Controllers\Api\Admin\DeliveryManagementController::class, 'updateSetting']);
        Route::get('/shipping-rates', [App\Http\Controllers\Api\Admin\DeliveryManagementController::class, 'getShippingRates']);
        Route::post('/shipping-rates', [App\Http\Controllers\Api\Admin\DeliveryManagementController::class, 'createShippingRate']);
        Route::put('/shipping-rates/{id}', [App\Http\Controllers\Api\Admin\DeliveryManagementController::class, 'updateShippingRate']);
        Route::delete('/shipping-rates/{id}', [App\Http\Controllers\Api\Admin\DeliveryManagementController::class, 'deleteShippingRate']);
        Route::get('/payouts/pending', [App\Http\Controllers\Api\Admin\DeliveryManagementController::class, 'getPendingPayouts']);
        Route::post('/payouts/{id}/approve', [App\Http\Controllers\Api\Admin\DeliveryManagementController::class, 'approvePayout']);
        // Without this, declining a request stranded the money: out of the
        // courier's available balance and never paid.
        Route::post('/payouts/{id}/reject', [App\Http\Controllers\Api\Admin\DeliveryManagementController::class, 'rejectPayout']);
        Route::get('/agents', [App\Http\Controllers\Api\Admin\DeliveryManagementController::class, 'getAgents']);
        Route::post('/agents/{id}/verify', [App\Http\Controllers\Api\Admin\DeliveryManagementController::class, 'verifyAgent']);
    });
});

// Delivery routes with v1 prefix
Route::prefix('v1')->group(function () {
    // Delivery Tracking (Public)
    Route::post('/delivery/track', [App\Http\Controllers\Api\DeliveryTrackingController::class, 'track']);

    // Delivery Agent Portal
    Route::prefix('delivery/agent')->group(function () {
        Route::post('/login', [App\Http\Controllers\Api\DeliveryAgentController::class, 'login']);
        
        Route::middleware('auth.agent')->group(function () {
            Route::get('/dashboard', [App\Http\Controllers\Api\DeliveryAgentController::class, 'dashboard']);
            Route::get('/shipments', [App\Http\Controllers\Api\DeliveryAgentController::class, 'getShipments']);
            Route::put('/shipments/{id}/status', [App\Http\Controllers\Api\DeliveryAgentController::class, 'updateStatus']);
            Route::get('/earnings', [App\Http\Controllers\Api\AgentPayoutController::class, 'getEarnings']);
            Route::post('/payouts/request', [App\Http\Controllers\Api\AgentPayoutController::class, 'requestPayout']);
            Route::get('/payouts', [App\Http\Controllers\Api\AgentPayoutController::class, 'getPayouts']);
            Route::post('/change-password', [App\Http\Controllers\Api\DeliveryAgentController::class, 'changePassword']);
            Route::put('/status', [App\Http\Controllers\Api\DeliveryAgentController::class, 'toggleStatus']);
            Route::get('/profile', [App\Http\Controllers\Api\DeliveryAgentController::class, 'getProfile']);
            Route::put('/profile', [App\Http\Controllers\Api\DeliveryAgentController::class, 'updateProfile']);
        });
    });

    // Logistics Company Portal
    Route::prefix('delivery/logistics')->group(function () {
        Route::post('/login', [App\Http\Controllers\Api\LogisticsCompanyController::class, 'login']);
        
        Route::middleware('auth.company')->group(function () {
            Route::get('/dashboard', [App\Http\Controllers\Api\LogisticsCompanyController::class, 'dashboard']);
            Route::post('/change-password', [App\Http\Controllers\Api\LogisticsCompanyController::class, 'changePassword']);
            Route::get('/profile', [App\Http\Controllers\Api\LogisticsCompanyController::class, 'getProfile']);
            Route::post('/agents/invite', [App\Http\Controllers\Api\LogisticsCompanyController::class, 'inviteAgent']);
            Route::get('/agents', [App\Http\Controllers\Api\LogisticsCompanyController::class, 'getAgents']);
            Route::get('/invitations', [App\Http\Controllers\Api\LogisticsCompanyController::class, 'getInvitations']);
            Route::put('/agents/{id}', [App\Http\Controllers\Api\LogisticsCompanyController::class, 'updateAgent']);
            Route::put('/agents/{id}/status', [App\Http\Controllers\Api\LogisticsCompanyController::class, 'updateAgentStatus']);
            Route::delete('/agents/{id}', [App\Http\Controllers\Api\LogisticsCompanyController::class, 'deleteAgent']);
            // Orders management
            Route::get('/orders', [App\Http\Controllers\Api\LogisticsCompanyController::class, 'getOrders']);
            Route::get('/orders/{id}', [App\Http\Controllers\Api\LogisticsCompanyController::class, 'getOrderDetail']);
            Route::post('/orders/{id}/status', [App\Http\Controllers\Api\LogisticsCompanyController::class, 'updateShipmentStatus']);
            Route::post('/orders/{id}/assign-agent', [App\Http\Controllers\Api\LogisticsCompanyController::class, 'assignAgentToShipment']);
            Route::get('/orders/{id}/available-agents', [App\Http\Controllers\Api\LogisticsCompanyController::class, 'getAgentsForAssignment']);
            // Financials & Payouts
            Route::get('/financials', [App\Http\Controllers\Api\LogisticsCompanyController::class, 'getFinancials']);
            Route::post('/payouts/request', [App\Http\Controllers\Api\LogisticsCompanyController::class, 'requestPayout']);
            Route::get('/payouts', [App\Http\Controllers\Api\LogisticsCompanyController::class, 'getPayoutHistory']);
            // Shipping Rates (read-only for company)
            Route::get('/shipping-rates', [App\Http\Controllers\Api\LogisticsCompanyController::class, 'getShippingRates']);
        });
    });

    // Authenticated user order tracking
    Route::middleware('auth.token')->group(function () {
        Route::get('/orders/{id}/tracking', [App\Http\Controllers\Api\DeliveryTrackingController::class, 'getOrderTracking']);
    });
});

// Additional health check route for backward compatibility
Route::get('/api/health', [DocumentationController::class, 'health']);
