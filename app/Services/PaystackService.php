<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Jobs\SendOrderStatusEmail;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaystackService
{
    private $secretKey;
    private $publicKey;
    private $baseUrl;

    public function __construct()
    {
        $this->secretKey = config('services.paystack.secret_key');
        $this->publicKey = config('services.paystack.public_key');
        $this->baseUrl = 'https://api.paystack.co';
    }

    /**
     * Initialize payment transaction
     */
    public function initializePayment(Order $order, array $customerData = [])
    {
        try {
            $reference = PaymentTransaction::generateReference();
            
            $payload = [
                'email' => $customerData['email'] ?? ($order->user ? $order->user->email : $order->shipping_address['email']),
                'amount' => $order->total_amount * 100, // Convert to kobo
                'reference' => $reference,
                'currency' => $order->currency ?? 'NGN',
                'callback_url' => config('app.frontend_url') . '/payment/callback?reference=' . $reference . '&order_id=' . $order->id,
                'metadata' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'customer_name' => $customerData['name'] ?? ($order->user ? $order->user->name : ($order->shipping_address['firstName'] . ' ' . $order->shipping_address['lastName'])),
                    'custom_fields' => [
                        [
                            'display_name' => 'Order Number',
                            'variable_name' => 'order_number',
                            'value' => $order->order_number
                        ]
                    ]
                ]
            ];

            // Add customer if provided
            if (!empty($customerData)) {
                $payload['customer'] = [
                    'email' => $customerData['email'],
                    'first_name' => $customerData['first_name'] ?? '',
                    'last_name' => $customerData['last_name'] ?? '',
                    'phone' => $customerData['phone'] ?? ''
                ];
            }

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->secretKey,
                'Content-Type' => 'application/json'
            ])->post($this->baseUrl . '/transaction/initialize', $payload);

            if ($response->successful()) {
                $data = $response->json();
                
                if ($data['status']) {
                    // Create payment transaction record
                    $transaction = PaymentTransaction::create([
                        'order_id' => $order->id,
                        'user_id' => $order->user_id,
                        'reference' => $reference,
                        'amount' => $order->total_amount,
                        'currency' => $order->currency ?? 'NGN',
                        'status' => PaymentTransaction::STATUS_PENDING,
                        'payment_method' => 'card',
                        'gateway' => PaymentTransaction::GATEWAY_PAYSTACK,
                        'metadata' => $payload['metadata']
                    ]);

                    return [
                        'success' => true,
                        'data' => [
                            'authorization_url' => $data['data']['authorization_url'],
                            'access_code' => $data['data']['access_code'],
                            'reference' => $reference,
                            'transaction_id' => $transaction->id
                        ]
                    ];
                }
            }

            $responseData = $response->json();
            Log::error('Paystack initialization failed', [
                'response' => $responseData,
                'status_code' => $response->status(),
                'order_id' => $order->id
            ]);

            $errorMessage = 'Failed to initialize payment';
            if (isset($responseData['message'])) {
                $errorMessage = $responseData['message'];
            }

            return [
                'success' => false,
                'message' => $errorMessage
            ];

        } catch (\Exception $e) {
            Log::error('Paystack initialization error', [
                'error' => $e->getMessage(),
                'order_id' => $order->id
            ]);

            return [
                'success' => false,
                'message' => 'Payment initialization error'
            ];
        }
    }

    /**
     * Verify payment transaction
     */
    public function verifyPayment($reference)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->secretKey
            ])->get($this->baseUrl . "/transaction/verify/{$reference}");

            if ($response->successful()) {
                $data = $response->json();
                
                if ($data['status'] && $data['data']['status'] === 'success') {
                    $paymentData = $data['data'];
                    
                    // Find the transaction
                    $transaction = PaymentTransaction::where('reference', $reference)->first();
                    
                    if ($transaction) {
                        // Update transaction
                        $transaction->update([
                            'transaction_id' => $paymentData['id'],
                            'status' => PaymentTransaction::STATUS_SUCCESS,
                            'gateway_response' => $paymentData,
                            'authorization_code' => $paymentData['authorization']['authorization_code'] ?? null,
                            'customer_code' => $paymentData['customer']['customer_code'] ?? null,
                            'fees' => ($paymentData['fees'] ?? 0) / 100, // Convert from kobo
                            'paid_at' => now()
                        ]);

                        // Update order - set payment as paid and status to processing
                        $order = $transaction->order;
                        $order->update([
                            'payment_status' => Order::PAYMENT_PAID,
                            'payment_id' => $paymentData['id'],
                            'status' => Order::STATUS_PROCESSING
                        ]);

                        // Create initial tracking event for order confirmation
                        $order->createTrackingEventForStatus('processing');

                        // Reduce stock quantities
                        $this->reduceStock($order);

                        // Clear cart after successful payment
                        $this->clearCart($order);

                        // Send order placed/confirmed notifications to all parties
                        try {
                            $notificationService = new \App\Services\OrderNotificationService();
                            $notificationService->notifyOrderPlaced($order->fresh());
                            Log::info("Order placed notifications sent after payment: {$order->order_number}");
                        } catch (\Exception $e) {
                            Log::error("Failed to send order placed notifications: " . $e->getMessage());
                        }

                        // Send order confirmed email (for both authenticated and guest users)
                        $emailRecipient = null;
                        if ($order->user) {
                            $emailRecipient = $order->user->email;
                        } elseif (isset($order->shipping_address['email'])) {
                            $emailRecipient = $order->shipping_address['email'];
                        }

                        if ($emailRecipient) {
                            // Send order confirmation immediately (contains delivery code)
                            try {
                                \Illuminate\Support\Facades\Mail::to($emailRecipient)->send(
                                    new \App\Mail\OrderStatusEmail($order, 'confirmed')
                                );
                                Log::info("Order confirmed email sent immediately: {$order->order_number}", [
                                    'recipient' => $emailRecipient,
                                    'is_guest' => !$order->user
                                ]);
                            } catch (\Exception $e) {
                                Log::error("Failed to send order confirmation email: " . $e->getMessage());
                            }
                        } else {
                            Log::warning("Cannot send order confirmation email - no email found", [
                                'order_id' => $order->id,
                                'order_number' => $order->order_number
                            ]);
                        }

                        // Process first order coupon for next purchase
                        try {
                            $firstOrderService = new \App\Services\FirstOrderCouponService();
                            $firstOrderService->processFirstOrderCompletion($order);
                        } catch (\Exception $e) {
                            Log::error("Failed to process first order coupon: " . $e->getMessage());
                        }

                        return [
                            'success' => true,
                            'data' => [
                                'transaction' => $transaction,
                                'order' => $order,
                                'payment_data' => $paymentData
                            ]
                        ];
                    }
                }
            }

            return [
                'success' => false,
                'message' => 'Payment verification failed'
            ];

        } catch (\Exception $e) {
            Log::error('Paystack verification error', [
                'error' => $e->getMessage(),
                'reference' => $reference
            ]);

            return [
                'success' => false,
                'message' => 'Payment verification error'
            ];
        }
    }

    /**
     * Create customer on Paystack
     */
    public function createCustomer($email, $firstName = '', $lastName = '', $phone = '')
    {
        try {
            $payload = [
                'email' => $email,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'phone' => $phone
            ];

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->secretKey,
                'Content-Type' => 'application/json'
            ])->post($this->baseUrl . '/customer', $payload);

            if ($response->successful()) {
                $data = $response->json();
                
                if ($data['status']) {
                    return [
                        'success' => true,
                        'data' => $data['data']
                    ];
                }
            }

            return [
                'success' => false,
                'message' => 'Failed to create customer'
            ];

        } catch (\Exception $e) {
            Log::error('Paystack customer creation error', [
                'error' => $e->getMessage(),
                'email' => $email
            ]);

            return [
                'success' => false,
                'message' => 'Customer creation error'
            ];
        }
    }

    /**
     * Process refund
     */
    public function processRefund($transactionId, $amount = null, $reason = '')
    {
        try {
            $payload = [
                'transaction' => $transactionId,
                'amount' => $amount ? $amount * 100 : null, // Convert to kobo if specified
                'currency' => 'NGN',
                'customer_note' => $reason,
                'merchant_note' => 'Refund processed via admin panel'
            ];

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->secretKey,
                'Content-Type' => 'application/json'
            ])->post($this->baseUrl . '/refund', $payload);

            if ($response->successful()) {
                $data = $response->json();
                
                if ($data['status']) {
                    return [
                        'success' => true,
                        'data' => $data['data']
                    ];
                }
            }

            return [
                'success' => false,
                'message' => 'Refund processing failed'
            ];

        } catch (\Exception $e) {
            Log::error('Paystack refund error', [
                'error' => $e->getMessage(),
                'transaction_id' => $transactionId
            ]);

            return [
                'success' => false,
                'message' => 'Refund processing error'
            ];
        }
    }

    /**
     * Get transaction details
     */
    public function getTransaction($transactionId)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->secretKey
            ])->get($this->baseUrl . "/transaction/{$transactionId}");

            if ($response->successful()) {
                $data = $response->json();
                
                if ($data['status']) {
                    return [
                        'success' => true,
                        'data' => $data['data']
                    ];
                }
            }

            return [
                'success' => false,
                'message' => 'Failed to fetch transaction'
            ];

        } catch (\Exception $e) {
            Log::error('Paystack transaction fetch error', [
                'error' => $e->getMessage(),
                'transaction_id' => $transactionId
            ]);

            return [
                'success' => false,
                'message' => 'Transaction fetch error'
            ];
        }
    }

    /**
     * Reduce stock quantities after successful payment
     */
    public function reduceStock(Order $order)
    {
        \Log::info('PaystackService - Starting stock reduction for order: ' . $order->order_number);
        
        // Check if stock has already been reduced for this order using cache lock
        $cacheKey = 'stock_reduced_' . $order->id;
        
        if (\Cache::has($cacheKey)) {
            \Log::info('PaystackService - Stock reduction already completed for order: ' . $order->order_number);
            return;
        }
        
        // Set a lock to prevent concurrent stock reductions
        \Cache::put($cacheKey, true, 600); // 10 minutes lock
        
        try {
            // Load order items with products
            $order->load('items.product');
            
            foreach ($order->items as $item) {
                $product = $item->product;
                
                if (!$product) {
                    \Log::warning('PaystackService - Product not found for order item: ' . $item->id);
                    continue;
                }
                
                \Log::info('PaystackService - Reducing stock for product: ' . $product->name . 
                          ' (ID: ' . $product->id . ')' .
                          ', Current stock: ' . $product->stock_quantity . 
                          ', Quantity to deduct: ' . $item->quantity);
                
                if ($product->stock_quantity >= $item->quantity) {
                    // Use decrement to ensure atomic operation
                    $product->decrement('stock_quantity', $item->quantity);
                    
                    $product->refresh();
                    \Log::info('PaystackService - Stock reduced successfully. New stock: ' . $product->stock_quantity);
                } else {
                    \Log::warning('PaystackService - Insufficient stock for product: ' . $product->name . 
                                 ', Available: ' . $product->stock_quantity . 
                                 ', Required: ' . $item->quantity);
                }
            }
            
            \Log::info('PaystackService - Stock reduction completed for order: ' . $order->order_number);
            
        } catch (\Exception $e) {
            \Log::error('PaystackService - Stock reduction error for order: ' . $order->order_number, [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Remove cache lock on error so it can be retried
            \Cache::forget($cacheKey);
            throw $e;
        }
    }

    /**
     * Clear cart after successful payment
     */
    private function clearCart(Order $order)
    {
        \Log::info('PaystackService - Clearing cart for order: ' . $order->order_number);
        
        try {
            // Clear cart for authenticated users
            if ($order->user_id) {
                $deletedCount = \App\Models\Cart::where('user_id', $order->user_id)->delete();
                \Log::info('PaystackService - Cleared ' . $deletedCount . ' cart items from authenticated user (user_id: ' . $order->user_id . ')');
                
                // Clear cart session (coupon data)
                $sessionDeletedCount = \App\Models\CartSession::where('user_id', $order->user_id)->delete();
                \Log::info('PaystackService - Cleared ' . $sessionDeletedCount . ' cart sessions from authenticated user (user_id: ' . $order->user_id . ')');
            }
            
            // Clear cart for guest users using session_id from order
            if ($order->session_id) {
                $deletedCount = \App\Models\Cart::where('session_id', $order->session_id)->delete();
                \Log::info('PaystackService - Cleared ' . $deletedCount . ' cart items from guest (session_id: ' . $order->session_id . ')');
                
                // Clear cart session (coupon data)
                $sessionDeletedCount = \App\Models\CartSession::where('session_id', $order->session_id)->delete();
                \Log::info('PaystackService - Cleared ' . $sessionDeletedCount . ' cart sessions from guest (session_id: ' . $order->session_id . ')');
            }
            
            \Log::info('PaystackService - Cart and cart sessions cleared successfully for order: ' . $order->order_number);
        } catch (\Exception $e) {
            \Log::error('PaystackService - Error clearing cart for order: ' . $order->order_number, [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get webhook signature for verification
     */
    public function verifyWebhookSignature($payload, $signature)
    {
        $computedSignature = hash_hmac('sha512', $payload, $this->secretKey);
        return hash_equals($signature, $computedSignature);
    }
}
