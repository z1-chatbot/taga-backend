<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeliverySetting;
use App\Models\ShippingRate;
use App\Models\AgentPayout;
use App\Models\DeliveryAgent;
use App\Models\LogisticsCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Mail\PayoutApprovedEmail;

class DeliveryManagementController extends Controller
{
    public function getSettings()
    {
        $settings = DeliverySetting::all()->groupBy('group');
        return response()->json(['success' => true, 'data' => $settings]);
    }

    public function updateSetting(Request $request)
    {
        $request->validate(['key' => 'required|string', 'value' => 'required']);
        $setting = DeliverySetting::setValue($request->key, $request->value);
        return response()->json(['success' => true, 'data' => $setting]);
    }

    public function getShippingRates(Request $request)
    {
        $query = ShippingRate::with(['logisticsCompany', 'deliveryAgent:id,name,phone']);

        // Filter by company if requested
        if ($request->has('logistics_company_id')) {
            if ($request->logistics_company_id === 'global' || $request->logistics_company_id === '') {
                $query->global();
            } else {
                $query->forCompany($request->logistics_company_id);
            }
        }

        // Or by rider, for terms agreed with one courier.
        if ($request->filled('delivery_agent_id')) {
            $query->forAgent($request->delivery_agent_id);
        }

        $rates = $query->orderBy('from_state')->orderBy('to_state')->get();
        return response()->json(['success' => true, 'data' => $rates]);
    }

    /**
     * A rate belongs to one owner: an independent rider, a company, or nobody.
     *
     * "Nobody" is a global rate, which every courier on the route gets. Setting
     * both a rider and a company would make the row unreadable — the lookup
     * prefers the rider, so the company on it would be decoration that looked
     * like a restriction.
     *
     * A rider working under a company cannot have one at all. They have no
     * earnings of their own and cannot request a payout — their company settles
     * with them directly — so a rate in their name would be a number nobody
     * would ever be paid.
     */
    private function rateRules(): array
    {
        return [
            'from_state' => 'required|string',
            'to_state' => 'required|string',
            'base_rate' => 'required|numeric|min:0',
            'per_kg_rate' => 'nullable|numeric|min:0',
            'estimated_days_min' => 'nullable|integer|min:0',
            'estimated_days_max' => 'nullable|integer|min:0',
            'logistics_company_id' => 'nullable|integer|exists:logistics_companies,id|prohibits:delivery_agent_id',
            'delivery_agent_id' => [
                'nullable',
                'integer',
                'exists:delivery_agents,id',
                function ($attribute, $value, $fail) {
                    $agent = \App\Models\DeliveryAgent::find($value);

                    // Checked here rather than left to the admin console: the
                    // console only lists independent riders, but the console is
                    // not the only way to reach this endpoint.
                    if ($agent && ! $agent->isPaidDirectly()) {
                        $company = $agent->logisticsCompany->name ?? 'their logistics company';

                        $fail("{$agent->name} rides for {$company}, who settles with them directly. "
                            ."Set the rate for {$company} instead.");
                    }
                },
            ],
        ];
    }

    private function rateMessages(): array
    {
        return [
            'logistics_company_id.prohibits' => 'A rate belongs either to a logistics company or to one rider, not both.',
        ];
    }

    public function createShippingRate(Request $request)
    {
        $request->validate($this->rateRules(), $this->rateMessages());

        $rate = ShippingRate::create($request->all());

        return response()->json([
            'success' => true,
            'data' => $rate->load(['logisticsCompany', 'deliveryAgent:id,name,phone']),
        ]);
    }

    public function updateShippingRate(Request $request, $id)
    {
        $request->validate($this->rateRules(), $this->rateMessages());

        $rate = ShippingRate::findOrFail($id);
        $rate->update($request->all());

        return response()->json([
            'success' => true,
            'data' => $rate->fresh()->load(['logisticsCompany', 'deliveryAgent:id,name,phone']),
        ]);
    }

    public function deleteShippingRate($id)
    {
        $rate = ShippingRate::findOrFail($id);
        $rate->delete();
        
        return response()->json(['success' => true, 'message' => 'Shipping rate deleted successfully']);
    }

