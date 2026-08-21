<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class DeliveryPortalController extends Controller
{
    /**
     * Get order details by token (no auth required)
     */
    public function getOrderByToken(Request $request, $orderId): JsonResponse
    {
        $token = $request->query('token');
        
        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Access token is required'
            ], 401);
        }

        // 'customer' is not a relationship on Order — the account is 'user'.
        // Eager-loading it threw RelationNotFoundException, so this endpoint
        // 500'd for every caller that got past the token check.
        $order = Order::with(['items.product', 'deliveryAgent', 'store', 'user'])
            ->where('id', $orderId)
            ->where('delivery_access_token', $token)
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid order or access token'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'customer_name' => $order->customer_name,
                'customer_phone' => $order->shipping_address['phone'] ?? null,
                'shipping_address' => $order->shipping_address,
                'total_amount' => $order->total_amount,
                'payment_method' => $order->payment_method,
                'delivery_notes' => $order->delivery_notes,
                'items' => $order->items->map(function ($item) {
                    return [
                        'product_name' => $item->product->name ?? 'N/A',
                        'quantity' => $item->quantity,
                        'price' => $item->price,
                        'subtotal' => $item->quantity * $item->price,
                    ];
                }),
                'store' => [
                    'name' => $order->store->name ?? 'N/A',
                    'phone' => $order->store->phone ?? null,
                ],
                'delivery_agent' => [
                    'name' => $order->deliveryAgent->name ?? 'N/A',
                    'phone' => $order->deliveryAgent->phone ?? null,
                ],
                'created_at' => $order->created_at,
                'updated_at' => $order->updated_at,
            ]
        ]);
    }

    /**
     * Update delivery status (no auth required, token-based)
     */
    public function updateDeliveryStatus(Request $request, $orderId): JsonResponse
    {
        $token = $request->input('token');
        
        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Access token is required'
            ], 401);
        }

        $order = Order::where('id', $orderId)
            ->where('delivery_access_token', $token)
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid order or access token'
            ], 404);
        }

        $validated = $request->validate([
            'status' => 'required|in:picked_up,out_for_delivery,delivered',
            'notes' => 'nullable|string',
            'proof_of_delivery' => 'nullable|string', // Base64 image or URL
        ]);

        // Update order status based on delivery status
        switch ($validated['status']) {
            case 'picked_up':
                $order->update([
                    'status' => 'shipped',
                    'delivery_notes' => ($order->delivery_notes ?? '') . "\n[" . now() . "] Picked up by delivery agent. " . ($validated['notes'] ?? '')
                ]);
                break;
            
            case 'out_for_delivery':
                $order->update([
                    'status' => 'shipped',
                    'delivery_notes' => ($order->delivery_notes ?? '') . "\n[" . now() . "] Out for delivery. " . ($validated['notes'] ?? '')
                ]);
                break;
            
            case 'delivered':
                $order->update([
                    'status' => 'delivered',
                    'delivered_at' => now(),
                    'delivery_notes' => ($order->delivery_notes ?? '') . "\n[" . now() . "] Delivered successfully. " . ($validated['notes'] ?? '')
                ]);
                
                // Mark agent as available if no other active deliveries
                if ($order->deliveryAgent) {
                    $order->deliveryAgent->completeDelivery($order);
                }
                break;
        }

        return response()->json([
            'success' => true,
            'message' => 'Delivery status updated successfully',
            'data' => $order->fresh()
        ]);
    }

    /**
     * Accept delivery assignment (agent confirms they'll handle it)
     */
    public function acceptDelivery(Request $request, $orderId): JsonResponse
    {
        $token = $request->input('token');
        
        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Access token is required'
            ], 401);
        }

        $order = Order::where('id', $orderId)
            ->where('delivery_access_token', $token)
            ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid order or access token'
            ], 404);
        }

        $order->update([
            'delivery_notes' => ($order->delivery_notes ?? '') . "\n[" . now() . "] Delivery accepted by agent.",
            'status' => 'processing' // Or keep current status
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Delivery accepted successfully',
            'data' => $order->fresh()
        ]);
    }
}
