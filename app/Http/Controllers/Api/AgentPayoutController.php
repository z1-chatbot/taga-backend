<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentPayout;
use Illuminate\Http\Request;

/**
 * Earnings and withdrawals for independent dispatch riders.
 *
 * A rider who signed up under a logistics company is that company's employee.
 * The platform pays the company for the whole delivery and the company settles
 * with its riders on its own terms, so this section is closed to them — it used
 * to credit both the rider and the company for the same journey, and both could
 * withdraw against it.
 */
class AgentPayoutController extends Controller
{
    public function getEarnings()
    {
        $agent = auth()->user();

        if (! $agent->isPaidDirectly()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'settled_by_company' => true,
                    'company_name' => $agent->logisticsCompany->name ?? null,
                    'message' => 'Your deliveries are settled with '
                        .($agent->logisticsCompany->name ?? 'your company')
                        .', who pays you directly. Talk to them about your earnings.',
                ],
            ]);
        }

        $earnings = $agent->earnings()->with('order')->latest()->paginate(20);

        $pendingPayouts = AgentPayout::where('delivery_agent_id', $agent->id)
            ->whereIn('status', ['pending', 'approved', 'processing'])
            ->sum('amount');

        $minimumPayout = \App\Models\DeliverySetting::getValue('minimum_payout_amount', 5000);

        return response()->json([
            'success' => true,
            'data' => [
                'settled_by_company' => false,
                'total_earnings' => (float) ($agent->total_earned ?? 0),
                // Cleared and withdrawable now.
                'available_balance' => (float) ($agent->available_balance ?? 0),
                // Earned, but still inside the admin's hold period — not yet
                // withdrawable. Released automatically when the hold elapses.
                'pending_balance' => (float) ($agent->pending_balance ?? 0),
                'pending_payouts' => (float) $pendingPayouts,
                'total_paid_out' => (float) ($agent->total_paid_out ?? 0),
                'minimum_payout' => (float) $minimumPayout,
                'can_request_payout' => $agent->canRequestPayout(),
                'earnings' => $earnings,
            ]
        ]);
    }

    public function requestPayout(Request $request)
    {
        $request->validate(['amount' => 'required|numeric|min:1']);
        $agent = auth()->user();

        try {
            $payout = $agent->requestPayout($request->amount);

            // Riders were the one requester type nobody was told about. Stores
            // and logistics companies both emailed administrators on request;
            // a rider's withdrawal debited their balance and then waited for
            // somebody to notice it in a queue.
            \App\Services\AdminAlerts::payoutRequested(
                $payout,
                'delivery_agent',
                $agent->name ?? 'Rider #'.$agent->id,
                $agent->email
            );

            return response()->json(['success' => true, 'data' => $payout]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function getPayouts()
    {
        $agent = auth()->user();
        $payouts = $agent->payouts()->latest()->paginate(20);
        return response()->json(['success' => true, 'data' => $payouts]);
    }
}
