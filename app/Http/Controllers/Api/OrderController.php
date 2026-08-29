<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesOrderAccess;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\CartSession;
use App\Models\SaleEvent;
use App\Models\Product;
use App\Jobs\SendOrderStatusEmail;
use App\Jobs\SendOrderFollowUpEmail;
use App\Models\EmailAutomationSetting;
use App\Models\DeliveryAgent;
use App\Models\LogisticsCompany;
use App\Models\ShippingZone;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use App\Mail\DeliveryAssignmentEmail;
use App\Mail\DeliveryTrackingUpdateEmail;

class OrderController extends Controller
{
    use AuthorizesOrderAccess;
    use \App\Http\Controllers\Concerns\ResolvesOwnPrescription;
    use \App\Http\Controllers\Concerns\ScopesToStore;

    private $_lastShippingZoneId = null;

    /**
     * Whether the last shipping calculation found no zone covering the route.
     *
     * A missing zone used to be indistinguishable from a zero fee, so an order
     * to a state nobody delivers to went through at ₦0 shipping and the platform
     * paid the courier out of its own pocket. With no zones configured at all —
     * which is where this database currently stands — that was every order.
     */
    private $_lastShippingUncovered = false;

    /**
     * Where the last quoted delivery fee came from, one row per pharmacy.
     *
     * The fee arrived at checkout as a single number with nothing behind it, so
     * neither a shopper nor an operator could tell whether ₦5,000 was one long
     * leg or two short ones — and an operator wanting to know why could only
     * read the log. Each row names the pharmacy, the route, the zone that
     * priced it, and what that leg costs.
     */
    private $_lastShippingBreakdown = [];

    /**
     * Pharmacy checkout guards.
     *
     * Two rules that a general-merchandise checkout never needed:
     *  1. Expired stock must never be dispatched, whatever the stock count says.
     *  2. Every prescription-only line needs a prescription that is approved and
     *     still in date at the moment of checkout.
     *
     * Returns a JsonResponse to short-circuit with, or null when the basket is clear.
     */
    private function guardPharmacyRules($cartItems): ?JsonResponse
    {
        foreach ($cartItems as $item) {
            $product = $item->product;

            if (! $product) {
                continue;
            }

            // The shop behind this line must be licensed to sell. The catalogue
            // already hides unlicensed stock, but a basket can outlive a licence
            // — it can lapse or be withdrawn between adding and checking out —
            // and nothing stops a client posting a product id directly.
            $store = $product->store;

            if ($store && ! $store->canSell()) {
                return response()->json([
                    'success' => false,
                    'message' => "{$product->name} is not available for sale at the moment. "
                        . 'Please remove it from your basket.',
                    'code' => 'store_not_licensed',
                    'product_id' => $product->id,
                ], 422);
            }

            // Hard rule (not configurable): never dispatch expired stock.
            if ($product->isExpired()) {
                return response()->json([
                    'success' => false,
                    'message' => "{$product->name} has passed its expiry date and cannot be dispatched. "
                        . 'Please remove it from your basket.',
                    'code' => 'product_expired',
                    'product_id' => $product->id,
                ], 422);
            }

            // Business policy (admin-configurable): some operators refuse stock with
            // too little shelf life left. This only ever tightens the rule above.
            $minShelfLife = \App\Support\PharmacyPolicy::minShelfLifeDays();

            if ($minShelfLife > 0
                && $product->expiry_date
                && $product->expiry_date->lt(\App\Support\PharmacyPolicy::earliestSellableExpiryDate())) {
                return response()->json([
                    'success' => false,
                    'message' => "{$product->name} has less than {$minShelfLife} days of shelf life "
                        . 'remaining and cannot be dispatched.',
                    'code' => 'insufficient_shelf_life',
                    'product_id' => $product->id,
                ], 422);
            }

            if (! $product->requires_prescription) {
                continue;
            }

            $prescription = $item->prescription_id
                ? \App\Models\Prescription::find($item->prescription_id)
                : null;

            if (! $prescription) {
                return response()->json([
                    'success' => false,
                    'message' => "{$product->name} requires a prescription. "
                        . 'Please upload one before checking out.',
                    'code' => 'prescription_required',
                    'product_id' => $product->id,
                ], 422);
            }

            if ($prescription->status === \App\Models\Prescription::STATUS_REJECTED) {
                return response()->json([
                    'success' => false,
                    'message' => "The prescription for {$product->name} was rejected"
                        . ($prescription->rejection_reason ? ": {$prescription->rejection_reason}" : '.'),
                    'code' => 'prescription_rejected',
                    'product_id' => $product->id,
                ], 422);
            }

            /*
             * A prescription still awaiting review does NOT block checkout.
             *
             * The order is placed and paid for, then held at
             * prescription_status = pending until a pharmacist decides. Nothing
             * is dispatched before that — see guardPrescriptionBeforeDispatch —
             * and a rejection cancels the order and marks it for refund.
             *
             * The alternative, blocking here, meant the shopper had to come back
             * later and rebuild a basket whose prices and stock may have moved,
             * and it stranded every basket the moment one line needed review.
             */

            // Approved but lapsed — isUsable() covers the expiry case. A pending
            // prescription has no decision yet, so there is nothing to have lapsed.
            if ($prescription->status !== \App\Models\Prescription::STATUS_PENDING
                && ! $prescription->isUsable()) {
                return response()->json([
                    'success' => false,
                    'message' => "The prescription for {$product->name} has expired. "
                        . 'Please upload a current one.',
                    'code' => 'prescription_expired',
                    'product_id' => $product->id,
                ], 422);
            }
        }

        return null;
    }

    /**
     * Binds the basket's prescriptions to the created order and sets the order-level
     * prescription state. Called after line items exist.
     */
    private function linkPrescriptionsToOrder(Order $order, $cartItems): void
    {
        $prescriptionIds = collect($cartItems)->pluck('prescription_id')->filter()->unique();

        if ($prescriptionIds->isEmpty()) {
            $order->update([
                'requires_prescription' => false,
                'prescription_status' => 'not_required',
            ]);

            return;
        }

        // Claim the prescriptions for this order, and record which store must hold
        // the record for it (first Rx line's store).
        foreach ($prescriptionIds as $prescriptionId) {
            $prescription = \App\Models\Prescription::find($prescriptionId);

            if (! $prescription) {
                continue;
            }

            $storeId = collect($cartItems)
                ->firstWhere('prescription_id', $prescriptionId)
                ?->product?->store_id;

            $prescription->update(array_filter([
                'order_id' => $order->id,
                'store_id' => $prescription->store_id ?: $storeId,
            ]));
        }

        (new PrescriptionController())->refreshOrderPrescriptionStatus($order->fresh());
    }

    /**
     * Get user orders
     */
    public function index(Request $request): JsonResponse
    {
        $userId = Auth::id();
        \Log::info('OrderController@index - User ID: ' . $userId);
        
        // Debug: Show all orders in database
        $allOrders = Order::all();
        \Log::info('OrderController@index - All orders in DB: ' . json_encode($allOrders->toArray()));
        
        $query = Order::with(['items.product', 'coupon'])
                     ->where('user_id', $userId);

        // Apply filters
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        $orders = $query->orderBy('created_at', 'desc')
                       ->paginate($request->get('per_page', 10));

        \Log::info('OrderController@index - Found orders: ' . $orders->count());
        \Log::info('OrderController@index - Orders data: ' . json_encode($orders->toArray()));

        return response()->json([
            'success' => true,
            'data' => $orders
        ]);
    }