    public function getPendingPayouts()
    {
        $payouts = AgentPayout::with(['deliveryAgent', 'logisticsCompany'])
            ->pending()
            ->latest()
            ->paginate(20);
        return response()->json(['success' => true, 'data' => $payouts]);
    }

    public function approvePayout($payoutId)
    {
        $payout = AgentPayout::findOrFail($payoutId);

        // Approve and immediately complete the payout (admin confirms payment was made)
        $payout->update([
            'status' => 'completed',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'completed_at' => now(),
        ]);

        // The money already left available_balance when the payout was
        // requested, so approving it only records that it has now been sent.
        // This used to decrement pending_balance as well — a column that means
        // "earned but still on hold" and was never credited for a payout, so
        // approving a withdrawal drove it negative and wiped out genuinely held
        // earnings.
        $recipientEmail = null;
        $recipientName = null;
        $recipientType = null;

        if ($payout->delivery_agent_id) {
            $agent = $payout->deliveryAgent;
            if ($agent) {
                $agent->increment('total_paid_out', $payout->amount);
                $recipientEmail = $agent->email;
                $recipientName = $agent->name;
                $recipientType = 'delivery_agent';
            }
        } elseif ($payout->logistics_company_id) {
            $company = $payout->logisticsCompany;
            if ($company) {
                $company->increment('total_paid_out', $payout->amount);
                $recipientEmail = $company->admin_email;
                $recipientName = $company->name;
                $recipientType = 'logistics_company';
            }
        }

        // Send approval email
        if ($recipientEmail) {
            try {
                Mail::to($recipientEmail)->send(new PayoutApprovedEmail($payout, $recipientType, $recipientName));
            } catch (\Exception $e) {
                \Log::error('Failed to send payout approval email: ' . $e->getMessage());
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Payout approved and completed successfully',
            'data' => $payout->fresh(),
        ]);
    }

    /**
     * Decline a payout request and give the money back.
     *
     * Requesting a payout takes the amount out of available_balance so it cannot
     * be claimed twice. There was no way to decline one, so a request an admin
     * would not pay left the courier's money stranded — not withdrawable, not
     * paid, with no route back.
     */
    public function rejectPayout(Request $request, $payoutId)
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        $payout = AgentPayout::findOrFail($payoutId);

        if (! in_array($payout->status, ['pending', 'approved', 'processing'], true)) {
            return response()->json([
                'success' => false,
                'message' => "This payout is already {$payout->status}.",
                'code' => 'payout_not_open',
            ], 422);
        }

        DB::transaction(function () use ($payout, $validated) {
            // The status enum has no 'rejected' — 'cancelled' is its word for a
            // request that will not be paid.
            $payout->update([
                'status' => 'cancelled',
                'notes' => trim(($payout->notes ? $payout->notes."\n" : '')
                    .'['.now()->toDateTimeString().'] Declined: '.$validated['reason']),
                'approved_by' => auth()->id(),
            ]);

            $recipient = $payout->logistics_company_id
                ? $payout->logisticsCompany
                : $payout->deliveryAgent;

            $recipient?->increment('available_balance', $payout->amount);
        });

