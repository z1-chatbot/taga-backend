<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeliveryAgent;
use App\Models\Order;
use App\Models\OrderShipment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DeliveryAgentController extends Controller
{
    public function login(Request $request)
    {
        $request->validate(['email' => 'required|email', 'password' => 'required']);
        $agent = DeliveryAgent::where('email', $request->email)->first();

        if (!$agent || !Hash::check($request->password, $agent->password)) {
            return response()->json(['success' => false, 'message' => 'Invalid credentials'], 401);
        }

        $agent->update(['last_active_at' => now()]);
        
        // Generate token (same format as TokenAuthentication)
        $token = \App\Support\ApiToken::issue(\App\Support\ApiToken::TYPE_AGENT, $agent->id, $agent->email);
        
        return response()->json([
            'success' => true, 
            'data' => [
                'agent' => $agent,
                'token' => $token
            ]
        ]);
    }

    public function dashboard()
    {
        $agent = auth()->user();

        // Get shipments where agent is currently assigned OR was the pickup agent
        $shipments = OrderShipment::where(function($query) use ($agent) {
                $query->where('delivery_agent_id', $agent->id)
                      ->orWhere('pickup_agent_id', $agent->id);
            })
            ->with('order')
            ->get();

        $activeDeliveries = $shipments->whereIn('status', ['assigned_to_agent', 'picked_up', 'in_transit', 'out_for_delivery'])->count();
        
        // Count completed deliveries including pickups that reached hub
        $completedToday = $shipments->filter(function($s) use ($agent) {
            // Delivered by this agent OR picked up by this agent and reached hub
            $isDelivered = $s->status === 'delivered' && $s->delivery_agent_id === $agent->id;
            $isPickupCompleted = $s->status === 'arrived_at_hub' && $s->pickup_agent_id === $agent->id;
            $isToday = $s->updated_at && $s->updated_at->isToday();
            return ($isDelivered || $isPickupCompleted) && $isToday;
        })->count();
        
        $pendingDeliveries = $shipments->whereIn('status', ['assigned_to_agent', 'pending'])->count();

        $recentDeliveries = $shipments->sortByDesc('updated_at')->take(10)->map(function($s) {
            $addr = $s->order->shipping_address ?? [];
            return [
                'id' => $s->id,
                'order_number' => $s->order->order_number ?? 'N/A',
                'customer_address' => implode(', ', array_filter([
                    $addr['city'] ?? '', $addr['state'] ?? '',
                ])) ?: 'N/A',
                'status' => $s->status,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => [
                'agent' => ['name' => $agent->name, 'email' => $agent->email],
                'active_deliveries' => $activeDeliveries,
                'completed_today' => $completedToday,
                'total_earnings' => (float) ($agent->total_earned ?? 0),
                'pending_deliveries' => $pendingDeliveries,
                'available_balance' => (float) ($agent->available_balance ?? 0),
                'total_deliveries' => $agent->total_deliveries ?? 0,
                'rating' => $agent->rating ?? 0,
                'recent_deliveries' => $recentDeliveries,
            ]
        ]);
    }

    public function getShipments(Request $request)
    {
        $agent = auth()->user();
        
        // Get shipments where agent is currently assigned OR was the pickup agent
        $shipments = OrderShipment::where(function($query) use ($agent) {
                $query->where('delivery_agent_id', $agent->id)
                      ->orWhere('pickup_agent_id', $agent->id);
            })
            ->with(['order.shippingZone', 'order.items', 'store', 'logisticsCompany'])
            ->latest()
            ->paginate(20);

        // Append is_interstate flag and items_count to each shipment
        $shipments->getCollection()->transform(function ($shipment) use ($agent) {
            $zone = $shipment->order->shippingZone ?? null;
            $shipment->is_interstate = $zone && $zone->type === 'interstate';
            
            // Add items count to order
            if ($shipment->order) {
                $shipment->order->items_count = $shipment->order->items ? $shipment->order->items->count() : 0;
                
                // For store pickup orders, set delivery address to agent's hub instead of customer address
                if ($shipment->order->delivery_type === 'store_pickup' && $shipment->logisticsCompany) {
                    $shipment->order->delivery_address = $shipment->logisticsCompany->hub_address;
                }
            }
            
            // Mark if this agent was the pickup agent (for display purposes)
            $shipment->was_pickup_agent = $shipment->pickup_agent_id === $agent->id;
            $shipment->is_current_agent = $shipment->delivery_agent_id === $agent->id;
            
            return $shipment;
        });

        return response()->json(['success' => true, 'data' => $shipments]);
    }

    public function updateStatus(Request $request, $shipmentId)
    {
        $request->validate([
            'status' => 'required|in:picked_up,out_for_delivery,delivered,arrived_at_hub',
            'delivery_code' => 'required_if:status,delivered|nullable|string',
        ]);

        $agent = auth()->user();
        $shipment = OrderShipment::where('delivery_agent_id', $agent->id)->findOrFail($shipmentId);

        $order = $shipment->order;

        /*
         * The code belongs to the parcel, not the order. On an order split
         * between two pharmacies the customer holds a code per parcel, and
         * checking against the order's would let either rider close out the
         * other's delivery.
         */
        if ($request->status === 'delivered' && ! $shipment->verifyDeliveryCode($request->delivery_code)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid delivery confirmation code. Please ask the customer for the code for this parcel.',
            ], 422);
        }

        $oldStatus = $order ? $order->status : null;

        /*
         * The shipment used to be advanced before the order, unwrapped. When
         * the order refused the move — an unapproved prescription — the rider
         * got a 422 but their shipment was already sitting at "picked up",
         * showing them a collected parcel against an order still marked
         * pending. Refusing up front keeps the two in step, and the
         * transaction below covers anything else in this method that throws.
         */
        if ($order && ! $this->canAdvanceToDispatch($order, $request->status)) {
            return response()->json([
                'success' => false,
                'message' => 'This order contains prescription medicine that has not been approved. '
                    ."Prescription status: {$order->prescription_status}.",
                'code' => 'prescription_not_cleared',
            ], 422);
        }

        DB::beginTransaction();

        $shipment->updateStatus($request->status, [
            'updated_by' => 'delivery_agent',
            'agent_id' => $agent->id,
            'notes' => $request->notes,
        ]);

        /*
         * The order moves only when every parcel on it has reached this stage.
         *
         * An order split between two pharmacies is two parcels on two journeys,
         * and one rider confirming their own delivery used to mark the whole
         * order delivered — crediting the courier and settling a
         * cash-on-delivery payment while the second pharmacy's parcel had not
         * left the shop. For the single-parcel orders that are most of them
         * this is true immediately and nothing changes.
         */
        $orderReached = $order && $order->allShipmentsReached($request->status);

        if ($order) {
            switch ($request->status) {
                case 'picked_up':
                    if ($orderReached) {
                        $order->update(['status' => 'picked_up', 'picked_up_at' => now()]);
                    }
                    break;
                case 'arrived_at_hub':
                    // Interstate: agent delivered to logistics hub, agent's job is done
                    // Save pickup agent ID before unassigning, then unassign for logistics to reassign new agent
                    $shipment->update([
                        'pickup_agent_id' => $agent->id, // Track who did the pickup
                        'delivery_agent_id' => null, // Unassign for next agent
                        'arrived_at_hub_at' => now()
                    ]);
                    if ($orderReached) {
                        $order->update(['status' => 'arrived_at_hub', 'delivery_agent_id' => null]);
                    }
                    // Mark pickup agent as available again
                    $activeCount = $agent->shipments()->whereNotIn('status', ['delivered', 'cancelled'])->where('id', '!=', $shipment->id)->count();
                    if ($activeCount === 0) {
                        $agent->update(['status' => 'available']);
                    }
                    break;
                case 'out_for_delivery':
                    if ($orderReached) {
                        $order->update(['status' => 'out_for_delivery', 'out_for_delivery_at' => now()]);
                    }
                    break;
                case 'delivered':
                    // Credited against the parcel, not the order, and so always:
                    // this rider carried this leg whether or not the rest of the
                    // order has moved. One service owns the calculation, decides
                    // who the payee is and refuses to pay twice for the same
                    // journey — whichever portal confirms the delivery.
                    app(\App\Services\DeliveryEarningsService::class)
                        ->creditForDelivery($order->fresh(), $shipment);

                    if ($orderReached) {
                        $order->update([
                            'status' => 'delivered',
                            'delivered_at' => now(),
                            'delivery_notes' => $request->notes,
                        ]);
                    }

                    // Mark agent as available if no other active shipments
                    $activeCount = $agent->shipments()->whereNotIn('status', ['delivered', 'cancelled'])->where('id', '!=', $shipment->id)->count();
                    if ($activeCount === 0) {
                        $agent->update(['status' => 'available']);
                    }
                    break;
            }

            // Send email notifications after the response is sent (non-blocking).
            // This has to be a terminating callback rather than a shutdown
            // function: shutdown functions run after Laravel has torn the
            // container down, so the Log facade below is unresolvable and any
            // failure in here becomes an uncatchable fatal.
            app()->terminating(function () use ($order, $oldStatus, $request) {
                try {
                    $notificationService = new \App\Services\OrderNotificationService();
                    $notificationService->notifyStatusUpdate($order->fresh(), $oldStatus, $request->status);
                    \Log::info('Status notification email sent', ['order_id' => $order->id, 'status' => $request->status]);
                } catch (\Throwable $e) {
                    // Throwable, not Exception: a missing mailer or a broken
                    // view throws Error, and letting that escape a background
                    // callback kills the process.
                    \Log::error("Failed to send status notification: " . $e->getMessage());
                }
            });
        }

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Delivery status updated to ' . $request->status,
            'data' => $shipment->fresh()->load('order'),
        ]);
    }

    /**
     * Whether this status change is allowed to move the order toward dispatch.
     *
     * Mirrors the gate in Order::booted(). Checking here lets the portals
     * answer with a clear message instead of relying on the exception, and
     * stops a shipment being advanced only to be rolled back.
     */
    private function canAdvanceToDispatch(Order $order, string $status): bool
    {
        if (! in_array($status, Order::DISPATCH_STATUSES, true)) {
            return true;
        }

        return $order->isClearedForDispatch();
    }

    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:6|confirmed',
        ]);

        $agent = auth()->user();

        if (!Hash::check($request->current_password, $agent->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Current password is incorrect.',
            ], 422);
        }

        $agent->update([
            'password' => Hash::make($request->new_password),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully.',
        ]);
    }

    public function getProfile()
    {
        $agent = auth()->user();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $agent->id,
                'name' => $agent->name,
                'email' => $agent->email,
                'phone' => $agent->phone,
                'vehicle_type' => $agent->vehicle_type,
                'vehicle_number' => $agent->vehicle_number,
                'license_number' => $agent->license_number,
                'bank_name' => $agent->bank_name,
                'account_number' => $agent->account_number,
                'account_name' => $agent->account_name,
                'status' => $agent->status,
                'rating' => $agent->rating,
                'total_deliveries' => $agent->total_deliveries,
                'logistics_company' => $agent->logisticsCompany ? [
                    'id' => $agent->logisticsCompany->id,
                    'name' => $agent->logisticsCompany->name,
                ] : null,
            ],
        ]);
    }

    public function updateProfile(Request $request)
    {
        $request->validate([
            'phone' => 'nullable|string',
            'vehicle_type' => 'nullable|string',
            'vehicle_number' => 'nullable|string',
            'license_number' => 'nullable|string',
            'bank_name' => 'nullable|string',
            'account_number' => 'nullable|string',
            'account_name' => 'nullable|string',
        ]);

        $agent = auth()->user();
        $agent->update($request->only([
            'phone', 'vehicle_type', 'vehicle_number', 'license_number',
            'bank_name', 'account_number', 'account_name',
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully.',
            'data' => $agent->fresh(),
        ]);
    }

    public function toggleStatus(Request $request)
    {
        $request->validate([
            'status' => 'required|in:available,offline',
        ]);

        $agent = auth()->user();

        // Don't allow toggling if agent is suspended
        if ($agent->status === 'suspended') {
            return response()->json([
                'success' => false,
                'message' => 'Your account is suspended. Please contact your company or support.',
            ], 403);
        }

        $agent->update(['status' => $request->status]);

        return response()->json([
            'success' => true,
            'message' => 'Status updated to ' . $request->status,
            'data' => ['status' => $agent->status],
        ]);
    }
}
