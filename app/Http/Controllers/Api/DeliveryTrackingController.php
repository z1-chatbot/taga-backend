<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderShipment;
use Illuminate\Http\Request;

class DeliveryTrackingController extends Controller
{
    /**
     * Get tracking information by order number or tracking number
     */
    public function track(Request $request)
    {
        $request->validate([
            'tracking_number' => 'required|string'
        ]);

        $trackingNumber = $request->tracking_number;

        // Try to find by order number first
        $order = Order::where('order_number', $trackingNumber)->first();

        if (!$order) {
            // Try to find by shipment tracking number
            $shipment = OrderShipment::where('tracking_number', $trackingNumber)->first();
            if ($shipment) {
                $order = $shipment->order;
            }
        }

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'order' => [
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                    'created_at' => $order->created_at,
                    'estimated_delivery' => $order->shipments->first()?->getEstimatedDeliveryDate(),
                ],
                'shipments' => $order->shipments->map(function ($shipment) {
                    return [
                        'tracking_number' => $shipment->tracking_number,
                        'store' => $shipment->store->name ?? 'N/A',
                        'status' => $shipment->status,
                        'estimated_delivery_days' => $shipment->estimated_delivery_days,
                    ];
                }),
                'tracking_history' => $order->getTrackingHistory()->map(function ($event) {
                    return [
                        'status' => $event->status,
                        'description' => $event->description,
                        'location' => $event->location,
                        'timestamp' => $event->created_at,
                    ];
                }),
                'current_status' => $order->getCurrentTrackingStatus()?->description,
            ]
        ]);
    }

    /**
     * Get detailed tracking for authenticated user's order
     */
    public function getOrderTracking($orderId)
    {
        $order = Order::with(['shipments', 'trackingEvents', 'deliveryAgent', 'logisticsCompany'])
            ->findOrFail($orderId);

        // Verify user owns this order
        if (auth()->check() && $order->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'order' => [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                    'tracking_number' => $order->tracking_number,
                    'created_at' => $order->created_at,
                    'tracking_history' => $order->getTrackingHistory()->map(function ($event) {
                        return [
                            'status' => $event->status,
                            'description' => $event->description,
                            'location' => $event->location,
                            'timestamp' => $event->created_at,
                            'metadata' => $event->metadata,
                        ];
                    }),
                    'shipments' => $order->shipments->map(function ($shipment) {
                        return [
                            'tracking_number' => $shipment->tracking_number,
                            'store' => $shipment->store->name ?? 'N/A',
                            'status' => $shipment->status,
                            'estimated_delivery_days' => $shipment->estimated_delivery_days,
                        ];
                    }),
                ],
                'delivery_agent' => $order->deliveryAgent ? [
                    'name' => $order->deliveryAgent->name,
                    'phone' => $order->deliveryAgent->phone,
                    'rating' => $order->deliveryAgent->rating,
                ] : null,
                'logistics_company' => $order->logisticsCompany ? [
                    'name' => $order->logisticsCompany->name,
                    'phone' => $order->logisticsCompany->contact_phone,
                ] : null,
            ]
        ]);
    }
}