        return response()->json([
            'success' => true,
            'message' => 'Payout declined and the amount returned to their available balance.',
            'data' => $payout->fresh(),
        ]);
    }

    public function getAgents()
    {
        // Also filter to independent agents only (same as getDeliveryAgents)
        $agents = DeliveryAgent::with('logisticsCompany')
            ->whereNull('logistics_company_id')
            ->paginate(20);
        return response()->json(['success' => true, 'data' => $agents]);
    }

    public function verifyAgent($agentId)
    {
        $agent = DeliveryAgent::findOrFail($agentId);
        $agent->update(['is_verified' => true, 'verified_at' => now()]);
        return response()->json(['success' => true, 'data' => $agent]);
    }

    // Delivery Agents Management (Independent agents only - not under any company)
    public function getDeliveryAgents()
    {
        $agents = DeliveryAgent::with('logisticsCompany')
            ->whereNull('logistics_company_id') // Only independent agents
            ->latest()
            ->get();
        return response()->json(['success' => true, 'data' => $agents]);
    }

    public function createDeliveryAgent(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'phone' => 'required|string',
            'email' => 'required|email|unique:delivery_agents,email',
            'service_areas' => 'required|array',
            'vehicle_type' => 'nullable|string',
            'vehicle_number' => 'nullable|string',
            'license_number' => 'nullable|string',
            'logistics_company_id' => 'nullable|integer|exists:logistics_companies,id',
        ]);

        $companyId = $request->logistics_company_id ?: null;

        // Generate a random default password
        $defaultPassword = \Illuminate\Support\Str::random(8);

        $agent = DeliveryAgent::create([
            'name' => $request->name,
            'phone' => $request->phone,
            'email' => $request->email,
            'service_areas' => $request->service_areas,
            'vehicle_type' => $request->vehicle_type,
            'vehicle_number' => $request->vehicle_number,
            'license_number' => $request->license_number,
            'logistics_company_id' => $companyId,
            'status' => 'available',
            'is_verified' => true,
            'verified_at' => now(),
            'password' => \Illuminate\Support\Facades\Hash::make($defaultPassword),
        ]);

        // Send welcome email with login credentials
        $loginUrl = \App\Support\AppUrl::agentPortal('/login');
        $companyName = $companyId
            ? (\App\Models\LogisticsCompany::find($companyId)->name ?? 'Taga')
            : 'Taga';

        try {
            \Illuminate\Support\Facades\Mail::to($request->email)->send(
                new \App\Mail\AgentInvitationEmail(
                    $request->name,
                    $request->email,
                    $defaultPassword,
                    $companyName,
                    $loginUrl
                )
            );
        } catch (\Exception $e) {
            \Log::error('Failed to send delivery agent welcome email: ' . $e->getMessage());
        }

        return response()->json(['success' => true, 'message' => 'Delivery agent created and welcome email sent.', 'data' => $agent]);
    }

    public function updateDeliveryAgent(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|string',
            'phone' => 'required|string',
            'email' => 'required|email|unique:delivery_agents,email,' . $id,
            'service_areas' => 'required|array',
            'vehicle_type' => 'nullable|string',
            'vehicle_number' => 'nullable|string',
            'license_number' => 'nullable|string',
            'logistics_company_id' => 'nullable|integer|exists:logistics_companies,id',
        ]);

        $agent = DeliveryAgent::findOrFail($id);
        
        $agent->update([
            'name' => $request->name,
            'phone' => $request->phone,
            'email' => $request->email,
            'service_areas' => $request->service_areas,
            'vehicle_type' => $request->vehicle_type,
            'vehicle_number' => $request->vehicle_number,
            'license_number' => $request->license_number,
            'logistics_company_id' => $request->logistics_company_id ?: null,
        ]);

        return response()->json(['success' => true, 'data' => $agent]);
    }

    public function deleteDeliveryAgent($id)
    {
        $agent = DeliveryAgent::findOrFail($id);
        
        // Check if agent has active deliveries
        if ($agent->activeOrders()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete agent with active deliveries'
            ], 422);
        }

        $agent->delete();
        return response()->json(['success' => true, 'message' => 'Delivery agent deleted successfully']);
    }

    public function toggleDeliveryAgentStatus($id)
    {
        $agent = DeliveryAgent::findOrFail($id);
        
        // Cycle through statuses: available -> busy -> offline -> available
        $statusCycle = [
            'available' => 'busy',
            'busy' => 'offline',
            'offline' => 'available',
        ];
        
        $newStatus = $statusCycle[$agent->status] ?? 'available';
        $agent->update(['status' => $newStatus]);

        return response()->json(['success' => true, 'data' => $agent]);
    }

    // Logistics Companies Management
    public function getLogisticsCompanies()
    {
        $companies = LogisticsCompany::latest()->get();
        return response()->json(['success' => true, 'data' => $companies]);
    }

    public function createLogisticsCompany(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'code' => 'required|string|unique:logistics_companies,code',
            'contact_email' => 'required|email',
            'contact_phone' => 'required|string',
            'service_areas' => 'required|array',
        ]);

        // Generate a random default password
        $defaultPassword = \Illuminate\Support\Str::random(10);

        $company = LogisticsCompany::create([
            'name' => $request->name,
            'code' => $request->code,
            'contact_email' => $request->contact_email,
            'contact_phone' => $request->contact_phone,
            'admin_email' => $request->contact_email,
            'admin_password' => \Illuminate\Support\Facades\Hash::make($defaultPassword),
            'service_areas' => $request->service_areas,
            'is_active' => $request->is_active ?? true,
        ]);

        // Send welcome email with login credentials
        $loginUrl = \App\Support\AppUrl::logisticsPortal('/login');

        try {
            \Illuminate\Support\Facades\Mail::to($request->contact_email)->send(
                new \App\Mail\LogisticsCompanyWelcomeEmail(
                    $request->name,
                    $request->contact_email,
                    $defaultPassword,
                    $loginUrl
                )
            );
        } catch (\Exception $e) {
            \Log::error('Failed to send logistics company welcome email: ' . $e->getMessage());
        }

        return response()->json(['success' => true, 'message' => 'Logistics company created and welcome email sent.', 'data' => $company]);
    }

    public function updateLogisticsCompany(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|string',
            'code' => 'required|string|unique:logistics_companies,code,' . $id,
            'contact_email' => 'required|email',
            'contact_phone' => 'required|string',
            'service_areas' => 'required|array',
        ]);

        $company = LogisticsCompany::findOrFail($id);
        $company->update([
            'name' => $request->name,
            'code' => $request->code,
            'contact_email' => $request->contact_email,
            'contact_phone' => $request->contact_phone,
            'service_areas' => $request->service_areas,
            'is_active' => $request->is_active ?? $company->is_active,
        ]);

        return response()->json(['success' => true, 'data' => $company]);
    }

    public function resendLogisticsCompanyCredentials($id)
    {
        $company = LogisticsCompany::findOrFail($id);

        $newPassword = \Illuminate\Support\Str::random(10);
        $loginUrl = \App\Support\AppUrl::logisticsPortal('/login');

        // Send first, save second — see resendDeliveryAgentCredentials below.
        try {
            \Illuminate\Support\Facades\Mail::to($company->admin_email ?? $company->contact_email)->send(
                new \App\Mail\LogisticsCompanyWelcomeEmail(
                    $company->name,
                    $company->admin_email ?? $company->contact_email,
                    $newPassword,
                    $loginUrl
                )
            );
        } catch (\Throwable $e) {
            \Log::error('Failed to resend logistics company credentials: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to send email. Please try again.'], 500);
        }

        $company->update([
            'admin_password' => \Illuminate\Support\Facades\Hash::make($newPassword),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'New credentials have been sent to ' . ($company->admin_email ?? $company->contact_email),
        ]);
    }

    public function resendDeliveryAgentCredentials($id)
    {
        $agent = DeliveryAgent::findOrFail($id);

        $newPassword = \Illuminate\Support\Str::random(8);
        $loginUrl = \App\Support\AppUrl::agentPortal('/login');
        $companyName = $agent->logisticsCompany ? $agent->logisticsCompany->name : 'Taga';

        // Send first, save second. A password is only ever stored hashed, so
        // overwriting it before the email is known to have gone out would lock
        // a working agent out of the portal every time a send failed — the old
        // password destroyed and the new one nowhere.
        try {
            \Illuminate\Support\Facades\Mail::to($agent->email)->send(
                new \App\Mail\AgentInvitationEmail(
                    $agent->name,
                    $agent->email,
                    $newPassword,
                    $companyName,
                    $loginUrl
                )
            );
        } catch (\Throwable $e) {
            \Log::error('Failed to resend delivery agent credentials: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to send email. Please try again.'], 500);
        }

        $agent->update([
            'password' => \Illuminate\Support\Facades\Hash::make($newPassword),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'New credentials have been sent to ' . $agent->email,
        ]);
    }

    public function deleteLogisticsCompany($id)
    {
        $company = LogisticsCompany::findOrFail($id);
        
        // Check if company has agents or active deliveries
        if ($company->deliveryAgents()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete company with registered agents. Please reassign or remove agents first.'
            ], 422);
        }

        $company->delete();
        return response()->json(['success' => true, 'message' => 'Logistics company deleted successfully']);
    }
}
