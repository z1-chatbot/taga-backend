<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesOrderAccess;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Services\PaystackService;
use App\Jobs\SendOrderStatusEmail;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    use AuthorizesOrderAccess;

    protected $paystackService;

    public function __construct(PaystackService $paystackService)
    {
        $this->paystackService = $paystackService;
    }

    /**
     * Initialize payment for an order
     */
    public function initializePayment(Request $request): JsonResponse
    {
        $request->validate([
            'order_id' => 'required|exists:orders,id',
            'email' => 'required|email',
            'first_name' => 'nullable|string',
            'last_name' => 'nullable|string',
            'phone' => 'nullable|string'
        ]);

        $order = Order::with('user', 'items.product')->find($request->order_id);

        if (! $this->canAccessOrder($request, $order)) {
            return $this->orderNotFound();
        }

        // Check if order is already paid
        if ($order->payment_status === Order::PAYMENT_PAID) {
            return response()->json([
                'success' => false,
                'message' => 'Order has already been paid'
            ], 422);
        }

        // Verify stock availability
        foreach ($order->items as $item) {
            if ($item->product->stock_quantity < $item->quantity) {
                return response()->json([
                    'success' => false,
                    'message' => "Insufficient stock for {$item->product->name}"
                ], 422);
            }
        }

        $customerData = [
            'email' => $request->email,
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'phone' => $request->phone,
            'name' => trim(($request->first_name ?? '') . ' ' . ($request->last_name ?? ''))
        ];

        Log::info('Initializing Paystack payment', [
            'order_id' => $order->id,
            'customer_data' => $customerData,
            'order_total' => $order->total_amount
        ]);

        $result = $this->paystackService->initializePayment($order, $customerData);

        Log::info('Paystack initialization result', [
            'success' => $result['success'],
            'message' => $result['message'] ?? 'No message',
            'order_id' => $order->id
        ]);

        if ($result['success']) {
            return response()->json([
                'success' => true,
                'message' => 'Payment initialized successfully',
                'data' => $result['data']
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => $result['message'] ?? 'Payment initialization failed'
        ], 422);
    }

    /**
     * Verify payment after callback
     */
    public function verifyPayment(Request $request): JsonResponse
    {
        $request->validate([
            'reference' => 'required|string'
        ]);

        $result = $this->paystackService->verifyPayment($request->reference);

        if ($result['success']) {
            return response()->json([
                'success' => true,
                'message' => 'Payment verified successfully',
                'data' => [
                    'order' => $result['data']['order'],
                    'transaction' => $result['data']['transaction']
                ]
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => $result['message']
        ], 422);
    }

    /**
     * Handle Paystack webhook
     */
    public function handleWebhook(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $signature = $request->header('x-paystack-signature');

        Log::info('Paystack webhook received', [
            'has_signature' => !empty($signature),
            'payload_length' => strlen($payload)
        ]);

        // Verify webhook signature
        if (!$this->paystackService->verifyWebhookSignature($payload, $signature)) {
            Log::warning('Invalid Paystack webhook signature', [
                'signature' => $signature,
                'payload_preview' => substr($payload, 0, 100)
            ]);
            return response()->json(['success' => false], 400);
        }

        $event = json_decode($payload, true);

        Log::info('Paystack webhook event', [
            'event_type' => $event['event'] ?? 'unknown',
            'reference' => $event['data']['reference'] ?? 'unknown'
        ]);

        try {
            switch ($event['event']) {
                case 'charge.success':
                    $this->handleSuccessfulCharge($event['data']);
                    break;

                case 'charge.failed':
                    $this->handleFailedCharge($event['data']);
                    break;

                case 'charge.dispute.create':
                    $this->handleDispute($event['data']);
                    break;

                case 'refund.processed':
                    $this->handleRefund($event['data']);
                    break;

                default:
                    Log::info('Unhandled Paystack webhook event: ' . $event['event']);
            }

            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            Log::error('Paystack webhook error: ' . $e->getMessage(), [
                'event' => $event,
                'error' => $e->getTraceAsString()
            ]);

            return response()->json(['success' => false], 500);
        }
    }

    /**
     * Get payment status for an order
     */
    public function getPaymentStatus(Request $request, $orderId): JsonResponse
    {
        $order = Order::with('paymentTransactions')->find($orderId);

        if (! $this->canAccessOrder($request, $order)) {
            return $this->orderNotFound();
        }

        $latestTransaction = $order->paymentTransactions()->latest()->first();

        return response()->json([
            'success' => true,
            'data' => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'payment_status' => $order->payment_status,
                'total_amount' => $order->total_amount,
                'latest_transaction' => $latestTransaction ? [
                    'id' => $latestTransaction->id,
                    'reference' => $latestTransaction->reference,
                    'status' => $latestTransaction->status,
                    'amount' => $latestTransaction->amount,
                    'gateway' => $latestTransaction->gateway,
                    'created_at' => $latestTransaction->created_at
                ] : null
            ]
        ]);
    }

    /**
     * Admin: Manually verify and confirm payment for an order
     */
    public function adminVerifyPayment($orderId): JsonResponse
    {
        try {
            $order = Order::with('paymentTransactions')->find($orderId);

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found'
                ], 404);
            }

            // Check if already paid
            if ($order->payment_status === Order::PAYMENT_PAID) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order payment already confirmed'
                ], 422);
            }

            // Get the latest transaction from the loaded relationship
            $transaction = $order->paymentTransactions->sortByDesc('created_at')->first();

            if (!$transaction) {
                Log::warning('No payment transaction found for order - payment may not have been initialized', [
                    'order_id' => $orderId,
                    'order_number' => $order->order_number,
                    'transactions_count' => $order->paymentTransactions->count()
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'No payment transaction found for this order. Payment was not initialized through Paystack. Please use "Manual Confirmation" if payment was received through other means.',
                    'debug' => [
                        'order_id' => $orderId,
                        'order_number' => $order->order_number,
                        'transactions_count' => $order->paymentTransactions->count(),
                        'suggestion' => 'Use Manual Confirmation button instead'
                    ]
                ], 422);
            }

            // Try to verify with Paystack
            $result = $this->paystackService->verifyPayment($transaction->reference);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => 'Payment verified and order confirmed successfully',
                    'data' => [
                        'order' => $result['data']['order'],
                        'transaction' => $result['data']['transaction']
                    ]
                ]);
            }

            // If Paystack verification fails, allow manual confirmation
            return response()->json([
                'success' => false,
                'message' => 'Payment verification failed. Use manual confirmation if payment was received.',
                'data' => [
                    'order' => $order,
                    'transaction' => $transaction
                ]
            ], 422);

        } catch (\Exception $e) {
            Log::error('Admin payment verification error', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to verify payment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Admin: Manually confirm payment (bypass Paystack verification)
     */
    public function adminConfirmPayment(Request $request, $orderId): JsonResponse
    {
        try {
            $request->validate([
                'payment_method' => 'nullable|string|max:50',
                'transaction_id' => 'nullable|string|max:255',
                'notes' => 'nullable|string|max:500'
            ]);

            $order = Order::with('paymentTransactions')->find($orderId);

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found'
                ], 404);
            }

            // Check if already paid
            if ($order->payment_status === Order::PAYMENT_PAID) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order payment already confirmed'
                ], 422);
            }

            // Get or create transaction from the loaded relationship
            $transaction = $order->paymentTransactions->sortByDesc('created_at')->first();

            if ($transaction) {
                // Update existing transaction
                $transaction->update([
                    'status' => PaymentTransaction::STATUS_SUCCESS,
                    'transaction_id' => $request->transaction_id ?? $transaction->transaction_id,
                    'payment_method' => $request->payment_method ?? $transaction->payment_method ?? 'manual',
                    'gateway_response' => [
                        'manually_confirmed' => true,
                        'confirmed_by' => auth()->user()->name ?? 'Admin',
                        'confirmed_at' => now()->toISOString(),
                        'notes' => $request->notes
                    ],
                    'paid_at' => now()
                ]);
            } else {
                // Create new transaction for manual payment
                $transaction = PaymentTransaction::create([
                    'order_id' => $order->id,
                    'user_id' => $order->user_id,
                    'reference' => 'MANUAL_' . strtoupper(uniqid()),
                    'transaction_id' => $request->transaction_id,
                    'amount' => $order->total_amount,
                    'currency' => $order->currency ?? 'NGN',
                    'status' => PaymentTransaction::STATUS_SUCCESS,
                    'payment_method' => $request->payment_method ?? 'manual',
                    'gateway' => PaymentTransaction::GATEWAY_MANUAL,
                    'gateway_response' => [
                        'manually_confirmed' => true,
                        'confirmed_by' => auth()->user()->name ?? 'Admin',
                        'confirmed_at' => now()->toISOString(),
                        'notes' => $request->notes
                    ],
                    'paid_at' => now()
                ]);
            }

            // Update order - set payment as paid and status to processing
            $order->update([
                'payment_status' => Order::PAYMENT_PAID,
                'payment_id' => $transaction->transaction_id,
                'status' => Order::STATUS_PROCESSING
            ]);

            // Create initial tracking event for order confirmation
            $order->createTrackingEventForStatus('processing');

            // Reduce stock quantities
            $this->paystackService->reduceStock($order);

            // A manually confirmed payment is a paid order like any other, and
            // the customer still has to read a code to the rider.
            $order->ensureDeliveryCode();

            // Send order placed/confirmed notifications to all parties
            try {
                $notificationService = new \App\Services\OrderNotificationService();
                $notificationService->notifyOrderPlaced($order->fresh());
                Log::info("Order placed notifications sent after manual confirmation: {$order->order_number}");
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
                SendOrderStatusEmail::dispatch($order, 'confirmed');
                Log::info("Order confirmed email queued (manual): {$order->order_number}", [
                    'recipient' => $emailRecipient,
                    'is_guest' => !$order->user
                ]);
            } else {
                Log::warning("Cannot send order confirmation email - no email found", [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number
                ]);
            }

            Log::info('Payment manually confirmed', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'confirmed_by' => auth()->user()->name ?? 'Admin'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment confirmed successfully. Order is now processing.',
                'data' => [
                    'order' => $order->fresh(),
                    'transaction' => $transaction
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Admin payment confirmation error', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to confirm payment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Process refund for an order
     */
    public function processRefund(Request $request, $orderId): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|max:500',
            'amount' => 'nullable|numeric|min:0'
        ]);

        $order = Order::with('paymentTransactions')->find($orderId);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found'
            ], 404);
        }

        if ($order->payment_status !== Order::PAYMENT_PAID) {
            return response()->json([
                'success' => false,
                'message' => 'Order has not been paid'
            ], 422);
        }

        $successfulTransaction = $order->paymentTransactions()
                                     ->where('status', PaymentTransaction::STATUS_SUCCESS)
                                     ->first();

        if (!$successfulTransaction) {
            return response()->json([
                'success' => false,
                'message' => 'No successful payment transaction found'
            ], 422);
        }

        $refundAmount = $request->amount ?? $order->total_amount;

        if ($refundAmount > $order->total_amount) {
            return response()->json([
                'success' => false,
                'message' => 'Refund amount cannot exceed order total'
            ], 422);
        }

        $result = $this->paystackService->processRefund(
            $successfulTransaction->transaction_id,
            $refundAmount,
            $request->reason
        );

        if ($result['success']) {
            // Update order status
            $order->update([
                'status' => Order::STATUS_REFUNDED,
                'payment_status' => Order::PAYMENT_REFUNDED,
                'refunded_at' => now()
            ]);

            // Create refund transaction record
            PaymentTransaction::create([
                'order_id' => $order->id,
                'user_id' => $order->user_id,
                'transaction_id' => $result['data']['id'] ?? null,
                'reference' => 'REFUND_' . $successfulTransaction->reference,
                'amount' => -$refundAmount,
                'currency' => $order->currency,
                'status' => PaymentTransaction::STATUS_SUCCESS,
                'payment_method' => 'refund',
                'gateway' => PaymentTransaction::GATEWAY_PAYSTACK,
                'gateway_response' => $result['data'],
                'paid_at' => now()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Refund processed successfully',
                'data' => $result['data']
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => $result['message']
        ], 422);
    }

    /**
     * Handle successful charge webhook
     */
    private function handleSuccessfulCharge($data)
    {
        $reference = $data['reference'];
        $transaction = PaymentTransaction::where('reference', $reference)->first();

        if (!$transaction) {
            Log::warning('Webhook: Transaction not found for reference', ['reference' => $reference]);
            return;
        }

        if ($transaction->status === PaymentTransaction::STATUS_SUCCESS) {
            Log::info('Webhook: Transaction already marked as successful', ['reference' => $reference]);
            return;
        }

        // Update transaction_id and other fields from Paystack before marking successful
        $transaction->update([
            'transaction_id' => $data['id'] ?? null,
            'authorization_code' => $data['authorization']['authorization_code'] ?? null,
            'customer_code' => $data['customer']['customer_code'] ?? null,
            'fees' => ($data['fees'] ?? 0) / 100,
        ]);

        // Reload the order relationship to ensure it's available
        $transaction->load('order');

        $transaction->markAsSuccessful($data);
        
        Log::info('Payment confirmed via webhook', [
            'reference' => $reference,
            'amount' => $data['amount'] / 100,
            'order_id' => $transaction->order_id
        ]);
    }

    /**
     * Handle failed charge webhook
     */
    private function handleFailedCharge($data)
    {
        $reference = $data['reference'];
        $transaction = PaymentTransaction::where('reference', $reference)->first();

        if ($transaction && $transaction->status === PaymentTransaction::STATUS_PENDING) {
            $transaction->markAsFailed($data['gateway_response'] ?? 'Payment failed', $data);
            
            Log::info('Payment failed via webhook', [
                'reference' => $reference,
                'reason' => $data['gateway_response'] ?? 'Unknown'
            ]);
        }
    }

    /**
     * Handle dispute webhook
     */
    private function handleDispute($data)
    {
        // Log dispute for manual review
        Log::warning('Payment dispute created', [
            'transaction_id' => $data['transaction']['id'],
            'amount' => $data['amount'] / 100,
            'reason' => $data['reason'],
            'status' => $data['status']
        ]);

        // You can implement dispute handling logic here
        // e.g., notify admin, update order status, etc.
    }

    /**
     * Handle refund webhook
     */
    private function handleRefund($data)
    {
        Log::info('Refund processed via webhook', [
            'transaction_id' => $data['transaction']['id'],
            'amount' => $data['amount'] / 100,
            'status' => $data['status']
        ]);

        // Additional refund handling logic can be added here
    }
}
