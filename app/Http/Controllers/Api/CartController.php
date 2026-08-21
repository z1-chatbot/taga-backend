<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\CartSession;
use App\Models\Prescription;
use App\Models\SaleEvent;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class CartController extends Controller
{
    use \App\Http\Controllers\Concerns\ResolvesOwnPrescription;

    /**
     * Attach (or replace) the prescription on a basket line already added.
     *
     * The storefront flow is add-to-basket, then upload the script, so the
     * shopper needs a way to connect the two without re-adding the item.
     */
    public function attachPrescription(Request $request, $id): JsonResponse
    {
        $request->validate([
            'prescription_id' => 'required|integer',
        ]);

        $userId = Auth::id();
        $guestId = $request->header('X-Guest-ID');

        $cartItem = Cart::where('id', $id)
            ->when($userId,
                fn ($q) => $q->where('user_id', $userId),
                fn ($q) => $q->where('session_id', $guestId)
            )
            ->first();

        if (! $cartItem) {
            return response()->json([
                'success' => false,
                'message' => 'Cart item not found',
            ], 404);
        }

        $prescription = $this->resolveOwnPrescription($request, $request->prescription_id);

        if (! $prescription) {
            return response()->json([
                'success' => false,
                'message' => 'Prescription not found',
            ], 404);
        }

        if ($prescription->status === Prescription::STATUS_REJECTED) {
            return response()->json([
                'success' => false,
                'message' => 'That prescription was rejected and cannot be used.',
                'code' => 'prescription_rejected',
            ], 422);
        }

        $cartItem->update(['prescription_id' => $prescription->id]);

        return response()->json([
            'success' => true,
            'message' => 'Prescription attached to this item.',
            'data' => $this->buildCartResponse($userId, $guestId),
        ]);
    }

    /**
     * Get cart items
     */
    public function index(Request $request): JsonResponse
    {
        $userId = Auth::id();
        $guestId = $request->header('X-Guest-ID');

        if (!$userId && !$guestId) {
            // Return empty cart for guests without ID
            return response()->json([
                'success' => true,
                'data' => [
                    'items' => [],
                    'subtotal' => 0,
                    'total_items' => 0,
                    'total' => 0
                ]
            ]);
        }

        $cartData = $this->buildCartResponse($userId, $guestId);

        return response()->json([
            'success' => true,
            'data' => $cartData
        ]);
    }

    /**
     * Add item to cart
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1|max:10',
            'variation_id' => 'nullable|exists:product_variations,id',
            // Prescription-only lines carry the script they are being bought
            // against. Without this the field never reached the cart row and
            // every basket containing an Rx medicine failed at checkout.
            'prescription_id' => 'nullable|integer',
        ]);

        $product = Product::active()->find($request->product_id);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found or unavailable'
            ], 404);
        }

        $prescriptionId = null;

        if ($request->filled('prescription_id')) {
            $prescription = $this->resolveOwnPrescription($request, $request->prescription_id);

            if (! $prescription) {
                // Same 404 as a prescription that does not exist, so cart adds
                // cannot be used to probe for other people's record ids.
                return response()->json([
                    'success' => false,
                    'message' => 'Prescription not found',
                ], 404);
            }

            if ($prescription->status === Prescription::STATUS_REJECTED) {
                return response()->json([
                    'success' => false,
                    'message' => 'That prescription was rejected and cannot be used.',
                    'code' => 'prescription_rejected',
                ], 422);
            }

            $prescriptionId = $prescription->id;
        }

        /*
         * A prescription-only medicine can go into the basket without a script
         * attached yet. Requiring one here would mean uploading before you can
         * even add the item, which is a strange order to shop in and is not
         * what the product page promises.
         *
         * Checkout is where it becomes mandatory — guardPharmacyRules refuses
         * an Rx line with no prescription — and the basket flags the line so
         * the shopper knows before they get there.
         */

        // Handle variations
        $variation = null;
        $price = $product->current_price;
        $stockQuantity = $product->stock_quantity;

        // If variation_id is provided, validate and use variation pricing
        if ($request->variation_id) {
            $variation = ProductVariation::where('id', $request->variation_id)
                ->where('product_id', $product->id)
                ->active()
                ->first();

            if (!$variation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Selected variation not found or unavailable'
                ], 404);
            }

            $price = $variation->current_price;
            $stockQuantity = $variation->stock_quantity;
        }

        if ($stockQuantity < $request->quantity) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient stock available'
            ], 400);
        }

        $userId = Auth::id();
        $guestId = $request->header('X-Guest-ID');

        // Check if item already exists in cart (same product and variation)
        $cartItem = Cart::where('product_id', $request->product_id)
                       ->where('variation_id', $request->variation_id)
                       ->when($userId, function ($query) use ($userId) {
                           return $query->where('user_id', $userId);
                       }, function ($query) use ($guestId) {
                           return $query->where('session_id', $guestId);
                       })
                       ->first();

        if ($cartItem) {
            // Update quantity
            $newQuantity = $cartItem->quantity + $request->quantity;

            if ($stockQuantity < $newQuantity) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot add more items. Stock limit exceeded.'
                ], 400);
            }

            $cartItem->update([
                'quantity' => $newQuantity,
                'price' => $price,
                // A newly supplied script replaces the one already on the line;
                // omitting it leaves the existing one alone.
                'prescription_id' => $prescriptionId ?: $cartItem->prescription_id,
            ]);
        } else {
            // Create new cart item
            Cart::create([
                'user_id' => $userId,
                'session_id' => $userId ? null : $guestId,
                'product_id' => $request->product_id,
                'variation_id' => $request->variation_id,
                'quantity' => $request->quantity,
                'price' => $price,
                'prescription_id' => $prescriptionId,
            ]);
        }

        // Return updated cart data
        $cartData = $this->buildCartResponse($userId, $guestId);

        return response()->json([
            'success' => true,
            'message' => 'Item added to cart successfully',
            'data' => $cartData
        ]);
    }

    /**
     * Update cart item quantity
     */
    public function update(Request $request, $id): JsonResponse
    {
        $request->validate([
            'quantity' => 'required|integer|min:1|max:10'
        ]);

        $userId = Auth::id();
        $guestId = $request->header('X-Guest-ID');

        $cartItem = Cart::with('product')
                       ->where('id', $id)
                       ->when($userId, function ($query) use ($userId) {
                           return $query->where('user_id', $userId);
                       }, function ($query) use ($guestId) {
                           return $query->where('session_id', $guestId);
                       })
                       ->first();

        if (!$cartItem) {
            return response()->json([
                'success' => false,
                'message' => 'Cart item not found'
            ], 404);
        }

        if ($cartItem->product->stock_quantity < $request->quantity) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient stock available'
            ], 400);
        }

        $cartItem->update([
            'quantity' => $request->quantity,
            'price' => $cartItem->product->current_price
        ]);

        // Return updated cart data
        $cartData = $this->buildCartResponse($userId, $guestId);

        return response()->json([
            'success' => true,
            'message' => 'Cart item updated successfully',
            'data' => $cartData
        ]);
    }

    /**
     * Remove item from cart
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $userId = Auth::id();
        $guestId = $request->header('X-Guest-ID');

        $cartItem = Cart::where('id', $id)
                       ->when($userId, function ($query) use ($userId) {
                           return $query->where('user_id', $userId);
                       }, function ($query) use ($guestId) {
                           return $query->where('session_id', $guestId);
                       })
                       ->first();

        if (!$cartItem) {
            return response()->json([
                'success' => false,
                'message' => 'Cart item not found'
            ], 404);
        }

        $cartItem->delete();

        // Check if cart is now empty and clear cart session if so
        $remainingItems = Cart::when($userId, function ($query) use ($userId) {
                return $query->where('user_id', $userId);
            }, function ($query) use ($guestId) {
                return $query->where('session_id', $guestId);
            })->count();

        if ($remainingItems === 0) {
            // Cart is empty, clear cart session (coupon data)
            CartSession::where($userId ? 'user_id' : 'session_id', $userId ?: $guestId)->delete();
        }

        // Return updated cart data
        $cartData = $this->buildCartResponse($userId, $guestId);

        return response()->json([
            'success' => true,
            'message' => 'Item removed from cart successfully',
            'data' => $cartData
        ]);
    }

    /**
     * Clear entire cart
     */
    public function clear(Request $request): JsonResponse
    {
        $userId = Auth::id();
        $guestId = $request->header('X-Guest-ID');

        Cart::when($userId, function ($query) use ($userId) {
                return $query->where('user_id', $userId);
            }, function ($query) use ($guestId) {
                return $query->where('session_id', $guestId);
            })
            ->delete();

        // Clear cart session as well
        CartSession::where($userId ? 'user_id' : 'session_id', $userId ?: $guestId)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Cart cleared successfully',
            'data' => [
                'items' => [],
                'subtotal' => 0,
                'total_items' => 0,
                'total' => 0
            ]
        ]);
    }

    /**
     * Merge guest cart with user cart on login
     */
    public function mergeGuestCart(Request $request): JsonResponse
    {
        $request->validate([
            'guest_id' => 'required|string'
        ]);

        $userId = Auth::id();
        $guestId = $request->guest_id;

        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'User must be authenticated'
            ], 401);
        }

        // Get guest cart items
        $guestCartItems = Cart::with(['product', 'product.category'])
                             ->where('session_id', $guestId)
                             ->whereNull('user_id')
                             ->get();

        if ($guestCartItems->isEmpty()) {
            // No guest cart to merge, just return user's existing cart
            return $this->index($request);
        }

        // Get existing user cart items
        $userCartItems = Cart::where('user_id', $userId)->get();

        foreach ($guestCartItems as $guestItem) {
            // Check if user already has this product in cart
            $existingUserItem = $userCartItems->where('product_id', $guestItem->product_id)->first();

            if ($existingUserItem) {
                // Merge quantities (but respect stock limits)
                $newQuantity = $existingUserItem->quantity + $guestItem->quantity;
                $maxQuantity = min($newQuantity, $guestItem->product->stock_quantity, 10);
                
                $existingUserItem->update([
                    'quantity' => $maxQuantity,
                    'price' => $guestItem->product->current_price // Update to current price
                ]);
            } else {
                // Move guest item to user cart
                $guestItem->update([
                    'user_id' => $userId,
                    'session_id' => null,
                    'price' => $guestItem->product->current_price // Update to current price
                ]);
            }
        }

        // Delete any remaining guest cart items (duplicates that were merged)
        Cart::where('session_id', $guestId)
            ->whereNull('user_id')
            ->delete();

        // Return updated user cart
        return $this->index($request);
    }

    /**
     * Build cart response with session data (coupons, discounts)
     */
    private function buildCartResponse($userId = null, $guestId = null)
    {
        // Get cart items
        $cartItems = Cart::with(['product', 'variation'])
                        ->when($userId, function ($query) use ($userId) {
                            return $query->where('user_id', $userId);
                        }, function ($query) use ($guestId) {
                            return $query->where('session_id', $guestId);
                        })
                        ->get();

        // Remove cart items where product no longer exists
        $cartItems = $cartItems->filter(function ($item) {
            if (!$item->product) {
                // Delete orphaned cart items
                $item->delete();
                return false;
            }
            return true;
        });

        // Update cart item prices to reflect current product/variation prices (in case sales have changed)
        $cartItems->each(function ($item) {
            if ($item->variation) {
                // Use variation price if variation exists
                $currentPrice = $item->variation->current_price;
                if ($item->price != $currentPrice) {
                    $item->update(['price' => $currentPrice]);
                    $item->price = $currentPrice; // Update the loaded model as well
                }
            } elseif ($item->product) {
                // Use product price if no variation
                $currentPrice = $item->product->current_price;
                if ($item->price != $currentPrice) {
                    $item->update(['price' => $currentPrice]);
                    $item->price = $currentPrice; // Update the loaded model as well
                }
            }
        });

        $subtotal = $cartItems->sum('subtotal');
        $totalItems = $cartItems->sum('quantity');

        // Get cart session (coupon data)
        $cartSession = CartSession::getSession($userId, $guestId);
        
        // Calculate sale discounts from active events
        $saleDiscountResult = $this->calculateSaleDiscount($cartItems);
        $saleDiscount = $saleDiscountResult['discount'];
        $appliedSaleEvent = $saleDiscountResult['sale_event'];
        
        // Validate existing coupon in cart session
        $couponDiscount = 0;
        if ($cartSession && $cartSession->coupon_code) {
            // Re-validate the coupon to ensure it's still valid
            $coupon = \App\Models\Coupon::byCode($cartSession->coupon_code)->first();
            if ($coupon) {
                $validation = $coupon->isValid($userId, $subtotal, $cartItems->toArray());
                if ($validation['valid']) {
                    $couponDiscount = $coupon->calculateDiscount($subtotal, $cartItems->toArray());
                    // Update the discount amount in case it changed
                    $cartSession->update(['discount_amount' => $couponDiscount]);
                } else {
                    // Coupon is no longer valid, remove it from cart session
                    $cartSession->delete();
                    $cartSession = null;
                }
            } else {
                // Coupon doesn't exist anymore, remove cart session
                $cartSession->delete();
                $cartSession = null;
            }
        }
        
        // Note: saleDiscount is for display purposes only - it's already included in the subtotal
        // Only subtract coupon discount from the total
        $total = $subtotal - $couponDiscount;
        
        // Update cart session totals if it exists
        if ($cartSession && ($cartSession->subtotal != $subtotal || $cartSession->total != $total)) {
            $cartSession->update([
                'subtotal' => $subtotal,
                'total' => $total
            ]);
        }

        // Check for payment method restrictions in cart
        $hasPaymentBeforeDeliveryOnly = $cartItems->contains(function ($item) {
            return $item->product && $item->product->payment_method_restriction === 'payment_before_delivery';
        });
        
        $hasCashOnDeliveryOnly = $cartItems->contains(function ($item) {
            return $item->product && $item->product->payment_method_restriction === 'cash_on_delivery';
        });
        
        // Determine allowed payment methods
        $allowedPaymentMethods = ['paystack', 'cash_on_delivery']; // Default: both allowed
        $paymentRestrictionMessage = null;
        
        if ($hasPaymentBeforeDeliveryOnly && $hasCashOnDeliveryOnly) {
            // Conflicting restrictions - should not happen, but handle gracefully
            $allowedPaymentMethods = ['paystack']; // Default to payment before delivery
            $paymentRestrictionMessage = 'Your cart contains items with conflicting payment requirements. Please contact support.';
        } elseif ($hasPaymentBeforeDeliveryOnly) {
            $allowedPaymentMethods = ['paystack'];
            $paymentRestrictionMessage = 'Some items in your cart require payment before delivery. Cash on delivery is not available for this order.';
        } elseif ($hasCashOnDeliveryOnly) {
            $allowedPaymentMethods = ['cash_on_delivery'];
            $paymentRestrictionMessage = 'Some items in your cart are only available for cash on delivery.';
        }

        /*
         * The Settings switch overrides every per-product rule above. Without
         * this the basket kept offering cash on delivery while the switch was
         * off, and the shopper only found out when checkout refused the order
         * at the last step. A cart of cash-only stock is left with nothing it
         * can pay with, which is the honest answer — checkout would refuse it.
         */
        if (! \App\Models\SystemSetting::codEnabled()) {
            $allowedPaymentMethods = array_values(
                array_diff($allowedPaymentMethods, ['cash_on_delivery'])
            );

            $paymentRestrictionMessage = empty($allowedPaymentMethods)
                ? 'Cash on delivery is switched off, and every item in your cart is cash on delivery only. Please remove them to continue.'
                : 'Cash on delivery is not available at the moment. Please pay online to place your order.';
        }

        // Transform cart items to include pricing details
        $transformedItems = $cartItems->map(function ($item) {
            $itemData = $item->toArray();
            
            // If variation exists, use variation pricing
            if ($item->variation) {
                $itemData['variation']['current_price'] = $item->variation->current_price;
                $itemData['variation']['original_price'] = $item->variation->original_price;
                $itemData['variation']['platform_markup'] = $item->variation->platform_markup;
                $itemData['variation']['is_on_sale'] = $item->variation->is_on_sale;
            }
            
            // Always include product pricing for reference
            if ($item->product) {
                $itemData['product']['current_price'] = $item->product->current_price;
                $itemData['product']['original_price'] = $item->product->original_price;
                $itemData['product']['platform_markup'] = $item->product->platform_markup;
            }

            /*
             * Prescription state per line, so the basket can prompt for a
             * missing script rather than letting the shopper reach checkout and
             * be refused there.
             */
            $requiresRx = (bool) ($item->product?->requires_prescription);
            $itemData['requires_prescription'] = $requiresRx;
            $itemData['prescription_status'] = null;
            $itemData['needs_prescription_upload'] = $requiresRx && ! $item->prescription_id;

            if ($requiresRx && $item->prescription_id) {
                $prescription = \App\Models\Prescription::find($item->prescription_id);
                $itemData['prescription_status'] = $prescription?->status;

                // A rejected script is as good as none — the shopper has to
                // replace it before this line can be bought.
                if ($prescription?->status === \App\Models\Prescription::STATUS_REJECTED) {
                    $itemData['needs_prescription_upload'] = true;
                }
            }

            return $itemData;
        });

        $rxLinesNeedingUpload = $transformedItems->where('needs_prescription_upload', true)->count();

        $response = [
            'items' => $transformedItems,
            'subtotal' => $subtotal,
            'total_items' => $totalItems,
            'total' => $total,
            'sale_discount' => $saleDiscount,
            'allowed_payment_methods' => $allowedPaymentMethods,
            'payment_restriction_message' => $paymentRestrictionMessage,
            // Lets checkout disable its submit button with a reason rather than
            // sending an order it knows will be refused.
            'requires_prescription' => $transformedItems->contains('requires_prescription', true),
            'prescriptions_outstanding' => $rxLinesNeedingUpload,
        ];

        // Include coupon data if applied
        if ($cartSession && $cartSession->coupon_code) {
            $response['applied_coupon'] = [
                'code' => $cartSession->coupon_code,
                'discount_amount' => $cartSession->discount_amount
            ];
        }

        // Include sale discount details if any
        if ($saleDiscount > 0 && $appliedSaleEvent) {
            $response['applied_sale_event'] = [
                'id' => $appliedSaleEvent->id,
                'name' => $appliedSaleEvent->name,
                'discount_amount' => $saleDiscount
            ];
            
            $activeSales = SaleEvent::active()->byPriority()->get();
            $response['active_sales'] = $activeSales->map(function ($sale) {
                return [
                    'id' => $sale->id,
                    'name' => $sale->name,
                    'discount_type' => $sale->discount_type,
                    'discount_value' => $sale->discount_value,
                    'description' => $sale->description
                ];
            });
        }

        return $response;
    }

    /**
     * Calculate sale discount from active events
     */
    private function calculateSaleDiscount($cartItems)
    {
        $totalDiscount = 0;
        $appliedSale = null;
        $activeSales = SaleEvent::active()->byPriority()->get();

        foreach ($cartItems as $item) {
            $product = $item->product;
            
            // Skip if product no longer exists
            if (!$product) {
                continue;
            }
            
            $originalPrice = $product->price;
            $currentPrice = $item->price;
            
            // Only calculate discount if there's an active sale for this product
            $hasActiveSale = false;
            foreach ($activeSales as $sale) {
                if ($sale->appliesToProduct($product)) {
                    $hasActiveSale = true;
                    if (!$appliedSale) {
                        $appliedSale = $sale;
                    }
                    break;
                }
            }
            
            // Only add to discount if there's an active sale AND the current price is less than original
            if ($hasActiveSale && $currentPrice < $originalPrice) {
                $itemDiscount = ($originalPrice - $currentPrice) * $item->quantity;
                $totalDiscount += $itemDiscount;
            }
        }

        return [
            'discount' => $totalDiscount,
            'sale_event' => $appliedSale
        ];
    }

    /**
     * Refresh cart prices to reflect current product prices
     */
    public function refreshPrices(Request $request): JsonResponse
    {
        $userId = Auth::id();
        $guestId = $request->header('X-Guest-ID');

        $cartItems = Cart::with(['product'])
                        ->when($userId, function ($query) use ($userId) {
                            return $query->where('user_id', $userId);
                        }, function ($query) use ($guestId) {
                            return $query->where('session_id', $guestId);
                        })
                        ->get();

        $updatedCount = 0;
        $cartItems->each(function ($item) use (&$updatedCount) {
            if ($item->product) {
                $currentPrice = $item->product->current_price;
                if ($item->price != $currentPrice) {
                    $item->update(['price' => $currentPrice]);
                    $updatedCount++;
                }
            }
        });

        $cartData = $this->buildCartResponse($userId, $guestId);

        return response()->json([
            'success' => true,
            'message' => $updatedCount > 0 ? "Updated prices for {$updatedCount} items" : 'All prices are up to date',
            'data' => $cartData,
            'updated_items' => $updatedCount
        ]);
    }
}
