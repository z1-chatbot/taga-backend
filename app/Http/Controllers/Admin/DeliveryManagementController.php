<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LogisticsCompany;
use App\Models\DeliveryAgent;
use App\Models\Order;
use App\Notifications\DeliveryAssignedNotification;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class DeliveryManagementController extends Controller
{
    /**
     * Get all logistics companies
     */
    public function getLogisticsCompanies(Request $request): JsonResponse
    {
        $query = LogisticsCompany::query();

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $companies = $query->withCount('deliveryAgents')->get();

        return response()->json([
            'success' => true,
            'data' => $companies
        ]);
    }

    /**
     * Create logistics company
     */
    public function createLogisticsCompany(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:logistics_companies,code',
            'description' => 'nullable|string',
            'contact_email' => 'nullable|email',
            'contact_phone' => 'nullable|string',
            'service_areas' => 'nullable|array',
            'pricing_structure' => 'nullable|array',
            'logo' => 'nullable|image|max:2048'
        ]);

        if ($request->hasFile('logo')) {
            $validated['logo'] = $request->file('logo')->store('logistics/logos', 'public');
        }

        // Generate a random default password
        $defaultPassword = \Illuminate\Support\Str::random(10);
        $validated['admin_email'] = $validated['contact_email'] ?? null;
        $validated['admin_password'] = \Illuminate\Support\Facades\Hash::make($defaultPassword);

        $company = LogisticsCompany::create($validated);

        // Send welcome email
        if ($company->admin_email) {
            $loginUrl = \App\Support\AppUrl::logisticsPortal('/login');
            try {
                \Illuminate\Support\Facades\Mail::to($company->admin_email)->send(
                    new \App\Mail\LogisticsCompanyWelcomeEmail($company->name, $company->admin_email, $defaultPassword, $loginUrl)
                );
            } catch (\Exception $e) {
                \Log::error('Failed to send logistics company welcome email: ' . $e->getMessage());
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Logistics company created and welcome email sent.',
            'data' => $company
        ], 201);
    }

    /**
     * Update logistics company
     */
    public function updateLogisticsCompany(Request $request, $id): JsonResponse
    {
        $company = LogisticsCompany::find($id);

        if (!$company) {
            return response()->json([
                'success' => false,
                'message' => 'Logistics company not found'
            ], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'contact_email' => 'nullable|email',
            'contact_phone' => 'nullable|string',
            'service_areas' => 'nullable|array',
            'pricing_structure' => 'nullable|array',
            'is_active' => 'boolean'
        ]);

        $company->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Logistics company updated successfully',
            'data' => $company
        ]);
    }

    /**
     * Get all delivery agents
     */
    public function getDeliveryAgents(Request $request): JsonResponse
    {
        $query = DeliveryAgent::with('logisticsCompany');

        if ($request->has('logistics_company_id')) {
            $query->where('logistics_company_id', $request->logistics_company_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $agents = $query->get();

        return response()->json([
            'success' => true,
            'data' => $agents
        ]);
    }

    /**
     * Create delivery agent
     */
    public function createDeliveryAgent(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'logistics_company_id' => 'nullable|exists:logistics_companies,id',
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:delivery_agents,email',
            'phone' => 'required|string',
            'vehicle_type' => 'nullable|string',
            'vehicle_number' => 'nullable|string',
            'license_number' => 'nullable|string',
            'service_areas' => 'nullable|array',
            'status' => 'nullable|in:available,busy,offline,suspended'
        ]);

        // Set default status to available if not provided
        if (!isset($validated['status'])) {
            $validated['status'] = 'available';
        }

        // Generate a random default password
        $defaultPassword = \Illuminate\Support\Str::random(8);
        $validated['password'] = \Illuminate\Support\Facades\Hash::make($defaultPassword);
        $validated['is_verified'] = true;
        $validated['verified_at'] = now();

        $agent = DeliveryAgent::create($validated);

        // Send welcome email
        $loginUrl = \App\Support\AppUrl::agentPortal('/login');
        $companyName = $agent->logisticsCompany ? $agent->logisticsCompany->name : 'Taga';
        try {
            \Illuminate\Support\Facades\Mail::to($agent->email)->send(
                new \App\Mail\AgentInvitationEmail($agent->name, $agent->email, $defaultPassword, $companyName, $loginUrl)
            );
        } catch (\Exception $e) {
            \Log::error('Failed to send delivery agent welcome email: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Delivery agent created and welcome email sent.',
            'data' => $agent->fresh()
        ], 201);
    }

    /**
     * Update delivery agent
     */
    public function updateDeliveryAgent(Request $request, $id): JsonResponse
    {
        $agent = DeliveryAgent::find($id);

        if (!$agent) {
            return response()->json([
                'success' => false,
                'message' => 'Delivery agent not found'
            ], 404);
        }

        $validated = $request->validate([
            'logistics_company_id' => 'nullable|exists:logistics_companies,id',
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:delivery_agents,email,' . $id,
            'phone' => 'sometimes|string',
            'vehicle_type' => 'nullable|string',
            'vehicle_number' => 'nullable|string',
            'license_number' => 'nullable|string',
            'service_areas' => 'nullable|array',
            'status' => 'sometimes|in:available,busy,offline,suspended'
        ]);

        $agent->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Delivery agent updated successfully',
            'data' => $agent
        ]);
    }

    /**
     * Assign order to delivery agent
     */
    public function assignOrder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => 'required|exists:orders,id',
            'delivery_agent_id' => 'required|exists:delivery_agents,id'
        ]);

        $order = Order::find($validated['order_id']);
        $agent = DeliveryAgent::find($validated['delivery_agent_id']);

        if ($order->delivery_agent_id) {
            return response()->json([
                'success' => false,
                'message' => 'Order already assigned to a delivery agent'
            ], 422);
        }

        $order->assignToDeliveryAgent($agent);

        // Send notification to delivery agent
        try {
            $agent->notify(new DeliveryAssignedNotification($order));
        } catch (\Exception $e) {
            \Log::error('Failed to send delivery notification: ' . $e->getMessage());
            // Continue even if notification fails
        }

        return response()->json([
            'success' => true,
            'message' => 'Order assigned to delivery agent successfully. Notification sent to agent.',
            'data' => $order->fresh(['deliveryAgent', 'logisticsCompany'])
        ]);
    }

    /**
     * Toggle delivery agent status
     */
    public function toggleAgentStatus($id): JsonResponse
    {
        $agent = DeliveryAgent::find($id);

        if (!$agent) {
            return response()->json([
                'success' => false,
                'message' => 'Delivery agent not found'
            ], 404);
        }

        // Toggle between available and offline
        $agent->status = ($agent->status === 'available') ? 'offline' : 'available';
        $agent->save();

        return response()->json([
            'success' => true,
            'message' => 'Agent status updated successfully',
            'data' => $agent
        ]);
    }

    /**
     * Delete delivery agent
     */
    public function deleteDeliveryAgent($id): JsonResponse
    {
        $agent = DeliveryAgent::find($id);

        if (!$agent) {
            return response()->json([
                'success' => false,
                'message' => 'Delivery agent not found'
            ], 404);
        }

        // Check if agent has active deliveries
        $activeDeliveries = Order::where('delivery_agent_id', $id)
            ->whereIn('status', ['processing', 'shipped', 'out_for_delivery'])
            ->count();

        if ($activeDeliveries > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete agent with active deliveries'
            ], 422);
        }

        $agent->delete();

        return response()->json([
            'success' => true,
            'message' => 'Delivery agent deleted successfully'
        ]);
    }

    /**
     * Get available agents for order
     */
    public function getAvailableAgents($orderId): JsonResponse
    {
        $order = Order::find($orderId);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found'
            ], 404);
        }

        $shippingAddress = $order->shipping_address;
        $state = $shippingAddress['state'] ?? null;
        $city = $shippingAddress['city'] ?? null;

        if (!$state) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid shipping address'
            ], 422);
        }

        $agents = DeliveryAgent::available()
            ->get()
            ->filter(function ($agent) use ($state, $city) {
                return $agent->coversArea($state, $city);
            });

        return response()->json([
            'success' => true,
            'data' => $agents->values()
        ]);
    }

    /**
     * Update delivery status
     */
    public function updateDeliveryStatus(Request $request, $orderId): JsonResponse
    {
        $order = Order::find($orderId);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found'
            ], 404);
        }

        $validated = $request->validate([
            'status' => 'required|in:picked_up,out_for_delivery,delivered',
            'notes' => 'nullable|string'
        ]);

        switch ($validated['status']) {
            case 'picked_up':
                $order->markAsPickedUp();
                break;
            case 'out_for_delivery':
                $order->markAsOutForDelivery();
                break;
            case 'delivered':
                $order->markAsDelivered($validated['notes'] ?? null);
                break;
        }

        return response()->json([
            'success' => true,
            'message' => 'Delivery status updated successfully',
            'data' => $order->fresh()
        ]);
    }

    /**
     * Delete logistics company
     */
    public function deleteLogisticsCompany($id): JsonResponse
    {
        $company = LogisticsCompany::find($id);

        if (!$company) {
            return response()->json(['success' => false, 'message' => 'Logistics company not found'], 404);
        }

        if ($company->deliveryAgents()->count() > 0) {
            return response()->json(['success' => false, 'message' => 'Cannot delete company with registered agents.'], 422);
        }

        $company->delete();
        return response()->json(['success' => true, 'message' => 'Logistics company deleted successfully']);
    }

    /**
     * Toggle delivery agent status (cycle: available -> busy -> offline -> available)
     */
    public function toggleDeliveryAgentStatus($id): JsonResponse
    {
        $agent = DeliveryAgent::find($id);

        if (!$agent) {
            return response()->json(['success' => false, 'message' => 'Delivery agent not found'], 404);
        }

        $statusCycle = ['available' => 'busy', 'busy' => 'offline', 'offline' => 'available'];
        $agent->update(['status' => $statusCycle[$agent->status] ?? 'available']);

        return response()->json(['success' => true, 'data' => $agent]);
    }

    /**
     * Resend logistics company login credentials
     */
    public function resendLogisticsCompanyCredentials($id): JsonResponse
    {
        $company = LogisticsCompany::findOrFail($id);

        $newPassword = \Illuminate\Support\Str::random(10);
        $company->update([
            'admin_password' => \Illuminate\Support\Facades\Hash::make($newPassword),
        ]);

        $email = $company->admin_email ?? $company->contact_email;
        $loginUrl = \App\Support\AppUrl::logisticsPortal('/login');

        try {
            \Illuminate\Support\Facades\Mail::to($email)->send(
                new \App\Mail\LogisticsCompanyWelcomeEmail($company->name, $email, $newPassword, $loginUrl)
            );
        } catch (\Exception $e) {
            \Log::error('Failed to resend logistics company credentials: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to send email. Please try again.'], 500);
        }

        return response()->json(['success' => true, 'message' => 'New credentials have been sent to ' . $email]);
    }

    /**
     * Resend delivery agent login credentials
     */
    public function resendDeliveryAgentCredentials($id): JsonResponse
    {
        $agent = DeliveryAgent::findOrFail($id);

        $newPassword = \Illuminate\Support\Str::random(8);
        $agent->update([
            'password' => \Illuminate\Support\Facades\Hash::make($newPassword),
        ]);

        $loginUrl = \App\Support\AppUrl::agentPortal('/login');
        $companyName = $agent->logisticsCompany ? $agent->logisticsCompany->name : 'Taga';

        try {
            \Illuminate\Support\Facades\Mail::to($agent->email)->send(
                new \App\Mail\AgentInvitationEmail($agent->name, $agent->email, $newPassword, $companyName, $loginUrl)
            );
        } catch (\Exception $e) {
            \Log::error('Failed to resend delivery agent credentials: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to send email. Please try again.'], 500);
        }

        return response()->json(['success' => true, 'message' => 'New credentials have been sent to ' . $agent->email]);
    }
}
