<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LogisticsCompany;
use App\Models\AgentInvitation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use App\Mail\PayoutRequestedEmail;

class LogisticsCompanyController extends Controller
{
    public function login(Request $request)
    {
        $request->validate(['email' => 'required|email', 'password' => 'required']);
        $company = LogisticsCompany::where('admin_email', $request->email)->first();

        if (!$company || !Hash::check($request->password, $company->admin_password)) {
            return response()->json(['success' => false, 'message' => 'Invalid credentials'], 401);
        }

        // Generate token (same format as TokenAuthentication)
        $token = \App\Support\ApiToken::issue(\App\Support\ApiToken::TYPE_COMPANY, $company->id, $company->admin_email);

        return response()->json([
            'success' => true, 
            'data' => [
                'company' => $company,
                'token' => $token
            ]
        ]);
    }

    public function dashboard()
    {
        $company = auth()->user();
        
        // Get all shipments assigned to this company (directly or via agents)
        $shipments = \App\Models\OrderShipment::where(function($q) use ($company) {
            $q->where('logistics_company_id', $company->id)
              ->orWhereHas('deliveryAgent', function($q2) use ($company) {
                  $q2->where('logistics_company_id', $company->id);
              });
        })->with(['order', 'deliveryAgent'])->latest()->get();
        
        // Calculate stats
        $totalDeliveries = $shipments->count();
        $completedDeliveries = $shipments->where('status', 'delivered')->count();
        $inProgressDeliveries = $shipments->whereIn('status', ['pending', 'assigned_to_agent', 'picked_up', 'in_transit', 'out_for_delivery'])->count();
        $successRate = $totalDeliveries > 0 ? round(($completedDeliveries / $totalDeliveries) * 100, 1) : 0;
        
        // Get recent deliveries
        $recentDeliveries = $shipments->take(10)->map(function($shipment) {
            $shippingAddr = $shipment->order->shipping_address ?? [];
            $addressStr = implode(', ', array_filter([
                $shippingAddr['address'] ?? '',
                $shippingAddr['city'] ?? '',
                $shippingAddr['state'] ?? '',
            ]));
            return [
                'id' => $shipment->id,
                'tracking_number' => $shipment->tracking_number,
                'customer_name' => $shipment->order->customer_name ?? 'N/A',
                'delivery_address' => $addressStr ?: 'N/A',
                'status' => $shipment->status,
                'agent_name' => $shipment->deliveryAgent->name ?? 'Unassigned',
                'created_at' => $shipment->created_at->format('Y-m-d H:i'),
                'delivery_fee' => $shipment->shipping_fee,
            ];
        });
        
        // Get pending payouts
        $pendingPayouts = \App\Models\AgentPayout::where('logistics_company_id', $company->id)
            ->where('status', 'pending')
            ->with('deliveryAgent')
            ->latest()
            ->take(5)
            ->get()
            ->map(function($payout) {
                return [
                    'id' => $payout->id,
                    'agent_name' => $payout->deliveryAgent->name ?? 'N/A',
                    'amount' => $payout->amount,
                    'requested_at' => $payout->created_at->format('Y-m-d H:i'),
                    'status' => $payout->status,
                ];
            });
        
        return response()->json([
            'success' => true,
            'data' => [
                'company' => $company,
                'stats' => [
                    'total_agents' => $company->getTotalActiveAgents(),
                    'active_agents' => $company->deliveryAgents()->where('status', 'available')->count(),
                    'total_deliveries' => $totalDeliveries,
                    'completed_deliveries' => $completedDeliveries,
                    'in_progress_deliveries' => $inProgressDeliveries,
                    'success_rate' => $successRate,
                    'available_balance' => $company->available_balance,
                    'pending_balance' => $company->pending_balance,
                    'total_earned' => $company->total_earned,
                    'average_rating' => round($company->getAverageRating(), 1),
                    'pending_payouts_count' => \App\Models\AgentPayout::where('logistics_company_id', $company->id)
                        ->where('status', 'pending')->count(),
                ],
                'recent_agents' => $company->deliveryAgents()->latest()->take(5)->get(),
                'recent_deliveries' => $recentDeliveries,
                'pending_payouts' => $pendingPayouts,
            ]
        ]);
    }

    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:6|confirmed',
        ]);

        $company = auth()->user();

        if (!Hash::check($request->current_password, $company->admin_password)) {
            return response()->json([
                'success' => false,
                'message' => 'Current password is incorrect.',
            ], 422);
        }

        $company->update([
            'admin_password' => Hash::make($request->new_password),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully.',
        ]);
    }

    public function getProfile()
    {
        $company = auth()->user();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $company->id,
                'name' => $company->name,
                'code' => $company->code,
                'contact_email' => $company->contact_email,
                'contact_phone' => $company->contact_phone,
                'admin_email' => $company->admin_email,
                'service_areas' => $company->service_areas,
                'is_active' => $company->is_active,
            ],
        ]);
    }

    public function inviteAgent(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'phone' => 'required|string',
            'name' => 'required|string',
            'service_areas' => 'nullable|array',
            'service_areas.*.state' => 'required_with:service_areas|string',
            'service_areas.*.cities' => 'nullable|array',
        ]);

        $company = auth()->user();

        // Check if agent with this email already exists
        $existingAgent = \App\Models\DeliveryAgent::where('email', $request->email)->first();
        if ($existingAgent) {
            return response()->json([
                'success' => false,
                'message' => 'An agent with this email already exists.',
            ], 422);
        }

        // Generate a random default password
        $defaultPassword = \Illuminate\Support\Str::random(8);

        // Create the delivery agent account
        $agent = \App\Models\DeliveryAgent::create([
            'logistics_company_id' => $company->id,
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => \Illuminate\Support\Facades\Hash::make($defaultPassword),
            'service_areas' => $request->service_areas ?? [],
            'status' => 'available',
            'is_verified' => true,
            'verified_at' => now(),
        ]);

        // Create the invitation record (for tracking)
        $invitation = $company->inviteAgent($request->email, $request->phone, $request->name);
        $invitation->accept($agent);

        // Send invitation email with credentials
        $loginUrl = \App\Support\AppUrl::agentPortal('/login');

        try {
            \Illuminate\Support\Facades\Mail::to($request->email)->send(
                new \App\Mail\AgentInvitationEmail(
                    $request->name,
                    $request->email,
                    $defaultPassword,
                    $company->name,
                    $loginUrl
                )
            );
        } catch (\Exception $e) {
            \Log::error('Failed to send agent invitation email: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Agent account created and invitation email sent successfully.',
            'data' => $invitation,
        ]);
    }

    public function getAgents()
    {
        $company = auth()->user();
        $agents = $company->deliveryAgents()->with('earnings')->paginate(20);
        return response()->json(['success' => true, 'data' => $agents]);
    }

    /**
     * Get agents filtered by coverage area (for assignment to a specific shipment)
     * Logic: 
     * - For pickup (pending/new): Show agents covering STORE location (origin)
     * - For delivery (arrived_at_hub/in_transit): Show agents covering CUSTOMER location (destination)
     */
    public function getAgentsForAssignment(Request $request, $shipmentId)
    {
        $company = auth()->user();

        $shipment = \App\Models\OrderShipment::where(function($q) use ($company) {
            $q->where('logistics_company_id', $company->id);
        })->findOrFail($shipmentId);

        $order = $shipment->order;
        
        // Determine which location to filter by based on shipment status
        $isPickupPhase = in_array($shipment->status, ['pending', 'assigned_to_agent']);
        $isDeliveryPhase = in_array($shipment->status, ['arrived_at_hub', 'in_transit', 'out_for_delivery']);
        
        if ($isPickupPhase) {
            // Agent needs to cover the STORE location (where they pick up from)
            $storeState = null;
            $storeCity = null;
            
            /*
             * This parcel's own pharmacy, not the order's.
             *
             * On an order split between two pharmacies the order-level store is
             * only ever one of them, so assigning a rider to the second parcel
             * listed riders who cover the first pharmacy's city — the wrong
             * pickup address, silently marked as covered.
             */
            if ($shipment->store) {
                $storeState = $shipment->store->state;
                $storeCity = $shipment->store->city;
            } elseif ($order->store) {
                $storeState = $order->store->state;
                $storeCity = $order->store->city;
            } else {
                // Fallback: get store from first order item
                $firstItem = $order->items()->with('product.store')->first();
                if ($firstItem && $firstItem->product && $firstItem->product->store) {
                    $storeState = $firstItem->product->store->state;
                    $storeCity = $firstItem->product->store->city;
                }
            }
            
            $targetState = $storeState;
            $targetCity = $storeCity;
            $phase = 'pickup';
        } else {
            // Agent needs to cover the CUSTOMER delivery location
            $targetState = $order->shipping_address['state'] ?? null;
            $targetCity = $order->shipping_address['city'] ?? null;
            $phase = 'delivery';
        }

        $allAgents = $company->deliveryAgents()->get();

        // Separate agents into matching and non-matching
        $matching = [];
        $others = [];
        foreach ($allAgents as $agent) {
            $agentData = $agent->toArray();
            if ($targetState && $agent->coversArea($targetState, $targetCity)) {
                $agentData['covers_target_area'] = true;
                $matching[] = $agentData;
            } else {
                $agentData['covers_target_area'] = false;
                $others[] = $agentData;
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'matching_agents' => $matching,
                'other_agents' => $others,
                'target_state' => $targetState,
                'target_city' => $targetCity,
                'phase' => $phase,
                'shipment_status' => $shipment->status,
            ]
        ]);
    }

    /**
     * Update an agent's details (service areas, phone, etc.)
     */
    public function updateAgent(Request $request, $id)
    {
        $request->validate([
            'name' => 'nullable|string',
            'phone' => 'nullable|string',
            'service_areas' => 'nullable|array',
            'service_areas.*.state' => 'required_with:service_areas|string',
            'service_areas.*.cities' => 'nullable|array',
        ]);

        $company = auth()->user();
        $agent = $company->deliveryAgents()->findOrFail($id);

        $updateData = [];
        if ($request->has('name')) $updateData['name'] = $request->name;
        if ($request->has('phone')) $updateData['phone'] = $request->phone;
        if ($request->has('service_areas')) $updateData['service_areas'] = $request->service_areas;

        $agent->update($updateData);

        return response()->json([
            'success' => true,
            'message' => 'Agent updated successfully',
            'data' => $agent->fresh(),
        ]);
    }

    public function getInvitations()
    {
        $company = auth()->user();
        $invitations = $company->invitations()->latest()->paginate(20);
        return response()->json(['success' => true, 'data' => $invitations]);
    }

    /**
     * Update agent status (activate/deactivate)
     */
    public function updateAgentStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:available,offline,suspended',
        ]);

        $company = auth()->user();
        $agent = $company->deliveryAgents()->findOrFail($id);

        $agent->update(['status' => $request->status]);

        return response()->json([
            'success' => true,
            'message' => 'Agent status updated successfully',
            'data' => $agent->fresh(),
        ]);
    }

    /**
     * Delete/remove an agent from this logistics company
     */
    public function deleteAgent($id)
    {
        $company = auth()->user();
        $agent = $company->deliveryAgents()->findOrFail($id);

        // Check if agent has any active (non-delivered, non-cancelled) shipments
        $activeShipments = \App\Models\OrderShipment::where('delivery_agent_id', $agent->id)
            ->whereNotIn('status', ['delivered', 'cancelled'])
            ->count();

        if ($activeShipments > 0) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete agent with {$activeShipments} active delivery(ies). Complete or reassign them first.",
            ], 422);
        }

        $agent->delete();

        return response()->json([
            'success' => true,
            'message' => 'Agent has been removed successfully.',
        ]);
    }

    /**
     * Get all orders/shipments assigned to this logistics company
     */
    public function getOrders(Request $request)
    {
        $company = auth()->user();
        $status = $request->query('status');

        $query = \App\Models\OrderShipment::where(function($q) use ($company) {
            $q->where('logistics_company_id', $company->id)
              ->orWhereHas('deliveryAgent', function($q2) use ($company) {
                  $q2->where('logistics_company_id', $company->id);
              });
        })->with(['order.user', 'deliveryAgent']);

        if ($status && $status !== 'all') {
            if ($status === 'active') {
                $query->whereNotIn('status', ['delivered', 'cancelled']);
            } else {
                $query->where('status', $status);
            }
        }

        $shipments = $query->latest()->paginate(20);

        $shipments->getCollection()->transform(function($shipment) {
            $shippingAddr = $shipment->order->shipping_address ?? [];
            return [
                'id' => $shipment->id,
                'order_id' => $shipment->order_id,
                'order_number' => $shipment->order->order_number ?? 'N/A',
                'tracking_number' => $shipment->tracking_number,
                'customer_name' => $shipment->order->customer_name ?? 'N/A',
                'customer_phone' => $shippingAddr['phone'] ?? 'N/A',
                'delivery_address' => implode(', ', array_filter([
                    $shippingAddr['address'] ?? '',
                    $shippingAddr['city'] ?? '',
                    $shippingAddr['state'] ?? '',
                ])) ?: 'N/A',
                'delivery_city' => $shippingAddr['city'] ?? 'N/A',
                'delivery_state' => $shippingAddr['state'] ?? 'N/A',
                'status' => $shipment->status,
                'agent_name' => $shipment->deliveryAgent->name ?? 'Unassigned',
                'agent_id' => $shipment->delivery_agent_id,
                'shipping_fee' => $shipment->shipping_fee,
                'items' => $shipment->items,
                'order_total' => $shipment->order->total_amount ?? 0,
                'assigned_at' => $shipment->assigned_at?->format('Y-m-d H:i'),
                'picked_up_at' => $shipment->picked_up_at?->format('Y-m-d H:i'),
                'in_transit_at' => $shipment->in_transit_at?->format('Y-m-d H:i'),
                'out_for_delivery_at' => $shipment->out_for_delivery_at?->format('Y-m-d H:i'),
                'delivered_at' => $shipment->delivered_at?->format('Y-m-d H:i'),
                'created_at' => $shipment->created_at->format('Y-m-d H:i'),
            ];
        });

        return response()->json(['success' => true, 'data' => $shipments]);
    }

    /**
     * Get single order/shipment detail
     */
    public function getOrderDetail($shipmentId)
    {
        $company = auth()->user();

        $shipment = \App\Models\OrderShipment::where(function($q) use ($company) {
            $q->where('logistics_company_id', $company->id)
              ->orWhereHas('deliveryAgent', function($q2) use ($company) {
                  $q2->where('logistics_company_id', $company->id);
              });
        })->with(['order.user', 'order.items', 'order.trackingEvents', 'order.store', 'deliveryAgent'])
          ->findOrFail($shipmentId);

        $shippingAddr = $shipment->order->shipping_address ?? [];

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $shipment->id,
                'order_id' => $shipment->order_id,
                'order_number' => $shipment->order->order_number ?? 'N/A',
                'tracking_number' => $shipment->tracking_number,
                'status' => $shipment->status,
                'shipping_fee' => $shipment->shipping_fee,
                'items' => $shipment->items,
                'order_total' => $shipment->order->total_amount ?? 0,
                'payment_status' => $shipment->order->payment_status ?? 'N/A',
                'customer' => [
                    'name' => $shipment->order->customer_name ?? 'N/A',
                    'email' => $shipment->order->user?->email ?? ($shippingAddr['email'] ?? null),
                    'phone' => $shippingAddr['phone'] ?? 'N/A',
                ],
                'delivery_address' => [
                    'address' => $shippingAddr['address'] ?? '',
                    'city' => $shippingAddr['city'] ?? '',
                    'state' => $shippingAddr['state'] ?? '',
                    'postal_code' => $shippingAddr['postalCode'] ?? '',
                ],
                'store' => $shipment->order->store ? [
                    'id' => $shipment->order->store->id,
                    'name' => $shipment->order->store->name,
                    'phone' => $shipment->order->store->phone,
                    'email' => $shipment->order->store->email,
                    'address' => $shipment->order->store->address,
                    'city' => $shipment->order->store->city,
                    'state' => $shipment->order->store->state,
                ] : null,
                'agent' => $shipment->deliveryAgent ? [
                    'id' => $shipment->deliveryAgent->id,
                    'name' => $shipment->deliveryAgent->name,
                    'phone' => $shipment->deliveryAgent->phone,
                ] : null,
                'timeline' => [
                    'assigned_at' => $shipment->assigned_at?->format('Y-m-d H:i:s'),
                    'picked_up_at' => $shipment->picked_up_at?->format('Y-m-d H:i:s'),
                    'arrived_at_hub_at' => $shipment->arrived_at_hub_at?->format('Y-m-d H:i:s'),
                    'in_transit_at' => $shipment->in_transit_at?->format('Y-m-d H:i:s'),
                    'out_for_delivery_at' => $shipment->out_for_delivery_at?->format('Y-m-d H:i:s'),
                    'delivered_at' => $shipment->delivered_at?->format('Y-m-d H:i:s'),
                ],
                'tracking_events' => $shipment->order->trackingEvents->map(function($event) {
                    return [
                        'id' => $event->id,
                        'status' => $event->status,
                        'description' => $event->description,
                        'created_at' => $event->created_at->format('Y-m-d H:i:s'),
                    ];
                }),
                'order_items' => $shipment->order->items->map(function($item) {
                    return [
                        'name' => $item->product_snapshot['name'] ?? 'Product',
                        'quantity' => $item->quantity,
                        'price' => $item->price,
                        'total' => $item->quantity * $item->price,
                    ];
                }),
                'created_at' => $shipment->created_at->format('Y-m-d H:i:s'),
            ]
        ]);
    }

    /**
     * Update delivery status for a shipment (logistics company managing their delivery)
     */
    public function updateShipmentStatus(Request $request, $shipmentId)
    {
        $request->validate([
            'status' => 'required|in:picked_up,arrived_at_hub,in_transit,out_for_delivery,delivered',
            'notes' => 'nullable|string',
            'delivery_code' => 'required_if:status,delivered|nullable|string',
        ]);

        $company = auth()->user();

        $shipment = \App\Models\OrderShipment::where(function($q) use ($company) {
            $q->where('logistics_company_id', $company->id)
              ->orWhereHas('deliveryAgent', function($q2) use ($company) {
                  $q2->where('logistics_company_id', $company->id);
              });
        })->findOrFail($shipmentId);

        $order = $shipment->order;

        /*
         * The code belongs to the parcel, not the order — see
         * OrderShipment::verifyDeliveryCode(). On a split order the customer
         * holds one code per parcel and each releases only its own.
         */
        if ($request->status === 'delivered' && ! $shipment->verifyDeliveryCode($request->delivery_code)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid delivery confirmation code. Please ask the customer for the code for this parcel.',
            ], 422);
        }

        /*
         * Refuse before touching the shipment. Advancing it first and letting
         * the order's dispatch gate throw afterwards left the company's
         * dashboard showing a parcel in transit against an order still held for
         * a pharmacist. Same reasoning as DeliveryAgentController.
         */
        if ($order
            && in_array($request->status, \App\Models\Order::DISPATCH_STATUSES, true)
            && ! $order->isClearedForDispatch()) {
            return response()->json([
                'success' => false,
                'message' => 'This order contains prescription medicine that has not been approved. '
                    ."Prescription status: {$order->prescription_status}.",
                'code' => 'prescription_not_cleared',
            ], 422);
        }

        \DB::beginTransaction();

        // Update shipment status
        $shipment->updateStatus($request->status, [
            'updated_by' => 'logistics_company',
            'company_id' => $company->id,
            'notes' => $request->notes,
        ]);

        // Also update the parent order status and timestamps
        $oldStatus = $order ? $order->status : null;

        /*
         * The order is only as far along as its slowest parcel. See
         * Order::allShipmentsReached() — for the single-parcel orders that are
         * most of them this is true immediately and nothing changes.
         */
        $orderReached = $order && $order->allShipmentsReached($request->status);

        if ($order) {
            switch ($request->status) {
                case 'picked_up':
                    if ($orderReached) {
                        $order->update([
                            'status' => 'picked_up',
                            'picked_up_at' => now(),
                        ]);
                    }
                    break;
                case 'arrived_at_hub':
                    if ($orderReached) {
                        $order->update(['status' => 'arrived_at_hub']);
                    }
                    // Unassign agent so a new one can be assigned for final leg
                    if ($shipment->delivery_agent_id) {
                        $agent = \App\Models\DeliveryAgent::find($shipment->delivery_agent_id);
                        $shipment->update(['delivery_agent_id' => null]);
                        $order->update(['delivery_agent_id' => null]);
                        // Mark pickup agent as available again
                        if ($agent) {
                            $activeCount = $agent->shipments()->whereNotIn('status', ['delivered', 'cancelled'])->where('id', '!=', $shipment->id)->count();
                            if ($activeCount === 0) {
                                $agent->update(['status' => 'available']);
                            }
                        }
                    }
                    break;
                case 'in_transit':
                    // Company transports package between states — no agent involved
                    if ($orderReached) {
                        $order->update(['status' => 'in_transit', 'shipped_at' => $order->shipped_at ?? now()]);
                    }
                    break;
                case 'out_for_delivery':
                    if ($orderReached) {
                        $order->update([
                            'status' => 'out_for_delivery',
                            'out_for_delivery_at' => now(),
                        ]);
                    }
                    break;
                case 'delivered':
                    // Credited against the parcel, and so always: this courier
                    // carried this leg whether or not the rest of the order has
                    // moved. One service owns the calculation, decides who the
                    // payee is and refuses to pay twice for the same journey.
                    app(\App\Services\DeliveryEarningsService::class)
                        ->creditForDelivery($order->fresh(), $shipment);

                    if ($orderReached) {
                        $order->update([
                            'status' => 'delivered',
                            'delivered_at' => now(),
                            'delivery_notes' => $request->notes,
                        ]);
                    }
                    break;
            }

            // Send email notifications after response (non-blocking)
            // The column is `name` — `company_name` has never existed, so every
            // email built from this went out with a blank sender name.
            $companyName = $company->name;
            $statusValue = $request->status;
            
            // A terminating callback, not a shutdown function -- see the note on
            // the same deferral in DeliveryAgentController::updateDeliveryStatus.
            app()->terminating(function () use ($order, $oldStatus, $statusValue, $companyName) {
                // Send customer notification via OrderNotificationService
                try {
                    $notificationService = new \App\Services\OrderNotificationService();
                    $notificationService->notifyStatusUpdate($order->fresh(), $oldStatus, $statusValue);
                    \Log::info('Status notification email sent from logistics', ['order_id' => $order->id, 'status' => $statusValue]);
                } catch (\Throwable $e) {
                    \Log::error("Failed to send status notification from logistics: " . $e->getMessage());
                }

                // Send tracking update emails
                try {
                    $freshOrder = $order->fresh(['logisticsCompany', 'deliveryAgent', 'user']);
                    $statusLabels = [
                        'picked_up' => 'Picked Up',
                        'arrived_at_hub' => 'Arrived at Hub',
                        'in_transit' => 'In Transit',
                        'out_for_delivery' => 'Out for Delivery',
                        'delivered' => 'Delivered',
                    ];
                    $statusDescriptions = [
                        'picked_up' => 'The package has been picked up by ' . $companyName . '.',
                        'arrived_at_hub' => 'The package has arrived at the logistics hub and is being prepared for the next stage of delivery.',
                        'in_transit' => 'The package is in transit between states with ' . $companyName . '. It will be delivered once it arrives at the destination branch.',
                        'out_for_delivery' => 'The package is out for delivery and should arrive soon.',
                        'delivered' => 'The package has been successfully delivered.',
                    ];
                    $label = $statusLabels[$statusValue] ?? $statusValue;
                    $desc = $statusDescriptions[$statusValue] ?? 'Delivery status updated.';

                    // Notify customer
                    $customerEmail = $freshOrder->user?->email ?? ($freshOrder->shipping_address['email'] ?? null);
                    if ($customerEmail) {
                        \Illuminate\Support\Facades\Mail::to($customerEmail)->send(
                            new \App\Mail\DeliveryTrackingUpdateEmail(
                                $freshOrder, $label, $desc,
                                $freshOrder->user?->name ?? ($freshOrder->shipping_address['firstName'] ?? 'Customer'),
                                'customer'
                            )
                        );
                        \Log::info('Tracking update email sent', ['order_id' => $order->id, 'email' => $customerEmail]);
                    }
                } catch (\Throwable $e) {
                    \Log::error('Failed to send tracking update email from logistics: ' . $e->getMessage());
                }
            });
        }

        \DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Delivery status updated to ' . $request->status,
            'data' => $shipment->fresh()->load('order')
        ]);
    }

    /**
     * Assign one of the company's agents to a shipment
     */
    public function assignAgentToShipment(Request $request, $shipmentId)
    {
        $request->validate([
            'agent_id' => 'required|integer',
        ]);

        $company = auth()->user();

        $shipment = \App\Models\OrderShipment::where(function($q) use ($company) {
            $q->where('logistics_company_id', $company->id);
        })->findOrFail($shipmentId);

        // Verify agent belongs to this company
        $agent = \App\Models\DeliveryAgent::where('id', $request->agent_id)
            ->where('logistics_company_id', $company->id)
            ->firstOrFail();

        // Check if this is a reassignment (interstate: after arrived_at_hub/in_transit)
        $isReassignment = in_array($shipment->status, ['arrived_at_hub', 'in_transit']);

        if ($isReassignment) {
            // Don't reset shipment status — just assign the agent
            $shipment->update([
                'delivery_agent_id' => $agent->id,
                'logistics_company_id' => $agent->logistics_company_id,
            ]);
            // Log the reassignment as a tracking event
            \App\Models\DeliveryTrackingEvent::create([
                'order_id' => $shipment->order_id,
                'status' => $shipment->status,
                'description' => 'Delivery agent ' . $agent->name . ' assigned for final-mile delivery',
                'metadata' => ['shipment_id' => $shipment->id, 'agent_id' => $agent->id, 'reassignment' => true],
                'created_by_type' => 'system',
            ]);
        } else {
            $shipment->assignAgent($agent);
        }

        /*
         * The order carries a single delivery_agent_id, so it can only name the
         * rider when there is one parcel to name them for. On a split order
         * each assignment overwrote the last, leaving the order pointing at
         * whichever rider was assigned most recently — a courier who is
         * carrying one of the parcels and has nothing to do with the others.
         * The parcel is the record; the order stays blank rather than wrong.
         */
        if ($shipment->order && $shipment->order->shipments()->count() === 1) {
            $shipment->order->update(['delivery_agent_id' => $agent->id]);
        }

        // Send email to agent
        try {
            if ($agent->email) {
                \Illuminate\Support\Facades\Mail::to($agent->email)->send(
                    new \App\Mail\DeliveryAssignmentEmail(
                        $shipment->order->load('items'), 'agent', $agent->name, $shipment->tracking_number
                    )
                );
            }
        } catch (\Exception $e) {
            \Log::error('Failed to send agent assignment email: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Agent ' . $agent->name . ' assigned to shipment',
            'data' => $shipment->fresh(['deliveryAgent'])
        ]);
    }

    /**
     * Get financial overview for the logistics company
     */
    public function getFinancials()
    {
        $company = auth()->user();

        // Get earnings breakdown
        $earnings = \App\Models\AgentEarning::where('logistics_company_id', $company->id);
        $totalEarnings = (clone $earnings)->sum('agent_commission');
        $availableEarnings = (clone $earnings)->where('status', 'available')->sum('agent_commission');
        $paidOutEarnings = (clone $earnings)->where('status', 'paid_out')->sum('agent_commission');

        // Get payout stats
        $totalPayoutsRequested = \App\Models\AgentPayout::where('logistics_company_id', $company->id)->sum('amount');
        $pendingPayouts = \App\Models\AgentPayout::where('logistics_company_id', $company->id)
            ->whereIn('status', ['pending', 'approved', 'processing'])->sum('amount');
        $completedPayouts = \App\Models\AgentPayout::where('logistics_company_id', $company->id)
            ->where('status', 'completed')->sum('amount');

        // Monthly earnings (last 6 months)
        $monthlyEarnings = \App\Models\AgentEarning::where('logistics_company_id', $company->id)
            ->where('created_at', '>=', now()->subMonths(6))
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, SUM(agent_commission) as total, COUNT(*) as deliveries")
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        // Recent earnings
        $recentEarnings = \App\Models\AgentEarning::where('logistics_company_id', $company->id)
            // The parcel too: on an order split between pharmacies there are
            // two earnings under one order number, and without the tracking
            // number they are indistinguishable in the company's ledger.
            ->with(['order:id,order_number', 'shipment:id,tracking_number'])
            ->latest()
            ->take(20)
            ->get()
            ->map(function($earning) {
                return [
                    'id' => $earning->id,
                    'order_number' => $earning->order->order_number ?? 'N/A',
                    'tracking_number' => $earning->shipment->tracking_number ?? null,
                    'delivery_fee' => $earning->delivery_fee,
                    'commission' => $earning->agent_commission,
                    'platform_fee' => $earning->platform_commission,
                    'status' => $earning->status,
                    'date' => $earning->created_at->format('M d, Y H:i'),
                ];
            });

        // Delivery stats
        $totalDelivered = \App\Models\OrderShipment::where(function($q) use ($company) {
            $q->where('logistics_company_id', $company->id)
              ->orWhereHas('deliveryAgent', function($q2) use ($company) {
                  $q2->where('logistics_company_id', $company->id);
              });
        })->where('status', 'delivered')->count();

        $minimumPayout = \App\Models\DeliverySetting::getValue('minimum_payout_amount', 5000);

        return response()->json([
            'success' => true,
            'data' => [
                'balance' => [
                    'available' => (float) $company->available_balance,
                    'pending' => (float) $company->pending_balance,
                    'total_earned' => (float) $company->total_earned,
                    'total_paid_out' => (float) ($company->total_paid_out ?? 0),
                ],
                'earnings_summary' => [
                    'total' => (float) $totalEarnings,
                    'available' => (float) $availableEarnings,
                    'paid_out' => (float) $paidOutEarnings,
                ],
                'payout_summary' => [
                    'total_requested' => (float) $totalPayoutsRequested,
                    'pending' => (float) $pendingPayouts,
                    'completed' => (float) $completedPayouts,
                ],
                'monthly_earnings' => $monthlyEarnings,
                'recent_earnings' => $recentEarnings,
                'total_delivered' => $totalDelivered,
                'commission_percentage' => \App\Models\DeliverySetting::getValue('enable_commission_system', false)
                    ? (float) \App\Models\DeliverySetting::getValue('logistics_company_commission_percentage', 100)
                    : 100,
                'minimum_payout' => (float) $minimumPayout,
                'can_request_payout' => $company->available_balance >= $minimumPayout,
            ]
        ]);
    }

    /**
     * Request a payout
     */
    public function requestPayout(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'bank_name' => 'nullable|string',
            'account_number' => 'nullable|string',
            'account_name' => 'nullable|string',
        ]);

        $company = auth()->user();
        $amount = $request->amount;

        $minimumPayout = \App\Models\DeliverySetting::getValue('minimum_payout_amount', 5000);

        if ($amount < $minimumPayout) {
            return response()->json([
                'success' => false,
                'message' => "Minimum payout amount is ₦" . number_format($minimumPayout),
            ], 422);
        }

        if ($amount > $company->available_balance) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient balance. Available: ₦' . number_format($company->available_balance, 2),
            ], 422);
        }

        // Check for existing pending payout
        $existingPending = \App\Models\AgentPayout::where('logistics_company_id', $company->id)
            ->whereIn('status', ['pending', 'approved', 'processing'])
            ->exists();

        if ($existingPending) {
            return response()->json([
                'success' => false,
                'message' => 'You already have a pending payout request. Please wait for it to be processed.',
            ], 422);
        }

        $bankDetails = [
            'bank_name' => $request->bank_name ?? $company->bank_name,
            'account_number' => $request->account_number ?? $company->account_number,
            'account_name' => $request->account_name ?? $company->account_name,
        ];

        $payout = \App\Models\AgentPayout::create([
            'logistics_company_id' => $company->id,
            'payout_type' => 'logistics_company',
            'amount' => $amount,
            'status' => 'pending',
            'bank_details' => $bankDetails,
        ]);

        // Hold the amount out of the available balance so it cannot be requested
        // twice. It is NOT added to pending_balance: that column means "earned
        // but still inside the hold period", and the scheduled job that releases
        // held earnings decrements it — so parking a requested payout there let
        // that job eat into money already claimed.
        $company->decrement('available_balance', $amount);

        // Through AdminAlerts rather than a local lookup — see the note on the
        // store-side request for why one message per administrator beats one
        // message addressed to all of them.
        \App\Services\AdminAlerts::payoutRequested(
            $payout,
            'logistics_company',
            $company->name,
            $company->admin_email
        );

        return response()->json([
            'success' => true,
            'message' => 'Payout request of ₦' . number_format($amount, 2) . ' submitted successfully',
            'data' => $payout,
        ]);
    }

    /**
     * Get payout history
     */
    public function getPayoutHistory(Request $request)
    {
        $company = auth()->user();
        $status = $request->query('status');

        $query = \App\Models\AgentPayout::where('logistics_company_id', $company->id)
            ->latest();

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        $payouts = $query->paginate(20);

        $payouts->getCollection()->transform(function($payout) {
            return [
                'id' => $payout->id,
                'amount' => (float) $payout->amount,
                'status' => $payout->status,
                'bank_details' => $payout->bank_details,
                'reference_number' => $payout->reference_number,
                'notes' => $payout->notes,
                'requested_at' => $payout->created_at->format('M d, Y H:i'),
                'approved_at' => $payout->approved_at ? $payout->approved_at->format('M d, Y H:i') : null,
                'completed_at' => $payout->completed_at ? $payout->completed_at->format('M d, Y H:i') : null,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $payouts,
        ]);
    }

    /**
     * Get shipping rates applicable to this company (company-specific + global fallback)
     */
    public function getShippingRates()
    {
        $company = auth()->user();

        // Get company-specific rates
        $companyRates = \App\Models\ShippingRate::active()
            ->forCompany($company->id)
            ->orderBy('from_state')
            ->orderBy('to_state')
            ->get();

        // Get global rates (for routes not covered by company-specific rates)
        $globalRates = \App\Models\ShippingRate::active()
            ->global()
            ->orderBy('from_state')
            ->orderBy('to_state')
            ->get();

        // Build a map of company-covered routes
        $companyRoutes = $companyRates->map(fn($r) => $r->from_state . '->' . $r->to_state)->toArray();

        // Only include global rates for routes not covered by company-specific ones
        $fallbackRates = $globalRates->filter(function ($rate) use ($companyRoutes) {
            return !in_array($rate->from_state . '->' . $rate->to_state, $companyRoutes);
        });

        $formatted = $companyRates->map(fn($r) => [
            'id' => $r->id,
            'from_state' => $r->from_state,
            'to_state' => $r->to_state,
            'base_rate' => (float) $r->base_rate,
            'estimated_days_min' => $r->estimated_days_min,
            'estimated_days_max' => $r->estimated_days_max,
            'is_interstate' => $r->is_interstate,
            'type' => 'company',
        ])->merge($fallbackRates->map(fn($r) => [
            'id' => $r->id,
            'from_state' => $r->from_state,
            'to_state' => $r->to_state,
            'base_rate' => (float) $r->base_rate,
            'estimated_days_min' => $r->estimated_days_min,
            'estimated_days_max' => $r->estimated_days_max,
            'is_interstate' => $r->is_interstate,
            'type' => 'global',
        ]))->sortBy('from_state')->values();

        return response()->json([
            'success' => true,
            'data' => $formatted,
        ]);
    }
}