    /**
     * Create new order from cart
     */
    public function store(Request $request): JsonResponse
    {
        \Log::info('=== ORDER CREATION STARTED ===');
        \Log::info('OrderController@store - Request timestamp: ' . now());
        \Log::info('OrderController@store - Request ID: ' . uniqid());
        
        $request->validate([
            'shipping_address' => 'required|array',
            'shipping_address.firstName' => 'required|string',
            'shipping_address.lastName' => 'required|string',
            'shipping_address.email' => 'required|email',
            'shipping_address.address' => 'required|string',
            'shipping_address.city' => 'required|string',
            'shipping_address.state' => 'required|string',
            'shipping_address.postalCode' => 'nullable|string',
            'shipping_address.country' => 'required|string',
            'shipping_address.phone' => 'required|string',
            'billing_address' => 'nullable|array',
            'payment_method' => 'nullable|string',
            'is_pay_on_delivery' => 'nullable|boolean',
            'delivery_type' => 'nullable|in:home_delivery,pickup,store_pickup',
            'notes' => 'nullable|string'
        ]);

        // Validate delivery type against system settings
        $deliveryType = $request->delivery_type ?? 'home_delivery';
        
        if ($deliveryType === 'store_pickup') {
            $storePickupEnabled = \App\Models\SystemSetting::getValue(
                \App\Models\SystemSetting::CATEGORY_GENERAL, 
                'enable_store_pickup', 
                true
            );
            
            if (!$storePickupEnabled) {
                return response()->json([
                    'success' => false,
                    'message' => 'Store pickup is currently not available'
                ], 422);
            }
        }

        // Resolved by the `auth.optional` middleware: present for a signed-in
        // shopper, absent for a guest. This used to hand-decode the bearer
        // token here, duplicating the middleware and pinning this route to the
        // old unsigned token format.
        $userId = $request->user()?->id;
        
        $guestId = $request->header('X-Guest-ID');
        
        \Log::info('OrderController@store - User ID: ' . $userId);
        \Log::info('OrderController@store - Guest ID: ' . $guestId);
        \Log::info('OrderController@store - Auth user: ' . json_encode(Auth::user()));

        // Get cart items
        \Log::info('OrderController@store - Looking for cart items with userId: ' . $userId . ', guestId: ' . $guestId);
        
        $cartItems = Cart::with('product.store')
                        ->when($userId, function ($query) use ($userId) {
                            \Log::info('OrderController@store - Querying cart by user_id: ' . $userId);
                            return $query->where('user_id', $userId);
                        }, function ($query) use ($guestId) {
                            \Log::info('OrderController@store - Querying cart by session_id: ' . $guestId);
                            return $query->where('session_id', $guestId);
                        })
                        ->get();
        
        // Track which cart type was used for clearing later
        $cartUsedUserId = $userId;
        $cartUsedGuestId = null;
                        
        // If no cart items found and we have both userId and guestId, try fallback
        if ($cartItems->isEmpty() && $userId && $guestId) {
            \Log::info('OrderController@store - No items found, trying fallback query for session_id: ' . $guestId);
            $cartItems = Cart::with('product.store')->where('session_id', $guestId)->get();
            \Log::info('OrderController@store - Fallback found cart items: ' . $cartItems->count());
            
            // Update cart clearing variables to use session cart
            if (!$cartItems->isEmpty()) {
                $cartUsedUserId = null;
                $cartUsedGuestId = $guestId;
                \Log::info('OrderController@store - Will clear session cart instead of user cart');
            }
        }
                        
        \Log::info('OrderController@store - Found cart items: ' . $cartItems->count());
        
        // Debug: Log each cart item details
        foreach ($cartItems as $item) {
            \Log::info('OrderController@store - Cart item: Product=' . $item->product->name . 
                      ', Quantity=' . $item->quantity . 
                      ', Product Stock=' . $item->product->stock_quantity);
        }

        if ($cartItems->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Cart is empty'
            ], 422);
        }

        // Verify stock availability
        foreach ($cartItems as $item) {
            if ($item->product->stock_quantity < $item->quantity) {
                return response()->json([
                    'success' => false,
                    'message' => "Insufficient stock for {$item->product->name}"
                ], 422);
            }
        }

        // Pharmacy guards: no expired stock, and every Rx line needs an approved prescription.
        if ($guard = $this->guardPharmacyRules($cartItems)) {
            return $guard;
        }

        DB::beginTransaction();

        try {
            // Calculate totals
            $subtotal = $cartItems->sum(function ($item) {
                return $item->quantity * $item->price;
            });

            // Handle shipping based on delivery type
            $deliveryType = $request->delivery_type ?? 'home_delivery';
            $shippingAmount = $deliveryType === 'store_pickup' ? 0 : $this->calculateShipping($subtotal, $request->shipping_address, $cartItems);

            if ($blocked = $this->guardShippingCoverage($deliveryType, $request->shipping_address)) {
                DB::rollBack();

                return $blocked;
            }

            $taxAmount = $this->calculateTax($subtotal, $request->shipping_address);
            
            // Set payment method
            if ($request->is_pay_on_delivery && ! $this->codIsEnabled()) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Cash on delivery is not available at the moment. Please pay online to place your order.',
                    'code' => 'cod_disabled',
                ], 422);
            }

            $paymentMethod = $request->payment_method ?? ($request->is_pay_on_delivery ? 'cash_on_delivery' : null);

            // Apply active sale events
            $saleDiscountResult = $this->calculateSaleDiscount($cartItems);
            $saleDiscount = $saleDiscountResult['discount'];
            $appliedSaleEvent = $saleDiscountResult['sale_event'];

            // Apply coupon if provided
            $couponDiscount = 0;
            $coupon = null;
            if ($request->coupon_code) {
                $couponResult = $this->applyCoupon($request->coupon_code, $subtotal, $cartItems, $userId);
                if ($couponResult['success']) {
                    $coupon = $couponResult['coupon'];
                    $couponDiscount = $couponResult['discount'];
                }
            }

            $totalDiscount = $saleDiscount + $couponDiscount;
            $totalAmount = $subtotal + $taxAmount + $shippingAmount - $totalDiscount;

            \Log::info('OrderController@store - Final order amounts', [
                'subtotal' => $subtotal,
                'shipping_amount' => $shippingAmount,
                'tax_amount' => $taxAmount,
                'total_discount' => $totalDiscount,
                'total_amount' => $totalAmount,
                'delivery_type' => $deliveryType,
                'shipping_zone_id' => $this->_lastShippingZoneId,
            ]);

            // Create order (store session_id for guest cart clearing after payment)
            $order = Order::create([
                'user_id' => $userId,
                'session_id' => $cartUsedGuestId ?? $guestId, // Store session_id for cart clearing after payment
                'order_number' => $this->generateOrderNumber(),
                'status' => Order::STATUS_PENDING,
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'shipping_amount' => $shippingAmount,
                'discount_amount' => $totalDiscount,
                'coupon_discount' => $couponDiscount,
                'sale_discount' => $saleDiscount,
                'total_amount' => $totalAmount,
                'currency' => 'NGN',
                'payment_status' => Order::PAYMENT_PENDING,
                'payment_method' => $paymentMethod,
                'is_pay_on_delivery' => $request->is_pay_on_delivery ?? false,
                'cod_fee' => 0, // No COD fee
                'delivery_type' => $deliveryType,
                'coupon_id' => $coupon?->id,
                'coupon_code' => $coupon?->code,
                'sale_event_id' => $appliedSaleEvent?->id,
                'sale_event_name' => $appliedSaleEvent?->name,
                'shipping_zone_id' => $this->_lastShippingZoneId,
                'shipping_address' => $request->shipping_address,
                'billing_address' => $request->billing_address ?? $request->shipping_address,
                'notes' => $request->notes
            ]);

            // Create order items and deduct stock
            foreach ($cartItems as $item) {
                \Log::info('OrderController@store - Creating order item', [
                    'product_id' => $item->product_id,
                    'product_name' => $item->product->name,
                    'cart_price' => $item->price,
                    'product_base_price' => $item->product->base_price,
                    'product_current_price' => $item->product->current_price,
                    'quantity' => $item->quantity,
                    'item_total' => $item->quantity * $item->price
                ]);
                
                $order->items()->create([
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                    'price' => $item->price,
                    'total' => $item->quantity * $item->price,
                    'prescription_id' => $item->prescription_id,
                    'required_prescription' => (bool) $item->product->requires_prescription,
                    'product_snapshot' => [
                        'name' => $item->product->name,
                        'sku' => $item->product->sku,
                        'generic_name' => $item->product->generic_name,
                        'brand_name' => $item->product->brand_name,
                        'manufacturer' => $item->product->manufacturer,
                        'strength' => $item->product->strength,
                        'dosage_form' => $item->product->dosage_form,
                        'pack_size' => $item->product->pack_size,
                        'batch_number' => $item->product->batch_number,
                        'expiry_date' => $item->product->expiry_date?->toDateString(),
                        'requires_prescription' => (bool) $item->product->requires_prescription,
                        // What the pharmacy is owed for this line, fixed at the
                        // moment of sale. Store revenue used to be recomputed
                        // from today's price, so starting a promotion shrank the
                        // money owed on orders already delivered and paid for.
                        'base_price' => (float) $item->product->base_price,
                        'store_id' => $item->product->store_id,
                        'images' => $item->product->images
                    ]
                ]);
            }

            // Attach the prescriptions to this order and roll their state up onto it,
            // so fulfilment can see at a glance whether the order is cleared to ship.
            $this->linkPrescriptionsToOrder($order, $cartItems);

            // Increment coupon usage
            if ($coupon) {
                $coupon->incrementUsage($userId);
            }

            // Create shipments for multi-store orders
            try {
                $order->createShipments();
                \Log::info('OrderController@store - Shipments created for order');
            } catch (\Exception $e) {
                \Log::error('OrderController@store - Failed to create shipments: ' . $e->getMessage());
            }

            // COD only. Online orders get theirs the moment payment is
            // confirmed, on every one of those paths rather than just the
            // webhook the old comment here named.
            if ($request->is_pay_on_delivery) {
                $order->ensureDeliveryCode();

                // Cash orders reached the pharmacy silently. The three online
                // payment paths all call this; the COD path never did, so a
                // store's first sight of a cash order was whenever it next
                // opened the orders page, and no administrator heard about it
                // at all.
                try {
                    (new \App\Services\OrderNotificationService())->notifyOrderPlaced($order->fresh());
                } catch (\Throwable $e) {
                    \Log::error('Failed to send COD order notifications: '.$e->getMessage(), [
                        'order_id' => $order->id,
                    ]);
                }
            }

            // Send order confirmation email for COD orders
            if ($request->is_pay_on_delivery && $request->shipping_address['email']) {
                try {
                    \Mail::to($request->shipping_address['email'])->send(
                        new \App\Mail\OrderStatusEmail($order->fresh(), 'confirmed')
                    );
                    \Log::info('OrderController@store - Order confirmation email sent for COD order');
                } catch (\Exception $e) {
                    \Log::error('OrderController@store - Failed to send order confirmation email: ' . $e->getMessage());
                }
            }

            // NOTE: Order placed notifications are now sent AFTER payment is confirmed
            // (in PaystackService::verifyPayment, webhook handler, or admin manual confirmation)
            // This prevents sending confirmation emails before payment is verified.

            // NOTE: Cart will be cleared AFTER successful payment by PaystackService
            // This prevents losing cart items if payment fails
            \Log::info('OrderController@store - Order created. Cart will be cleared after successful payment.');
            \Log::info('OrderController@store - Order session_id stored: ' . ($cartUsedGuestId ?? $guestId));
            \Log::info('OrderController@store - Order user_id stored: ' . $userId);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Order created successfully',
                'data' => $order->load(['items.product', 'coupon'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create order: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get single order
     */
    public function show($id): JsonResponse
    {
        $order = Order::with(['items.product', 'coupon', 'paymentTransactions'])
                     ->where('user_id', Auth::id())
                     ->find($id);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $order
        ]);
    }

    /**
     * Cancel order
     */
    public function cancel($id): JsonResponse
    {
        $order = Order::where('user_id', Auth::id())->find($id);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found'
            ], 404);
        }

        if (!in_array($order->status, [Order::STATUS_PENDING, Order::STATUS_PROCESSING])) {
            return response()->json([
                'success' => false,
                'message' => 'Order cannot be cancelled at this stage'
            ], 422);
        }

        $order->update([
            'status' => Order::STATUS_CANCELLED,
            'cancelled_at' => now()
        ]);

        // Call off the parcels too. Without this the pharmacies and any
        // assigned courier were never told, and the job stayed on their list.
        $order->syncShipmentsToStatus();

        // Restore stock quantities
        foreach ($order->items as $item) {
            $item->product->increment('stock_quantity', $item->quantity);
        }

        return response()->json([
            'success' => true,
            'message' => 'Order cancelled successfully'
        ]);
    }

    /**
     * Apply coupon to cart
     */
    public function applyCouponToCart(Request $request): JsonResponse
    {
        $request->validate([
            'coupon_code' => 'required|string'
        ]);

        $userId = Auth::id();
        $guestId = $request->header('X-Guest-ID');

        // Get cart items
        $cartItems = Cart::with('product')
                        ->when($userId, function ($query) use ($userId) {
                            return $query->where('user_id', $userId);
                        }, function ($query) use ($guestId) {
                            return $query->where('session_id', $guestId);
                        })
                        ->get();

        if ($cartItems->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Cart is empty'
            ], 422);
        }

        $cartTotal = $cartItems->sum(function ($item) {
            return $item->quantity * $item->price;
        });

        $result = $this->applyCoupon($request->coupon_code, $cartTotal, $cartItems, $userId);

        if ($result['success']) {
            // Save coupon to cart session
            CartSession::updateSession($userId, $guestId, [
                'coupon_id' => $result['coupon']->id,
                'coupon_code' => $result['coupon']->code,
                'discount_amount' => $result['discount'],
                'subtotal' => $cartTotal,
                'total' => $cartTotal - $result['discount']
            ]);
        }

        return response()->json($result);
    }

    /**
     * Remove coupon from cart
     */
    public function removeCouponFromCart(Request $request): JsonResponse
    {
        $userId = Auth::id();
        $guestId = $request->header('X-Guest-ID');

        // Remove cart session (coupon data)
        $deleted = CartSession::where(function ($query) use ($userId, $guestId) {
            if ($userId) {
                $query->where('user_id', $userId);
            } else {
                $query->where('session_id', $guestId);
            }
        })->delete();

        if ($deleted === 0) {
            return response()->json([
                'success' => false,
                'message' => 'No coupon found to remove'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Coupon removed successfully'
        ]);
    }

    /**
     * Whether cash on delivery may be used, per the Settings page.
     *
     * The `enable_cod` setting existed and gated nothing: a customer could pay
     * on delivery whatever it said. Defaults to true, so an operator who never
     * touches it keeps the behaviour they already had.
     */
    private function codIsEnabled(): bool
    {
        return \App\Models\SystemSetting::codEnabled();
    }

    /**
     * Calculate shipping cost with free shipping threshold and multi-store support
     */
    private function calculateShipping($subtotal, $address, $cartItems = null)
    {
        // Check free shipping threshold
        $freeShippingThreshold = \App\Models\SystemSetting::getValue(
            \App\Models\SystemSetting::CATEGORY_GENERAL, 
            'free_shipping_threshold', 
            50000
        );
        
        \Log::info('OrderController@calculateShipping - Threshold check', [
            'subtotal' => $subtotal,
            'free_shipping_threshold_raw' => $freeShippingThreshold,
            'free_shipping_threshold_type' => gettype($freeShippingThreshold),
            'free_shipping_threshold_numeric' => (float) $freeShippingThreshold,
            'would_be_free' => $subtotal >= $freeShippingThreshold,
        ]);
        
        $this->_lastShippingUncovered = false;
        $this->_lastShippingBreakdown = [];

        if ($freeShippingThreshold > 0 && $subtotal >= $freeShippingThreshold) {
            $this->_lastShippingZoneId = null;
            // Not "no cost" — a cost the platform has chosen to absorb, and the
            // shopper is entitled to see that it was a threshold that did it.
            $this->_lastShippingBreakdown = [[
                'reason' => 'free_shipping_threshold',
                'threshold' => (float) $freeShippingThreshold,
                'fee' => 0.0,
            ]];
            \Log::info('OrderController@calculateShipping - FREE SHIPPING triggered', [
                'subtotal' => $subtotal,
                'threshold' => $freeShippingThreshold
            ]);
            return 0; // Free shipping
        }
        
        // Get destination (customer's address)
        $destinationState = $address['state'] ?? null;
        $city = $address['city'] ?? null;
        $postalCode = $address['postalCode'] ?? $address['postal_code'] ?? null;
        
        if (!$destinationState) {
            \Log::warning('OrderController@calculateShipping - No state provided in address', ['address' => $address]);
            $this->_lastShippingZoneId = null;
            $this->_lastShippingUncovered = true;
            return 0; // Can't calculate without state
        }
        
        // If cart items provided, use multi-store calculation
        if ($cartItems && $cartItems->isNotEmpty()) {
            return $this->calculateMultiStoreShipping($cartItems, $destinationState, $city, $postalCode);
        }
        
        // Fallback: single origin calculation (for legacy or edge cases)
        $originState = $this->determineOriginState($cartItems);
        
        \Log::info('OrderController@calculateShipping - Single origin route', [
            'origin' => $originState,
            'destination' => $destinationState
        ]);
        
        $shippingZone = ShippingZone::findByRoute($originState, $destinationState, $city, $postalCode);
        
        if ($shippingZone) {
            $fee = $shippingZone->calculateShippingFee();
            $this->_lastShippingZoneId = $shippingZone->id;
            $this->_lastShippingBreakdown = [[
                'store' => null,
                'from' => $originState,
                'to' => $destinationState,
                'zone' => $shippingZone->name,
                'fee' => (float) $fee,
                'charged' => true,
            ]];
            
            \Log::info('OrderController@calculateShipping - Shipping zone found', [
                'zone_name' => $shippingZone->name,
                'shipping_fee' => $fee
            ]);
            
            return $fee;
        }
        
        \Log::warning('OrderController@calculateShipping - No shipping zone found', [
            'origin' => $originState,
            'destination' => $destinationState
        ]);

        $this->_lastShippingZoneId = null;
        $this->_lastShippingUncovered = true;
        return 0;
    }
    
    /**
     * Determine shipping fee for multi-store carts
     * Uses the HIGHEST shipping fee when multiple stores are involved
     */
    private function determineOriginState($cartItems = null)
    {
        // This method is now deprecated but kept for backward compatibility
        // Use calculateShipping() which handles multi-store logic
        
        if ($cartItems && $cartItems->isNotEmpty()) {
            $firstProduct = $cartItems->first()->product;
            
            if ($firstProduct && $firstProduct->store && $firstProduct->store->state) {
                return $firstProduct->store->state;
            }
        }
        
        return \App\Models\SystemSetting::getValue(
            \App\Models\SystemSetting::CATEGORY_GENERAL,
            'default_warehouse_state',
            'Lagos'
        );
    }
    
    /**
     * Calculate shipping for multi-store orders.
     *
     * One journey per pharmacy, and the customer pays for each of them.
     *
     * This used to charge the dearest leg alone and let the rest ride along.
     * That was a discount nobody had asked for, and it did not survive contact
     * with how couriers are paid: each leg is settled at that courier's own
     * agreed rate, so a Lagos-and-Port-Harcourt basket collected the Lagos fee
     * and paid out both. The platform funded the gap on every genuinely split
     * order, and the shortfall grew with distance.
     *
     * What is summed is pickup runs, not pharmacies. Two shops in the same
     * city are one rider's round — collected together, driven together — so
     * they are priced once. Charging twice would bill for a journey nobody
     * makes. Different cities are genuinely different rounds and are charged
     * separately, whether or not they share a state.
     *
     * So the fee follows *journeys*: never items, and not shops either.
     */
    private function calculateMultiStoreShipping($cartItems, $destinationState, $city = null, $postalCode = null)
    {
        // Group cart items by store
        /*
         * Grouped by where the parcels are collected, not by which shop sells
         * them. A shop with no city on file falls back to its own id, so it
         * stays a run of one rather than joining every other addressless
         * pharmacy in an imaginary shared round.
         */
        $storeGroups = $cartItems->groupBy(function ($item) {
            $store = $item->product->store ?? null;

            return \App\Models\OrderShipment::pickupGroupFor($store)
                ?? 'store:'.($item->product->store_id ?? 0);
        });
        
        $totalShippingFee = 0;
        // The longest journey, kept for the delivery estimate and for the zone
        // stamped on the order: an order is done when its slowest parcel
        // arrives, so the slowest leg is the one that describes it.
        $dearestFee = 0;
        $selectedZone = null;
        $legs = [];
        
        \Log::info('OrderController@calculateMultiStoreShipping - Processing multi-store cart', [
            'pickup_runs' => $storeGroups->count(),
            'destination' => $destinationState
        ]);
        
        foreach ($storeGroups as $pickupGroup => $items) {
            $firstItem = $items->first();
            $store = $firstItem->product->store ?? null;

            // Every shop in this run is in the same city, so any of them names
            // the journey. The rest are listed so the breakdown can say who is
            // being collected from on it.
            $shopsOnRun = $items
                ->groupBy(fn ($item) => $item->product->store_id)
                ->map(fn ($lines) => $lines->first()->product->store->name ?? null)
                ->filter()
                ->values()
                ->all();
            
            $originState = \App\Models\SystemSetting::getValue(
                \App\Models\SystemSetting::CATEGORY_GENERAL,
                'default_warehouse_state',
                'Lagos'
            );
            
            if ($store && $store->state) {
                $originState = $store->state;
            }
            
            \Log::info('OrderController@calculateMultiStoreShipping - Pickup run origin', [
                'pickup_group' => $pickupGroup,
                'shops' => $shopsOnRun,
                'store_state' => $store->state ?? 'N/A',
                'store_city' => $store->city ?? 'N/A',
                'origin_used' => $originState,
            ]);
            
            // Find shipping zone for this route
            $zone = ShippingZone::findByRoute($originState, $destinationState, $city, $postalCode);

            $legs[] = [
                'pickup_group' => $pickupGroup,
                // Every shop collected on this one round, so a shopper reading
                // one fee against two pharmacies can see why it is one fee.
                'stores' => $shopsOnRun,
                'store' => $shopsOnRun ? implode(' and ', $shopsOnRun) : $store?->name,
                // Null-safe: buy-now reaches here without a store loaded, and a
                // product whose shop has been removed still has to price.
                'from' => $store?->city ? $store->city.', '.$originState : $originState,
                'to' => $destinationState,
                'zone' => $zone?->name,
                'fee' => $zone ? (float) $zone->calculateShippingFee() : null,
            ];

            if ($zone) {
                $fee = $zone->calculateShippingFee();
                
                \Log::info('OrderController@calculateMultiStoreShipping - Zone found for run', [
                    'pickup_group' => $pickupGroup,
                    'shops' => $shopsOnRun,
                    'origin' => $originState,
                    'destination' => $destinationState,
                    'zone_name' => $zone->name,
                    'fee' => $fee
                ]);
                
                $totalShippingFee += $fee;

                if ($fee > $dearestFee) {
                    $dearestFee = $fee;
                    $selectedZone = $zone;
                }
            }
        }
        
        if ($selectedZone) {
            \Log::info('OrderController@calculateMultiStoreShipping - Charging every leg', [
                'legs' => count($legs),
                'total' => $totalShippingFee,
                'longest_leg_zone' => $selectedZone->name,
            ]);
            $this->_lastShippingZoneId = $selectedZone->id;
        } else {
            $this->_lastShippingZoneId = null;
            // Not one of the stores in this basket has a route to the customer.
            $this->_lastShippingUncovered = true;
        }

        // Every priced leg is charged. An unpriced one is not a free delivery —
        // it means no zone covers that route, and `guardShippingCoverage`
        // refuses the order rather than letting it through at nothing.

        foreach ($legs as $index => $leg) {
            $legs[$index]['charged'] = $leg['fee'] !== null;
        }

        $this->_lastShippingBreakdown = $legs;

        return $totalShippingFee;
    }

    /**
     * Refuses a home delivery to somewhere no zone covers.
     *
     * Returning a ₦0 fee for an unreachable address let the order through and
     * left the platform paying the courier itself.
     */
    private function guardShippingCoverage(string $deliveryType, $address): ?JsonResponse
    {
        if ($deliveryType === 'store_pickup' || ! $this->_lastShippingUncovered) {
            return null;
        }

        $state = is_array($address) ? ($address['state'] ?? null) : null;

        return response()->json([
            'success' => false,
            'message' => $state
                ? "We don't deliver to {$state} yet. Choose store pickup or a different delivery address."
                : 'A delivery state is required before we can work out shipping.',
            'code' => 'shipping_not_covered',
        ], 422);
    }

    /**
     * Calculate tax based on system settings
     */
    private function calculateTax($subtotal, $address)
    {
        $taxRate = \App\Models\SystemSetting::getValue(
            \App\Models\SystemSetting::CATEGORY_GENERAL, 
            'default_tax_rate', 
            0 // Default to 0% (admin can change in settings)
        );
        
        // Calculate tax amount
        $taxAmount = ($subtotal * $taxRate) / 100;
        
        return $taxAmount;
    }

    /**
     * Calculate sale discount from active events
     */
    private function calculateSaleDiscount($cartItems)
    {
        $activeSales = SaleEvent::active()->byPriority()->get();
        $totalDiscount = 0;
        $appliedSale = null;

        foreach ($cartItems as $item) {
            $product = $item->product;
            $originalPrice = $product->sale_price ?? $product->price;
            
            foreach ($activeSales as $sale) {
                $discountedPrice = $sale->applyToProduct($product);
                if ($discountedPrice < $originalPrice) {
                    $itemDiscount = ($originalPrice - $discountedPrice) * $item->quantity;
                    $totalDiscount += $itemDiscount;
                    if (!$appliedSale) {
                        $appliedSale = $sale; // Store the first applied sale for reference
                    }
                    break; // Apply only the highest priority sale
                }
            }
        }

        return [
            'discount' => $totalDiscount,
            'sale_event' => $appliedSale
        ];
    }

    /**
     * Apply coupon and calculate discount
     */
    private function applyCoupon($couponCode, $cartTotal, $cartItems, $userId = null)
    {
        $coupon = Coupon::byCode($couponCode)->first();

        if (!$coupon) {
            return [
                'success' => false,
                'message' => 'Invalid coupon code'
            ];
        }

        $validation = $coupon->isValid($userId, $cartTotal, $cartItems->toArray());
        
        if (!$validation['valid']) {
            return [
                'success' => false,
                'message' => $validation['message']
            ];
        }

        $discount = $coupon->calculateDiscount($cartTotal, $cartItems->toArray());

        return [
            'success' => true,
            'message' => 'Coupon applied successfully',
            'coupon' => $coupon,
            'discount' => $discount,
            'data' => [
                'code' => $coupon->code,
                'name' => $coupon->name,
                'type' => $coupon->type,
                'discount_amount' => $discount
            ]
        ];
    }

    /**
     * Generate a unique order number, e.g. TG-20260800017.
     *
     * `order_number` carries a unique index, and the previous implementation
     * read the highest existing number and added one — so two checkouts landing
     * in the same moment both computed the same value and the second died on a
     * duplicate-key error at the end of checkout, after payment had been set up.
     * The counter was also four digits wide, which capped a month at 9,999
     * orders and then produced collisions permanently.
     *
     * A short retry loop closes the race in practice, and the final fallback
     * guarantees termination rather than looping under sustained contention.
     */
    private function generateOrderNumber()
    {
        $prefix = config('app.order_prefix', 'TG');
        $period = date('Ym');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $lastOrder = Order::where('order_number', 'like', "{$prefix}-{$period}%")
                ->orderBy('order_number', 'desc')
                ->first();

            $next = $lastOrder
                ? ((int) substr($lastOrder->order_number, strlen("{$prefix}-{$period}"))) + 1
                : 1;

            $candidate = "{$prefix}-{$period}".str_pad((string) $next, 5, '0', STR_PAD_LEFT);

            if (! Order::where('order_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        // Contention or an exhausted month: fall back to a random suffix, which
        // is still unique but no longer sequential.
        return "{$prefix}-{$period}".strtoupper(Str::random(6));
    }

    /**
     * Admin: Get all orders with filtering and pagination
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $user = $request->user();
        
        $query = Order::with(['user', 'items.product', 'coupon', 'saleEvent']);

        // Only orders carrying this shop's stock — see ScopesToStore. The role
        // string comparisons this replaces matched 'store_owner' and 'staff'
        // only, so a store manager saw every pharmacy's orders and customers.
        $this->scopeOrdersToStore($query, $request);

        // Apply filters
        if ($request->has('status') && $request->status !== '') {
            $query->where('status', $request->status);
        }

        if ($request->has('payment_status') && $request->payment_status !== '') {
            $query->where('payment_status', $request->payment_status);
        }

        if ($request->has('payment_method') && $request->payment_method !== '') {
            $query->where('payment_method', $request->payment_method);
        }

        if ($request->has('search') && $request->search !== '') {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($userQuery) use ($search) {
                      $userQuery->where('name', 'like', "%{$search}%")
                               ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        if ($request->has('date_from') && $request->date_from !== '') {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->has('date_to') && $request->date_to !== '') {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $orders = $query->orderBy('created_at', 'desc')
                       ->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $orders->items(),
            'pagination' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total()
            ]
        ]);
    }

    /**
     * Admin: Get single order details
     */
    public function adminShow($id): JsonResponse
    {
        $user = auth()->user();
        $order = Order::with(['user', 'items.product.store', 'items.prescription.reviewer', 'coupon', 'saleEvent', 'paymentTransactions', 'shipments.store', 'shipments.deliveryAgent.logisticsCompany', 'shipments.logisticsCompany', 'deliveryAgent.logisticsCompany', 'logisticsCompany', 'trackingEvents'])
                     ->findOrFail($id);

        // Anyone who is not a platform admin sees only orders carrying their own
        // stock, and only their own lines within them. This was written twice —
        // once for 'store_owner' and once for 'staff' — leaving every other store
        // role with full access, and the staff copy referenced a $store variable
        // that only existed in the owner branch, so it fataled when it did run.
        if ($user && ! $user->isPlatformAdmin()) {
            $storeId = $user->storeScopeId();

            $hasAccess = $storeId !== null && $order->items->contains(
                fn ($item) => $item->product && (int) $item->product->store_id === $storeId
            );

            if (! $hasAccess) {
                // Same answer as an order that does not exist, so ids cannot be
                // probed for what other pharmacies are shipping.
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found',
                ], 404);
            }

            $order->setRelation('items', $order->items->filter(
                fn ($item) => $item->product && (int) $item->product->store_id === $storeId
            )->values());

            // And only its own parcel. An order split between two pharmacies
            // carries a shipment each, and the other one's tracking number,
            // rider and delivery window are not this pharmacy's business.
            $order->setRelation('shipments', $order->shipments->filter(
                fn ($shipment) => (int) $shipment->store_id === $storeId
            )->values());
        }

        // The prescriptions this order was bought against, drawn from the item
        // list *after* the store scoping above — so a pharmacy sees the
        // prescriptions covering its own lines and nobody else's.
        $payload = $order->toArray();
        $payload['prescriptions'] = $this->orderPrescriptions($order, $user);

        return response()->json([
            'success' => true,
            'data' => $payload
        ]);
    }

    /**
     * The prescriptions attached to an order's lines, ready to be reviewed.
     *
     * Prescriptions were reachable only from a queue of their own — a flat list
     * of documents with no order beside them. A pharmacist deciding whether a
     * prescription covers a purchase has to see the purchase, and had to go and
     * find it by hand; a rejection cancels the order and refunds it, so that is
     * not a decision anyone should be making from a filename.
     *
     * One row per prescription rather than per line, because one document
     * routinely covers several medicines in the same basket, and reviewing it
     * three times is both three chances to decide differently and three audit
     * entries for one judgement. The lines it covers are listed inside it.
     *
     * Never includes `file_path`. The document is served only through
     * PrescriptionController::download, which re-checks who is asking.
     */
    private function orderPrescriptions(Order $order, $user): array
    {
        $isAdmin = $user && $user->isPlatformAdmin();
        $storeId = $user && ! $isAdmin ? $user->storeScopeId() : null;

        $grouped = [];

        foreach ($order->items as $item) {
            $prescription = $item->prescription;

            if (! $prescription) {
                continue;
            }

            if (! isset($grouped[$prescription->id])) {
                // Who may act on it. An admin always may; a pharmacy may only
                // for prescriptions routed to it. This mirrors the checks in
                // PrescriptionController::review() rather than replacing them —
                // the API still decides, this only stops the dashboard offering
                // a button that can only fail.
                $ownsIt = $isAdmin || ($storeId !== null && (int) $prescription->store_id === $storeId);

                $settled = $prescription->status !== \App\Models\Prescription::STATUS_PENDING;

                $canReview = $ownsIt && (
                    ! $settled
                    // A store reviewer can never re-decide; an admin can, if
                    // policy allows, and that override is recorded.
                    || ($isAdmin && \App\Support\PharmacyPolicy::allowAdminPrescriptionOverride())
                );

                $grouped[$prescription->id] = [
                    'id' => $prescription->id,
                    'status' => $prescription->status,
                    'is_usable' => $prescription->isUsable(),
                    'original_filename' => $prescription->original_filename,
                    'mime_type' => $prescription->mime_type,
                    'file_size' => $prescription->file_size,
                    'download_url' => "/api/v1/prescriptions/{$prescription->id}/download",
                    'patient_name' => $prescription->patient_name,
                    'doctor_name' => $prescription->doctor_name,
                    'doctor_license' => $prescription->doctor_license,
                    'doctor_email' => $prescription->doctor_email,
                    'doctor_phone' => $prescription->doctor_phone,
                    'hospital_name' => $prescription->hospital_name,
                    'issued_date' => $prescription->issued_date?->toDateString(),
                    'expires_at' => $prescription->expires_at?->toDateString(),
                    'notes' => $prescription->notes,
                    'rejection_reason' => $prescription->rejection_reason,
                    'reviewed_at' => $prescription->reviewed_at?->toIso8601String(),
                    'reviewed_by_type' => $prescription->reviewed_by_type,
                    'reviewed_by' => $prescription->reviewer?->name,
                    'can_review' => $canReview,
                    'is_reviewed' => $settled,
                    'uploaded_at' => $prescription->created_at?->toIso8601String(),
                    'items' => [],
                ];
            }

            $grouped[$prescription->id]['items'][] = [
                'id' => $item->id,
                // The snapshot first: it is what was actually bought, and it
                // survives the product being renamed or delisted afterwards.
                'name' => $item->product_snapshot['name'] ?? $item->product?->name,
                'quantity' => $item->quantity,
                'requires_prescription' => (bool) $item->required_prescription,
            ];
        }

        return array_values($grouped);
    }

    /**
     * Admin: Update order status
     */
    public function adminUpdateStatus(Request $request, $id): JsonResponse
    {
        $request->validate([
            'status' => 'required|in:pending,processing,ready_for_pickup,shipped,delivered,cancelled,refunded',
            'tracking_number' => 'nullable|string',
            'notes' => 'nullable|string'
        ]);

        $user = auth()->user();
        $order = Order::with('items.product.store')->findOrFail($id);
        
        // Check if payment has been made (except for cancelled/refunded status)
        if (!in_array($request->status, ['cancelled', 'refunded']) && $order->payment_status !== Order::PAYMENT_PAID) {
            return response()->json([
                'success' => false,
                'message' => 'Order status cannot be updated until payment is confirmed'
            ], 400);
        }

        // An order containing prescription-only items must not move toward dispatch
        // until every one of those prescriptions has been approved.
        if (in_array($request->status, ['ready_for_pickup', 'shipped', 'delivered'])
            && ! $order->isClearedForDispatch()) {
            return response()->json([
                'success' => false,
                'message' => 'This order contains prescription medicines awaiting approval. '
                    . "Current prescription status: {$order->prescription_status}.",
                'code' => 'prescription_not_cleared'
            ], 422);
        }
        
        // Everything a shop may do to an order. This was gated on the literal
        // role 'store_owner', so a store manager — who holds the same
        // orders.update_status permission — skipped all of it and could mark an
        // order delivered or refunded, crediting couriers and flagging refunds
        // on a delivery that never happened.
        if ($user && ! $user->isPlatformAdmin()) {
            $storeId = $user->storeScopeId();

            $hasAccess = $storeId !== null && $order->items->contains(
                fn ($item) => $item->product && (int) $item->product->store_id === $storeId
            );

            if (! $hasAccess) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found',
                ], 404);
            }

            // A shop prepares and hands over. Dispatch, delivery, cancellation
            // and refunds stay with the platform.
            $allowedStatuses = ['processing', 'ready_for_pickup'];

            if (!in_array($request->status, $allowedStatuses)) {
                return response()->json([
                    'success' => false,
                    'message' => 'A store can only mark orders as "processing" or "ready for pickup".',
                    'code' => 'status_not_permitted_for_store',
                ], 403);
            }

            // Validate status transition
            $currentStatus = $order->status;
            $newStatus = $request->status;
            
            if ($currentStatus === 'pending' && $newStatus !== 'processing') {
                return response()->json([
                    'success' => false,
                    'message' => 'You must mark order as "processing" first'
                ], 400);
            }
            
            if ($currentStatus === 'processing' && $newStatus !== 'ready_for_pickup') {
                return response()->json([
                    'success' => false,
                    'message' => 'You can only mark order as "ready for pickup" after processing'
                ], 400);
            }
        }
        
        $oldStatus = $order->status;
        $order->status = $request->status;

        // Update timestamps based on status
        switch ($request->status) {
            case Order::STATUS_SHIPPED:
                if (!$order->shipped_at) {
                    $order->shipped_at = now();
                }
                if ($request->tracking_number) {
                    $order->tracking_number = $request->tracking_number;
                }
                break;
            case Order::STATUS_DELIVERED:
                if (!$order->delivered_at) {
                    $order->delivered_at = now();
                }
                break;
            case Order::STATUS_CANCELLED:
                if (!$order->cancelled_at) {
                    $order->cancelled_at = now();
                }
                break;
            case Order::STATUS_REFUNDED:
                if (!$order->refunded_at) {
                    $order->refunded_at = now();
                }
                break;
        }

        if ($request->notes) {
            $order->notes = $request->notes;
        }

        $order->save();

        /*
         * Bring the shipment along. The rider's portal and the logistics
         * dashboard read the shipment rather than the order, so without this an
         * admin marking an order shipped left both of them still showing it as
         * merely assigned — and the rider's own "picked up" would then drag the
         * order backwards.
         */
        $order->syncShipmentsToStatus();

        // Create tracking event for status change
        $order->createTrackingEventForStatus($request->status, $oldStatus);

        // Send notifications using OrderNotificationService
        if ($oldStatus !== $request->status) {
            $notificationService = new \App\Services\OrderNotificationService();
            $notificationService->notifyStatusUpdate($order->fresh(), $oldStatus, $request->status);
            
            // Keep existing email for backward compatibility
            $hasEmail = $order->user || isset($order->shipping_address['email']);
            
            if ($hasEmail) {
                $statusMap = [
                    Order::STATUS_PENDING => 'confirmed',
                    'processing' => 'processing',
                    Order::STATUS_SHIPPED => 'shipped',
                    Order::STATUS_DELIVERED => 'delivered',
                    Order::STATUS_CANCELLED => 'cancelled',
                ];
                
                $emailStatus = $statusMap[$request->status] ?? null;
                
                if ($emailStatus) {
                    SendOrderStatusEmail::dispatch($order, $emailStatus);
                    \Log::info("Order status email queued: {$order->order_number} - {$emailStatus}", [
                        'is_guest' => !$order->user
                    ]);
                }
                
                // Schedule follow-up email when order is delivered
                if ($request->status === Order::STATUS_DELIVERED) {
                    $config = EmailAutomationSetting::getConfig(
                        EmailAutomationSetting::ORDER_FOLLOW_UP,
                        ['delay_days' => 7]
                    );
                    $delayDays = $config['delay_days'] ?? 7;
                    
                    SendOrderFollowUpEmail::dispatch($order)
                        ->delay(now()->addDays($delayDays));
                        
                    \Log::info("Order follow-up email scheduled for {$delayDays} days: {$order->order_number}", [
                        'is_guest' => !$order->user
                    ]);
                }
            } else {
                \Log::warning("Cannot send order status email - no email found", [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'status' => $request->status
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => "Order status updated from {$oldStatus} to {$request->status}",
            'data' => $order
        ]);
    }

    /**
     * Admin: Get order statistics
     */
    public function adminStats(Request $request): JsonResponse
    {
        // Every count here was taken across the whole platform, so a shop's
        // order tiles reported the total revenue of every other pharmacy.
        $scoped = fn () => $this->scopeOrdersToStore(Order::query(), $request);

        $stats = [
            'total_orders' => $scoped()->count(),
            'pending_orders' => $scoped()->where('status', Order::STATUS_PENDING)->count(),
            'processing_orders' => $scoped()->where('status', Order::STATUS_PROCESSING)->count(),
            'shipped_orders' => $scoped()->where('status', Order::STATUS_SHIPPED)->count(),
            'delivered_orders' => $scoped()->where('status', Order::STATUS_DELIVERED)->count(),
            'cancelled_orders' => $scoped()->where('status', Order::STATUS_CANCELLED)->count(),
            'total_revenue' => $scoped()->where('payment_status', Order::PAYMENT_PAID)->sum('total_amount'),
            'pending_payments' => $scoped()->where('payment_status', Order::PAYMENT_PENDING)->count(),
            'failed_payments' => $scoped()->where('payment_status', Order::PAYMENT_FAILED)->count(),
            'recent_orders' => $scoped()->with(['user', 'items'])
                                   ->orderBy('created_at', 'desc')
                                   ->limit(5)
                                   ->get(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    /**
     * Buy Now - Create order directly from product without cart
     */
    public function buyNow(Request $request): JsonResponse
    {
        \Log::info('=== BUY NOW ORDER CREATION STARTED ===');
        \Log::info('OrderController@buyNow - Request timestamp: ' . now());
        
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
            'shipping_address' => 'required|array',
            'shipping_address.firstName' => 'required|string',
            'shipping_address.lastName' => 'required|string',
            'shipping_address.email' => 'required|email',
            'shipping_address.address' => 'required|string',
            'shipping_address.city' => 'required|string',
            'shipping_address.state' => 'required|string',
            'shipping_address.postalCode' => 'nullable|string',
            'shipping_address.country' => 'required|string',
            'shipping_address.phone' => 'required|string',
            'billing_address' => 'nullable|array',
            'coupon_code' => 'nullable|string',
            'notes' => 'nullable|string',
            'payment_method' => 'nullable|string',
            'is_pay_on_delivery' => 'nullable|boolean',
            'delivery_type' => 'nullable|in:home_delivery,pickup,store_pickup'
        ]);

        // Resolved by the `auth.optional` middleware: present for a signed-in
        // shopper, absent for a guest. This used to hand-decode the bearer
        // token here, duplicating the middleware and pinning this route to the
        // old unsigned token format.
        $userId = $request->user()?->id;
        
        $guestId = $request->header('X-Guest-ID');
        
        \Log::info('OrderController@buyNow - User ID: ' . $userId);
        \Log::info('OrderController@buyNow - Guest ID: ' . $guestId);

        // Get product
        $product = Product::with('store')->find($request->product_id);
        
        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }

        // Check stock availability
        if ($product->stock_quantity < $request->quantity) {
            return response()->json([
                'success' => false,
                'message' => "Insufficient stock for {$product->name}. Available: {$product->stock_quantity}"
            ], 422);
        }

        /*
         * Same pharmacy guards as the cart checkout — buy-now must not be a way
         * around the expiry and prescription rules.
         *
         * The prescription id is resolved against the caller first. Taking it
         * straight from the request body let anyone quote a stranger's approved
         * prescription, and prescription ids are sequential.
         */
        $prescription = $this->resolveOwnPrescription($request, $request->input('prescription_id'));

        if ($request->filled('prescription_id') && ! $prescription) {
            return response()->json([
                'success' => false,
                'message' => 'Prescription not found',
            ], 404);
        }

        $buyNowLine = (object) [
            'product' => $product,
            'prescription_id' => $prescription?->id,
        ];

        if ($guard = $this->guardPharmacyRules([$buyNowLine])) {
            return $guard;
        }

        DB::beginTransaction();

        try {
            // Calculate price (use current_price which includes platform markup)
            $price = $product->current_price;
            $subtotal = $price * $request->quantity;
            
            \Log::info('OrderController@buyNow - Price calculation', [
                'product_base_price' => $product->base_price,
                'product_current_price' => $product->current_price,
                'price_used' => $price,
                'quantity' => $request->quantity,
                'subtotal' => $subtotal
            ]);

            // Create a pseudo cart item collection for shipping calculation
            $pseudoCartItem = (object)[
                'product_id' => $product->id,
                'product' => $product,
                'quantity' => $request->quantity,
                'price' => $price
            ];
            $pseudoCartItems = collect([$pseudoCartItem]);

            // Handle shipping based on delivery type
            $deliveryType = $request->delivery_type ?? 'home_delivery';
            $shippingAmount = $deliveryType === 'store_pickup' ? 0 : $this->calculateShipping($subtotal, $request->shipping_address, $pseudoCartItems);

            if ($blocked = $this->guardShippingCoverage($deliveryType, $request->shipping_address)) {
                DB::rollBack();

                return $blocked;
            }

            $taxAmount = $this->calculateTax($subtotal, $request->shipping_address);
            
            // Set payment method
            if ($request->is_pay_on_delivery && ! $this->codIsEnabled()) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Cash on delivery is not available at the moment. Please pay online to place your order.',
                    'code' => 'cod_disabled',
                ], 422);
            }

            $paymentMethod = $request->payment_method ?? ($request->is_pay_on_delivery ? 'cash_on_delivery' : null);

            // Apply active sale events
            $saleDiscountResult = $this->calculateSaleDiscountForProduct($product, $request->quantity);
            $saleDiscount = $saleDiscountResult['discount'];
            $appliedSaleEvent = $saleDiscountResult['sale_event'];

            // Apply coupon if provided
            $couponDiscount = 0;
            $coupon = null;
            if ($request->coupon_code) {
                // Create temporary cart item structure for coupon validation
                $tempCartItems = collect([
                    (object)[
                        'product_id' => $product->id,
                        'product' => $product,
                        'quantity' => $request->quantity,
                        'price' => $price
                    ]
                ]);
                
                $couponResult = $this->applyCoupon($request->coupon_code, $subtotal, $tempCartItems, $userId);
                if ($couponResult['success']) {
                    $coupon = $couponResult['coupon'];
                    $couponDiscount = $couponResult['discount'];
                }
            }

            $totalDiscount = $saleDiscount + $couponDiscount;
            $totalAmount = $subtotal + $taxAmount + $shippingAmount - $totalDiscount;

            \Log::info('OrderController@buyNow - Final order amounts', [
                'subtotal' => $subtotal,
                'shipping_amount' => $shippingAmount,
                'tax_amount' => $taxAmount,
                'total_discount' => $totalDiscount,
                'total_amount' => $totalAmount,
                'delivery_type' => $deliveryType,
                'shipping_zone_id' => $this->_lastShippingZoneId,
            ]);

            // Create order
            $order = Order::create([
                'user_id' => $userId,
                'session_id' => $guestId,
                'order_number' => $this->generateOrderNumber(),
                'status' => Order::STATUS_PENDING,
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'shipping_amount' => $shippingAmount,
                'discount_amount' => $totalDiscount,
                'coupon_discount' => $couponDiscount,
                'sale_discount' => $saleDiscount,
                'total_amount' => $totalAmount,
                'currency' => 'NGN',
                'payment_status' => Order::PAYMENT_PENDING,
                'payment_method' => $paymentMethod,
                'is_pay_on_delivery' => $request->is_pay_on_delivery ?? false,
                'cod_fee' => 0, // No COD fee
                'delivery_type' => $deliveryType,
                'coupon_id' => $coupon?->id,
                'coupon_code' => $coupon?->code,
                'sale_event_id' => $appliedSaleEvent?->id,
                'sale_event_name' => $appliedSaleEvent?->name,
                'shipping_zone_id' => $this->_lastShippingZoneId,
                'shipping_address' => $request->shipping_address,
                'billing_address' => $request->billing_address ?? $request->shipping_address,
                'notes' => $request->notes
            ]);

            // Create order item
            $order->items()->create([
                'product_id' => $product->id,
                'quantity' => $request->quantity,
                'price' => $price,
                'total' => $request->quantity * $price,
                // The resolved record, not the raw request value — see above.
                'prescription_id' => $prescription?->id,
                'required_prescription' => (bool) $product->requires_prescription,
                'product_snapshot' => [
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'generic_name' => $product->generic_name,
                    'brand_name' => $product->brand_name,
                    'manufacturer' => $product->manufacturer,
                    'strength' => $product->strength,
                    'dosage_form' => $product->dosage_form,
                    'pack_size' => $product->pack_size,
                    'batch_number' => $product->batch_number,
                    'expiry_date' => $product->expiry_date?->toDateString(),
                    'requires_prescription' => (bool) $product->requires_prescription,
                    // Fixed at the moment of sale — see the basket checkout.
                    'base_price' => (float) $product->base_price,
                    'store_id' => $product->store_id,
                    'images' => $product->images
                ]
            ]);

            $this->linkPrescriptionsToOrder($order, [$buyNowLine]);

            // Increment coupon usage
            if ($coupon) {
                $coupon->incrementUsage($userId);
            }

            // Create shipments for multi-store orders
            try {
                $order->createShipments();
                \Log::info('OrderController@buyNow - Shipments created for order');
            } catch (\Exception $e) {
                \Log::error('OrderController@buyNow - Failed to create shipments: ' . $e->getMessage());
            }

            // COD only. Online orders get theirs the moment payment is
            // confirmed, on every one of those paths rather than just the
            // webhook the old comment here named.
            if ($request->is_pay_on_delivery) {
                $order->ensureDeliveryCode();

                // Cash orders reached the pharmacy silently. The three online
                // payment paths all call this; the COD path never did, so a
                // store's first sight of a cash order was whenever it next
                // opened the orders page, and no administrator heard about it
                // at all.
                try {
                    (new \App\Services\OrderNotificationService())->notifyOrderPlaced($order->fresh());
                } catch (\Throwable $e) {
                    \Log::error('Failed to send COD order notifications: '.$e->getMessage(), [
                        'order_id' => $order->id,
                    ]);
                }
            }

            // Send order confirmation email for COD orders
            if ($request->is_pay_on_delivery && $request->shipping_address['email']) {
                try {
                    \Mail::to($request->shipping_address['email'])->send(
                        new \App\Mail\OrderStatusEmail($order->fresh(), 'confirmed')
                    );
                    \Log::info('OrderController@buyNow - Order confirmation email sent for COD order');
                } catch (\Exception $e) {
                    \Log::error('OrderController@buyNow - Failed to send order confirmation email: ' . $e->getMessage());
                }
            }

            \Log::info('OrderController@buyNow - Order created successfully: ' . $order->order_number);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Order created successfully',
                'data' => $order->load(['items.product', 'coupon'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('OrderController@buyNow - Order creation failed: ' . $e->getMessage(), [
                'exception' => $e,
                'request' => $request->all()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Order creation failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * What to tell the customer about the prescription state of their order.
     *
     * Returns null when there is nothing to say, so the storefront can simply
     * skip the notice.
     */
    private function prescriptionMessageFor(Order $order): ?string
    {
        if (! $order->requires_prescription) {
            return null;
        }

        return match ($order->prescription_status) {
            'pending' => 'A pharmacist is reviewing your prescription. We will dispatch as soon as it is approved, '
                .'usually within a few hours. If it cannot be approved, this order is cancelled and refunded in full.',
            'rejected' => 'Your prescription could not be approved, so this order was cancelled. '
                .'Any payment is being refunded.',
            'approved' => 'Your prescription has been approved and your order is being prepared.',
            default => null,
        };
    }

    /**
     * Get order confirmation details (public endpoint for guest users)
     */
    public function getOrderConfirmation(Request $request, $id): JsonResponse
    {
        try {
            $order = Order::with(['items.product', 'coupon'])->find($id);

            // This returns the customer's name, phone and delivery address and
            // previously had no ownership check at all, so walking order ids
            // harvested the whole customer list.
            if (! $this->canAccessOrder($request, $order)) {
                return $this->orderNotFound();
            }

            // Return limited order information for confirmation page
            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                    'payment_status' => $order->payment_status,
                    'payment_method' => $order->payment_method,
                    'delivery_type' => $order->delivery_type,
                    'total_amount' => $order->total_amount,
                    'subtotal' => $order->subtotal,
                    'shipping_amount' => $order->shipping_amount,
                    'tax_amount' => $order->tax_amount,
                    'discount_amount' => $order->discount_amount,
                    'shipping_address' => $order->shipping_address,
                    'billing_address' => $order->billing_address,
                    'items' => $order->items,
                    'created_at' => $order->created_at,
                    /*
                     * The confirmation page has to say so when an order is paid
                     * but held for a pharmacist. Otherwise the customer sees a
                     * successful payment, no dispatch, and no explanation.
                     */
                    'requires_prescription' => (bool) $order->requires_prescription,
                    'prescription_status' => $order->prescription_status,
                    'awaiting_prescription_review' => $order->requires_prescription
                        && $order->prescription_status === 'pending',
                    'prescription_message' => $this->prescriptionMessageFor($order),
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error('OrderController@getOrderConfirmation - Failed to fetch order: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Order not found'
            ], 404);
        }
    }

    /**
     * Calculate sale discount for a single product
     */
    private function calculateSaleDiscountForProduct($product, $quantity)
    {
        $activeSales = SaleEvent::active()
                                ->where(function ($query) use ($product) {
                                    $query->where('applicable_to', 'all')
                                          ->orWhere(function ($q) use ($product) {
                                              $q->where('applicable_to', 'specific_products')
                                                ->whereJsonContains('applicable_ids', $product->id);
                                          })
                                          ->orWhere(function ($q) use ($product) {
                                              $q->where('applicable_to', 'specific_categories')
                                                ->whereJsonContains('applicable_ids', $product->product_category);
                                          });
                                })
                                ->orderBy('priority', 'desc')
                                ->get();

        $maxDiscount = 0;
        $appliedSaleEvent = null;
        $itemPrice = $product->sale_price ?? $product->price;
        $itemTotal = $itemPrice * $quantity;

        foreach ($activeSales as $sale) {
            $discount = 0;

            if ($sale->discount_type === 'percentage') {
                $discount = ($itemTotal * $sale->discount_value) / 100;
            } elseif ($sale->discount_type === 'fixed_amount') {
                $discount = min($sale->discount_value, $itemTotal);
            }

            if ($discount > $maxDiscount) {
                $maxDiscount = $discount;
                $appliedSaleEvent = $sale;
            }
        }

        return [
            'discount' => $maxDiscount,
            'sale_event' => $appliedSaleEvent
        ];
    }

    /**
     * Admin: Assign delivery agent or logistics company to order
     */
    /**
     * The shipment an assignment should attach to, updated in place.
     *
     * Checkout already creates a shipment per store through
     * OrderShipmentService, so creating another one here left every assigned
     * order with two: the original sitting at 'pending' with no agent, and a
     * second carrying the assignment. The rider's portal filters on
     * delivery_agent_id and so saw one of them, tracking read the other, and
     * the shipping fee was recorded twice.
     *
     * With no id given it takes the first parcel still in play — which is the
     * only parcel, for the single-pharmacy orders that are most of them. An
     * order split between pharmacies must name the one it means: dispatching
     * "the order" silently assigned a rider to the lowest shipment id and left
     * the other pharmacy's parcel at 'pending' with nobody coming for it, while
     * the order itself read as handled.
     *
     * Only creates a shipment when the order genuinely has none — an order
     * placed before shipments existed, or one whose creation failed.
     */
    private function shipmentForAssignment(Order $order, array $attributes, $shipmentId = null): \App\Models\OrderShipment
    {
        $shipment = \App\Models\OrderShipment::where('order_id', $order->id)
            ->when($shipmentId, fn ($query) => $query->whereKey($shipmentId))
            ->whereNotIn('status', ['delivered', 'returned', 'failed'])
            ->orderBy('id')
            ->first();

        if ($shipmentId && ! $shipment) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException(
                'That parcel is not on this order, or has already completed its journey.'
            );
        }

        if ($shipment) {
            /*
             * The whole run goes to one courier, not just the parcel that was
             * clicked.
             *
             * Parcels collected in the same city are charged as one journey, so
             * they have to be *made* one journey. Letting an operator give them
             * to two different couriers would mean paying two agreed rates out
             * of a fee that only covered one — which is the hole that pricing
             * per run was meant to close, reopened at the assignment screen.
             *
             * `run()` is the parcel alone when it has no group, so a single
             * pharmacy behaves exactly as it did.
             */
            $shipment->run()
                ->whereNotIn('status', \App\Models\OrderShipment::SETTLED_STATUSES)
                ->where('status', '!=', 'delivered')
                ->update($attributes);

            return $shipment->fresh();
        }

        $storeId = $order->store_id;

        if (! $storeId) {
            $firstItem = $order->items()->with('product')->first();
            $storeId = $firstItem && $firstItem->product ? $firstItem->product->store_id : null;
        }

        return \App\Models\OrderShipment::create(array_merge([
            'order_id' => $order->id,
            'store_id' => $storeId ?: null,
            'tracking_number' => \App\Models\OrderShipment::generateTrackingNumber(),
            'shipping_fee' => $order->shipping_amount ?? 0,
            'items' => $order->items->map(fn ($item) => [
                'product_name' => $item->product_snapshot['name'] ?? 'Product',
                'quantity' => $item->quantity,
                'price' => $item->price,
            ])->toArray(),
        ], $attributes));
    }

    /**
     * Where a parcel is collected from: its own pharmacy, else the order's.
     *
     * Shared by assignment and by the courier list so the two cannot disagree
     * about which journey is being arranged.
     *
     * @return array{0: ?string, 1: ?string}  [state, city]
     */
    private function originFor(Order $order, $shipmentId = null): array
    {
        $shipment = $shipmentId
            ? \App\Models\OrderShipment::with('store')->where('order_id', $order->id)->find($shipmentId)
            : null;

        if ($shipment && $shipment->store) {
            return [$shipment->store->state, $shipment->store->city];
        }

        if ($order->store) {
            return [$order->store->state, $order->store->city];
        }

        $firstItem = $order->items()->with('product.store')->first();

        if ($firstItem && $firstItem->product && $firstItem->product->store) {
            return [$firstItem->product->store->state, $firstItem->product->store->city];
        }

        return [null, null];
    }

    /** Whether this journey crosses state lines. */
    private function isInterstateLeg(?string $originState, ?string $destinationState): bool
    {
        // An unknown origin is treated as interstate: it is the cautious
        // reading, and it routes the parcel through a logistics company rather
        // than handing an unknown pickup to an independent rider.
        return ! $originState
            || ! $destinationState
            || strcasecmp(trim($originState), trim($destinationState)) !== 0;
    }

    public function assignDeliveryAgent(Request $request, $id): JsonResponse
    {
        $request->validate([
            'delivery_agent_id' => 'required|integer',
            'type' => 'required|in:agent,company',
            // Optional so that every existing caller — and every
            // single-pharmacy order — keeps working unchanged.
            'shipment_id' => 'nullable|integer',
        ]);

        $order = Order::findOrFail($id);
        $shipmentId = $request->input('shipment_id');

        /*
         * Nothing is dispatched for an order whose prescription has not been
         * cleared — checked here, explicitly, before anything is written.
         *
         * This used to hold by accident: the assignment wrote the order's
         * status to 'assigned_to_agent' in the same update as the rider, and
         * the gate in Order::booted() threw on the status, taking the rider
         * with it. Splitting the status off — so a two-pharmacy order is not
         * marked assigned until both parcels are — removed the accident, and a
         * held order could be given a rider in silence. State the rule instead
         * of leaning on a side effect of another write.
         */
        /*
         * Nothing is dispatched for an order that is over. The parcels of a
         * cancelled order are called off with it, but an order cancelled before
         * that rule existed still has live-looking parcels, and assigning a
         * courier to one sends somebody to collect an order that no longer
         * exists.
         */
        if (in_array($order->status, [Order::STATUS_CANCELLED, Order::STATUS_REFUNDED], true)) {
            return response()->json([
                'success' => false,
                'message' => "This order is {$order->status} and cannot be dispatched.",
                'code' => 'order_closed',
            ], 422);
        }

        if (! $order->isClearedForDispatch()) {
            return response()->json([
                'success' => false,
                'message' => 'This order contains prescription medicine that has not been approved. '
                    ."Prescription status: {$order->prescription_status}.",
                'code' => 'prescription_not_cleared',
            ], 422);
        }

        /*
         * An order collected from more than one place must be dispatched one
         * run at a time. Refusing here rather than quietly picking one is the
         * point: the old behaviour assigned the lowest shipment id and left the
         * second pharmacy's parcel with nobody coming for it, and nothing in
         * the console said so.
         *
         * Counted in runs, not parcels — two pharmacies in the same city travel
         * together, so naming either one dispatches both and there is nothing
         * for an operator to choose between.
         */
        $runCount = $order->shipments()
            ->selectRaw('COUNT(DISTINCT COALESCE(pickup_group, CONCAT(\'id:\', id))) as total')
            ->value('total');

        if (! $shipmentId && $runCount > 1) {
            return response()->json([
                'success' => false,
                'message' => 'This order is collected from more than one place. Assign each pickup separately.',
                'code' => 'shipment_required',
                'data' => ['shipments' => $order->shipments()->with('store:id,name,state,city')->get()],
            ], 422);
        }

        $shippingAddress = $order->shipping_address;
        $state = $shippingAddress['state'] ?? null;
        $city = $shippingAddress['city'] ?? null;

        if ($request->type === 'agent') {
            // Assign independent delivery agent
            $agent = DeliveryAgent::findOrFail($request->delivery_agent_id);

            [$originState, $originCity] = $this->originFor($order, $shipmentId);
            $isInterstate = $this->isInterstateLeg($originState, $state);

            /*
             * An independent rider works within one state.
             *
             * Crossing state lines is a relay — collected at the origin, run to
             * a hub, handed to a second rider for the final mile — and only a
             * logistics company has the people in both places to do it. A lone
             * rider given an interstate parcel either drives the whole way or
             * the parcel stops moving, and nothing here said no: the check was
             * against the delivery address alone, so a rider covering Lagos was
             * accepted for a parcel sitting in a pharmacy in Enugu.
             */
            if ($isInterstate && ! $agent->logistics_company_id) {
                return response()->json([
                    'success' => false,
                    'message' => $originState
                        ? "This parcel travels from {$originState} to {$state}. Interstate deliveries go through a logistics company, not an independent rider."
                        : 'Interstate deliveries go through a logistics company, not an independent rider.',
                    'code' => 'interstate_needs_company',
                ], 422);
            }

            if (! $agent->coversArea($state, $city)) {
                return response()->json([
                    'success' => false,
                    'message' => 'This delivery agent does not service the order delivery location'
                ], 422);
            }

            // They also have to be able to reach the pharmacy. Checking only
            // the destination accepted a rider who covers where the parcel is
            // going but not the city they would have to collect it from.
            if ($originState && ! $agent->coversArea($originState, $originCity)) {
                return response()->json([
                    'success' => false,
                    'message' => $originCity
                        ? "This delivery agent does not cover {$originCity}, where this parcel is collected from."
                        : 'This delivery agent does not cover the pickup location for this parcel.',
                    'code' => 'agent_cannot_collect',
                ], 422);
            }

            $order->update([
                'delivery_agent_id' => $agent->id,
                'logistics_company_id' => $agent->logistics_company_id,
                'assigned_at' => now(),
            ]);

            $shipment = $this->shipmentForAssignment($order, [
                'logistics_company_id' => $agent->logistics_company_id,
                'delivery_agent_id' => $agent->id,
                'status' => 'assigned_to_agent',
                'assigned_at' => now(),
            ], $shipmentId);

            // Update order with tracking number if not set
            if (!$order->tracking_number) {
                $order->update(['tracking_number' => $shipment->tracking_number]);
            }

            // Mark agent as busy
            $agent->update(['status' => 'busy']);

            $message = 'Delivery agent assigned successfully';
        } else {
            // Assign logistics company
            $company = \App\Models\LogisticsCompany::findOrFail($request->delivery_agent_id);

            if (!$company->coversArea($state, $city)) {
                return response()->json([
                    'success' => false,
                    'message' => 'This logistics company does not service the order delivery location'
                ], 422);
            }

            $order->update([
                'logistics_company_id' => $company->id,
                'delivery_agent_id' => null,
                'assigned_at' => now(),
            ]);

            $shipment = $this->shipmentForAssignment($order, [
                'logistics_company_id' => $company->id,
                'delivery_agent_id' => null,
                'status' => 'assigned_to_agent',
                'assigned_at' => now(),
            ], $shipmentId);

            // Update order with tracking number if not set
            if (!$order->tracking_number) {
                $order->update(['tracking_number' => $shipment->tracking_number]);
            }

            $message = 'Logistics company assigned successfully';
        }

        /*
         * The order counts as assigned once every parcel on it is. Moving it on
         * the first assignment made a split order look dispatched while a
         * second pharmacy's parcel still had nobody coming for it — and the
         * "Assign" prompt disappeared from the console along with it.
         */
        if ($order->fresh()->allShipmentsReached('assigned_to_agent')) {
            $order->update(['status' => 'assigned_to_agent']);
        }

        // Create tracking event for assignment
        if ($request->type === 'agent') {
            $order->addTrackingEvent(
                \App\Models\DeliveryTrackingEvent::STATUS_ASSIGNED_TO_AGENT,
                'Order assigned to delivery agent: ' . ($agent->name ?? ''),
                ['agent_id' => $request->delivery_agent_id]
            );
        } else {
            $order->addTrackingEvent(
                \App\Models\DeliveryTrackingEvent::STATUS_ASSIGNED_TO_AGENT,
                'Order assigned to logistics company: ' . ($company->name ?? ''),
                ['company_id' => $request->delivery_agent_id, 'shipment_id' => $shipment->id ?? null]
            );
        }

        // Send email notification to the assigned entity
        try {
            $order->load('items');
            if ($request->type === 'company' && isset($company)) {
                $emailTo = $company->admin_email ?? $company->contact_email;
                if ($emailTo) {
                    Mail::to($emailTo)->send(new DeliveryAssignmentEmail(
                        $order, 'company', $company->name, $shipment->tracking_number ?? $order->tracking_number, $shipment
                    ));
                }
            } elseif ($request->type === 'agent' && isset($agent)) {
                $emailTo = $agent->email;
                if ($emailTo) {
                    // This parcel's tracking number, not the order's — the
                    // order carries whichever shipment happened to set it
                    // first, which on a split order is somebody else's parcel.
                    Mail::to($emailTo)->send(new DeliveryAssignmentEmail(
                        $order, 'agent', $agent->name, $shipment->tracking_number ?? $order->tracking_number, $shipment
                    ));
                }
            }

            // Also notify the customer
            $customerEmail = $order->user?->email ?? ($order->shipping_address['email'] ?? null);
            if ($customerEmail) {
                $assigneeName = $request->type === 'company' ? ($company->name ?? 'Logistics Company') : ($agent->name ?? 'Delivery Agent');
                Mail::to($customerEmail)->send(new DeliveryTrackingUpdateEmail(
                    $order->fresh(['logisticsCompany', 'deliveryAgent']),
                    'Assigned for Delivery',
                    "Your order has been assigned to {$assigneeName} for delivery.",
                    $order->user?->name ?? ($order->shipping_address['firstName'] ?? 'Customer'),
                    'customer'
                ));
            }
        } catch (\Exception $e) {
            \Log::error('Failed to send delivery assignment email: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $order->fresh(['deliveryAgent', 'logisticsCompany', 'shipments'])
        ]);
    }

    /**
     * Admin: Get available delivery agents and logistics companies for an order
     *
     * Interstate: logistics companies only, covering BOTH origin (store
     * state+city) AND destination (shipping state+city). An independent rider
     * cannot run a relay through a hub, so they are not offered.
     *
     * Intrastate: logistics companies covering the destination, *plus*
     * independent riders covering both ends. A company delivers within a state
     * too — through its own in-city dispatch riders — so it is offered for
     * every leg, and only the origin check is dropped: a company that covers
     * the destination reaches the pickup through its own people.
     *
     * The line that used to sit here read "Intrastate: show independent
     * delivery agents", which described half of what the code does and made
     * companies look interstate-only. They are not.
     */
    public function getAvailableAgents(Request $request, $id): JsonResponse
    {
        $order = Order::with('store')->findOrFail($id);

        /*
         * Which parcel this is for. The origin decides whether the journey is
         * interstate and therefore whether the console offers logistics
         * companies or independent riders, and on an order split between two
         * pharmacies the order-level store is only ever one of them — so the
         * second parcel's leg was classified, and its couriers filtered, by the
         * first pharmacy's address.
         */
        $shipment = $request->filled('shipment_id')
            ? \App\Models\OrderShipment::with('store')
                ->where('order_id', $order->id)
                ->find($request->input('shipment_id'))
            : null;

        $shippingAddress = $order->shipping_address;
        $destinationState = $shippingAddress['state'] ?? null;
        $destinationCity = $shippingAddress['city'] ?? null;

        if (!$destinationState) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid shipping address'
            ], 422);
        }

        // Origin: this parcel's pharmacy, else the order's, else the first
        // item's product store.
        [$originState, $originCity] = $this->originFor($order, $shipment?->id);

        $availableOptions = [];

        $isInterstate = $this->isInterstateLeg($originState, $destinationState);

        // Logistics companies for every leg, interstate or not — they run
        // in-city deliveries through their own dispatch riders.
        $logisticsCompanies = \App\Models\LogisticsCompany::where('is_active', true)
            ->get()
            ->filter(function ($company) use ($originState, $originCity, $destinationState, $destinationCity, $isInterstate) {
                $coversDestination = $company->coversArea($destinationState, $destinationCity);
                
                // For interstate, also check origin coverage
                if ($isInterstate && $originState) {
                    $coversOrigin = $company->coversArea($originState, $originCity);
                    return $coversOrigin && $coversDestination;
                }
                
                return $coversDestination;
            });

        foreach ($logisticsCompanies as $company) {
            $availableOptions[] = [
                'id' => $company->id,
                'type' => 'company',
                'name' => $company->name,
                'phone' => $company->contact_phone,
                'logistics_company' => [
                    'name' => $company->name
                ]
            ];
        }

        /*
         * Independent riders, and only for a journey inside one state.
         *
         * They were offered for every leg, filtered by the delivery address
         * alone — so a rider covering Lagos appeared as an option for a parcel
         * sitting in a pharmacy in Enugu, with no way to collect it. Crossing
         * state lines is a relay through a hub, which needs a company with
         * people at both ends; within a state one rider does the whole job.
         *
         * They must also cover the pickup, not just the drop-off. Lagos is one
         * state and a rider who works Ikeja cannot collect from Epe.
         */
        $independentAgents = $isInterstate
            ? collect()
            : DeliveryAgent::available()
                ->whereNull('logistics_company_id')
                ->get()
                ->filter(function ($agent) use ($originState, $originCity, $destinationState, $destinationCity) {
                    if (! $agent->coversArea($destinationState, $destinationCity)) {
                        return false;
                    }

                    return ! $originState || $agent->coversArea($originState, $originCity);
                });

        foreach ($independentAgents as $agent) {
            $availableOptions[] = [
                'id' => $agent->id,
                'type' => 'agent',
                'name' => $agent->name,
                'phone' => $agent->phone,
                'logistics_company' => [
                    'name' => 'Independent Agent'
                ],
                'rating' => $agent->rating
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $availableOptions,
            'meta' => [
                'delivery_type' => $isInterstate ? 'interstate' : 'intrastate',
                'origin_state' => $originState,
                'origin_city' => $originCity,
                'destination_state' => $destinationState,
                'destination_city' => $destinationCity
            ]
        ]);
    }

    /**
     * Admin: Update delivery status
     */
    public function updateDeliveryStatus(Request $request, $id): JsonResponse
    {
        $request->validate([
            'status' => 'required|in:picked_up,arrived_at_hub,in_transit,out_for_delivery,delivered',
            'notes' => 'nullable|string'
        ]);

        $order = Order::findOrFail($id);
        $oldStatus = $order->status;

        switch ($request->status) {
            case 'picked_up':
                $order->markAsPickedUp();
                $message = 'Order marked as picked up';
                break;
            case 'arrived_at_hub':
                $order->markAsArrivedAtHub();
                $message = 'Order marked as arrived at hub';
                break;
            case 'in_transit':
                $order->update(['status' => 'in_transit', 'shipped_at' => $order->shipped_at ?? now()]);
                $message = 'Order marked as in transit';
                break;
            case 'out_for_delivery':
                $order->markAsOutForDelivery();
                $message = 'Order marked as out for delivery';
                break;
            case 'delivered':
                $order->markAsDelivered($request->notes);
                $message = 'Order marked as delivered';

                // markAsDelivered() already credits the courier through
                // DeliveryEarningsService, which is also what the rider and
                // logistics portals call. Crediting again here is what paid some
                // deliveries twice.
                break;
        }

        // Send notification emails via OrderNotificationService
        try {
            $notificationService = new \App\Services\OrderNotificationService();
            $notificationService->notifyStatusUpdate($order->fresh(), $oldStatus, $request->status);
        } catch (\Exception $e) {
            \Log::error("Failed to send status notification: " . $e->getMessage());
        }

        // Send tracking update emails to all parties
        try {
            $freshOrder = $order->fresh(['logisticsCompany', 'deliveryAgent', 'user']);
            $statusLabels = [
                'picked_up' => 'Picked Up',
                'arrived_at_hub' => 'Arrived at Hub',
                'out_for_delivery' => 'Out for Delivery',
                'delivered' => 'Delivered',
            ];
            $statusDescriptions = [
                'picked_up' => 'The package has been picked up and is being prepared for transit.',
                'arrived_at_hub' => 'The package has arrived at the logistics hub and is being prepared for the next stage of delivery.',
                'out_for_delivery' => 'The package is out for delivery and should arrive soon.',
                'delivered' => 'The package has been successfully delivered.',
            ];
            $label = $statusLabels[$request->status] ?? $request->status;
            $desc = $statusDescriptions[$request->status] ?? 'Delivery status updated.';

            // Notify customer
            $customerEmail = $freshOrder->user?->email ?? ($freshOrder->shipping_address['email'] ?? null);
            if ($customerEmail) {
                Mail::to($customerEmail)->send(new DeliveryTrackingUpdateEmail(
                    $freshOrder, $label, $desc,
                    $freshOrder->user?->name ?? ($freshOrder->shipping_address['firstName'] ?? 'Customer'),
                    'customer'
                ));
            }

            // Notify logistics company
            if ($freshOrder->logisticsCompany) {
                $companyEmail = $freshOrder->logisticsCompany->admin_email ?? $freshOrder->logisticsCompany->contact_email;
                if ($companyEmail) {
                    Mail::to($companyEmail)->send(new DeliveryTrackingUpdateEmail(
                        $freshOrder, $label, $desc,
                        $freshOrder->logisticsCompany->name, 'company'
                    ));
                }
            }

            // Notify delivery agent
            if ($freshOrder->deliveryAgent && $freshOrder->deliveryAgent->email) {
                Mail::to($freshOrder->deliveryAgent->email)->send(new DeliveryTrackingUpdateEmail(
                    $freshOrder, $label, $desc,
                    $freshOrder->deliveryAgent->name, 'agent'
                ));
            }
        } catch (\Exception $e) {
            \Log::error('Failed to send delivery tracking update email: ' . $e->getMessage());
        }

        // Update shipment status too
        try {
            $shipment = \App\Models\OrderShipment::where('order_id', $order->id)
                ->where(function($q) use ($order) {
                    $q->where('logistics_company_id', $order->logistics_company_id)
                      ->orWhere('delivery_agent_id', $order->delivery_agent_id);
                })->first();
            if ($shipment) {
                $shipmentStatusMap = [
                    'picked_up' => 'picked_up',
                    'out_for_delivery' => 'out_for_delivery',
                    'delivered' => 'delivered',
                ];
                $shipment->updateStatus($shipmentStatusMap[$request->status] ?? $request->status);
            }
        } catch (\Exception $e) {
            \Log::error('Failed to update shipment status: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $order->fresh()
        ]);
    }

    /**
     * Estimate shipping fee for checkout/buy now
     */
    public function estimateShipping(Request $request): JsonResponse
    {
        $request->validate([
            'state' => 'required|string',
            'city' => 'nullable|string',
            'postal_code' => 'nullable|string',
            'subtotal' => 'nullable|numeric'
        ]);

        // This ran its own destination-only zone lookup while checkout ran a
        // route-based one from the selling store's state, so the fee quoted on
        // the checkout page could differ from the fee actually charged. Both now
        // go through one calculation.
        $address = [
            'state' => $request->state,
            'city' => $request->city,
            'postalCode' => $request->postal_code,
        ];

        $userId = Auth::id();
        $guestId = $request->header('X-Guest-ID');

        $cartItems = Cart::with('product.store')
            ->when(
                $userId,
                fn ($query) => $query->where('user_id', $userId),
                fn ($query) => $query->where('session_id', $guestId)
            )
            ->get();

        $fee = $this->calculateShipping((float) ($request->subtotal ?? 0), $address, $cartItems);
        $isCovered = ! $this->_lastShippingUncovered;

        $zone = $this->_lastShippingZoneId
            ? ShippingZone::find($this->_lastShippingZoneId)
            : null;

        return response()->json([
            'success' => true,
            'data' => [
                'shipping_fee' => (float) $fee,
                'is_covered' => $isCovered,
                'zone_name' => $zone?->name,
                // What the fee is made of, so the number on the checkout page
                // can be explained rather than merely displayed.
                'breakdown' => $this->_lastShippingBreakdown,
                'destination_state' => $request->state,
                'estimated_delivery_days' => $zone?->estimated_delivery_days,
                // A zero fee on an address we cannot reach is not free delivery.
                'is_free_shipping' => $isCovered && (float) $fee === 0.0,
                'message' => $isCovered
                    ? null
                    : "We don't deliver to {$request->state} yet. Choose store pickup or a different address.",
            ]
        ]);
    }

    /**
     * Admin: Calculate shipping fee for order
     */
    public function calculateShippingFee(Request $request): JsonResponse
    {
        $request->validate([
            'state' => 'required|string',
            'city' => 'nullable|string',
            'postal_code' => 'nullable|string'
        ]);

        $zone = ShippingZone::findByLocation(
            $request->state,
            $request->city,
            $request->postal_code
        );

        if (!$zone) {
            return response()->json([
                'success' => false,
                'message' => 'No shipping zone configured for this location'
            ], 404);
        }

        $fee = $zone->calculateShippingFee();

        return response()->json([
            'success' => true,
            'data' => [
                'zone' => $zone,
                'shipping_fee' => $fee,
                'estimated_delivery_days' => $zone->estimated_delivery_days
            ]
        ]);
    }

    /**
     * Public tracking - lookup order by tracking number
     * No authentication required
     */
    public function trackByNumber(Request $request): JsonResponse
    {
        $request->validate([
            'tracking_number' => 'required|string'
        ]);

        $order = Order::where('tracking_number', $request->tracking_number)
            ->with(['deliveryAgent', 'logisticsCompany', 'shipments.logisticsCompany'])
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'No order found with this tracking number'
            ], 404);
        }

        // Get logistics company from shipment if not on order
        $logisticsCompany = $order->logisticsCompany;
        if (!$logisticsCompany && $order->shipments->isNotEmpty()) {
            $logisticsCompany = $order->shipments->first()->logisticsCompany;
        }

        // Get ALL tracking events for this order, removing duplicates by status+timestamp
        $trackingEvents = \App\Models\DeliveryTrackingEvent::where('order_id', $order->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->unique(function($event) {
                // Group by status and created_at rounded to minute to remove near-duplicates
                return $event->status . '_' . $event->created_at->format('Y-m-d H:i');
            })
            ->values()
            ->map(function($event) {
                return [
                    'status' => $event->status,
                    'description' => $event->description,
                    'notes' => $event->description,
                    'location' => $event->location,
                    'created_at' => $event->created_at,
                    'metadata' => $event->metadata
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'order_number' => $order->order_number,
                'tracking_number' => $order->tracking_number,
                'status' => $order->status,
                'payment_status' => $order->payment_status,
                'created_at' => $order->created_at,
                'confirmed_at' => $order->confirmed_at,
                'picked_up_at' => $order->picked_up_at,
                'out_for_delivery_at' => $order->out_for_delivery_at,
                'delivered_at' => $order->delivered_at,
                'arrived_at_hub_at' => $order->arrived_at_hub_at ?? null,
                'in_transit_at' => $order->in_transit_at ?? null,
                'delivery_agent' => $order->deliveryAgent ? [
                    'name' => $order->deliveryAgent->name,
                    'phone' => $order->deliveryAgent->phone,
                ] : null,
                'logistics_company' => $logisticsCompany ? [
                    'name' => $logisticsCompany->company_name ?? $logisticsCompany->name,
                    'phone' => $logisticsCompany->phone,
                ] : null,
                'shipping_address' => [
                    'city' => $order->shipping_address['city'] ?? null,
                    'state' => $order->shipping_address['state'] ?? null,
                ],
                'tracking_updates' => $trackingEvents,
            ]
        ]);
    }
}
