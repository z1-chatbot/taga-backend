<?php
/**
 * One-time script to release ALL pending earnings to available balance.
 * Also sets earnings_hold_period_hours to 0 and clears cache.
 * 
 * Usage: https://api.z1stores.com/release-pending-earnings.php?key=shashhsa_uu72_ssjjasja_82shh4jsjsa
 * 
 * Safe to run multiple times - only affects earnings with status='pending'.
 * DELETE THIS FILE after running it.
 */

$secretKey = 'shashhsa_uu72_ssjjasja_82shh4jsjsa';

if (!isset($_GET['key']) || $_GET['key'] !== $secretKey) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Bootstrap Laravel
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->handle($request = Illuminate\Http\Request::capture());

$results = [];

// Step 1: Set hold period to 0 and clear cache
\App\Models\DeliverySetting::setValue('earnings_hold_period_hours', 0, 'number');
\Illuminate\Support\Facades\Cache::flush();
$results[] = 'Set earnings_hold_period_hours to 0 and cleared all cache.';

// Step 2: Find all pending earnings (regardless of available_at)
$pendingEarnings = \App\Models\AgentEarning::where('status', 'pending')->get();

$released = 0;
foreach ($pendingEarnings as $earning) {
    // Force release
    $earning->update([
        'status' => 'available',
        'available_at' => now(),
    ]);

    // Move from pending to available balance for delivery agent
    if ($earning->delivery_agent_id) {
        $agent = \App\Models\DeliveryAgent::find($earning->delivery_agent_id);
        if ($agent) {
            $agent->decrement('pending_balance', $earning->agent_commission);
            $agent->increment('available_balance', $earning->agent_commission);
            $results[] = "Agent #{$agent->id} ({$agent->name}): +₦{$earning->agent_commission}";
        }
    }

    // Move from pending to available balance for logistics company
    if ($earning->logistics_company_id) {
        $company = \App\Models\LogisticsCompany::find($earning->logistics_company_id);
        if ($company) {
            $company->decrement('pending_balance', $earning->agent_commission);
            $company->increment('available_balance', $earning->agent_commission);
            $results[] = "Company #{$company->id} ({$company->name}): +₦{$earning->agent_commission}";
        }
    }
    $released++;
}

// Step 3: Fix any negative pending_balance (safety net)
$agents = \App\Models\DeliveryAgent::where('pending_balance', '<', 0)->get();
foreach ($agents as $agent) {
    $agent->update(['pending_balance' => 0]);
    $results[] = "Fixed negative pending_balance for Agent #{$agent->id}";
}
$companies = \App\Models\LogisticsCompany::where('pending_balance', '<', 0)->get();
foreach ($companies as $company) {
    $company->update(['pending_balance' => 0]);
    $results[] = "Fixed negative pending_balance for Company #{$company->id}";
}

echo json_encode([
    'success' => true,
    'released' => $released,
    'details' => $results,
], JSON_PRETTY_PRINT);
