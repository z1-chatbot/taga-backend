<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StorePayout;
use App\Models\User;
use App\Models\Product;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;
use App\Mail\PayoutApprovedEmail;
use App\Mail\PayoutRejectedEmail;

class StoreManagementController extends Controller
{
    /**
     * Get all stores
     */
    public function index(Request $request): JsonResponse
    {
        $query = Store::with('owner');

        // Search by name or owner email
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhereHas('owner', function($q2) use ($search) {
                      $q2->where('name', 'like', "%{$search}%")
                         ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        // Filter by status
        if ($request->has('is_active')) {
            $status = $request->boolean('is_active') ? 'active' : 'inactive';
            $query->where('status', $status);
        }

        $stores = $query->get();

        // Transform to include statistics
        $storesData = $stores->map(function ($store) {
            $productsCount = Product::where('store_id', $store->id)->count();
            $ordersCount = Order::whereHas('items', function($q) use ($store) {
                $q->whereHas('product', function($q2) use ($store) {
                    $q2->where('store_id', $store->id);
                });
            })->count();
            
            $totalRevenue = Order::whereHas('items', function($q) use ($store) {
                $q->whereHas('product', function($q2) use ($store) {
                    $q2->where('store_id', $store->id);
                });
            })->where('payment_status', 'paid')->sum('total_amount');
            
            return [
                'id' => $store->id,
                'name' => $store->name,
                'slug' => $store->slug,
                'owner_id' => $store->owner_id,
                'owner' => [
                    'name' => $store->owner->name,
                    'email' => $store->owner->email,
                    'phone' => $store->owner->phone ?? 'N/A',
                ],
                'description' => $store->description,
                'logo' => $store->logo,
                'banner' => $store->banner,
                'is_active' => $store->status === 'active',
                'commission_rate' => $store->commission_rate,
                'total_products' => $productsCount,
                'total_orders' => $ordersCount,
                'total_revenue' => $totalRevenue ?? 0,
                'created_at' => $store->created_at,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $storesData
        ]);
    }

    /**
     * Get store details
     */
    public function show($id): JsonResponse
    {
        $store = Store::with('owner')->find($id);

        if (!$store) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found'
            ], 404);
        }

        // Get store statistics
        $productsCount = Product::where('store_id', $store->id)->count();
        $ordersCount = Order::whereHas('items', function($q) use ($store) {
            $q->whereHas('product', function($q2) use ($store) {
                $q2->where('store_id', $store->id);
            });
        })->count();
        
        $totalRevenue = Order::whereHas('items', function($q) use ($store) {
            $q->whereHas('product', function($q2) use ($store) {
                $q2->where('store_id', $store->id);
            });
        })->where('payment_status', 'paid')->sum('total_amount');

        $storeData = [
            'id' => $store->id,
            'name' => $store->name,
            'slug' => $store->slug,
            'owner_id' => $store->owner_id,
            'owner' => [
                'name' => $store->owner->name,
                'email' => $store->owner->email,
                'phone' => $store->owner->phone ?? 'N/A',
            ],
            'description' => $store->description,
            'phone' => $store->phone,
            'email' => $store->email,
            'address' => $store->address,
            'city' => $store->city,
            'state' => $store->state,
            'logo' => $store->logo,
            'banner' => $store->banner,
            'is_active' => $store->status === 'active',
            'is_verified' => $store->status === 'active',
            'status' => $store->status,
            'commission_rate' => $store->commission_rate,
            'total_products' => $productsCount,
            'total_orders' => $ordersCount,
            'total_revenue' => $totalRevenue ?? 0,
            'created_at' => $store->created_at,
        ];

        return response()->json([
            'success' => true,
            'data' => $storeData
        ]);
    }

    /**
     * Suspend store (deactivate user)
     */
    public function suspend($id): JsonResponse
    {
        $user = User::where('role', 'store_owner')->find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Store owner not found'
            ], 404);
        }

        $user->is_active = false;
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Store suspended successfully',
            'data' => $user
        ]);
    }

    /**
     * Activate store (activate user)
     */
    public function activate($id): JsonResponse
    {
        $user = User::where('role', 'store_owner')->find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Store owner not found'
            ], 404);
        }

        $user->is_active = true;
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Store activated successfully',
            'data' => $user
        ]);
    }

    /**
     * Update store commission rate
     */
    public function updateCommission(Request $request, $id): JsonResponse
    {
        $store = Store::find($id);

        if (!$store) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found'
            ], 404);
        }

        $validated = $request->validate([
            'commission_rate' => 'required|numeric|min:0|max:100'
        ]);

        $store->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Commission rate updated successfully',
            'data' => $store
        ]);
    }

    /**
     * Get store payouts
     */
    public function payouts($id): JsonResponse
    {
        $store = Store::find($id);

        if (!$store) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found'
            ], 404);
        }

        $payouts = $store->payouts()->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $payouts
        ]);
    }

    /**
     * Create payout for store
     */
    public function createPayout(Request $request, $id): JsonResponse
    {
        $store = Store::find($id);

        if (!$store) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found'
            ], 404);
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0',
            'payout_method' => 'nullable|string',
            'payout_details' => 'nullable|array',
            'notes' => 'nullable|string'
        ]);

        $commission = $store->calculateCommission($validated['amount']);
        $netAmount = $validated['amount'] - $commission;

        $payout = StorePayout::create([
            'store_id' => $store->id,
            'amount' => $validated['amount'],
            'commission_deducted' => $commission,
            'net_amount' => $netAmount,
            'payout_method' => $validated['payout_method'] ?? null,
            'payout_details' => $validated['payout_details'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'status' => 'pending'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Payout created successfully',
            'data' => $payout
        ], 201);
    }

    /**
     * Get all payouts across all stores
     */
    public function getAllPayouts(Request $request): JsonResponse
    {
        $query = StorePayout::with(['store']);

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Search by store name
        if ($request->has('search')) {
            $search = $request->search;
            $query->whereHas('store', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });
        }

        // Sort. Whitelisted — an unrecognised sort_by went straight to orderBy()
        // and 500'd the payouts list.
        $allowedColumns = ['created_at', 'updated_at', 'amount', 'status', 'paid_at'];
        $sortBy = $request->get('sort_by', 'created_at');
        $sortBy = in_array($sortBy, $allowedColumns, true) ? $sortBy : 'created_at';
        $sortOrder = strtolower($request->get('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortBy, $sortOrder);

        $payouts = $query->paginate(min(max((int) $request->get('per_page', 15), 1), 100));

        return response()->json([
            'success' => true,
            'data' => $payouts
        ]);
    }

    /**
     * Process payout
     */
    public function processPayout(Request $request, $payoutId): JsonResponse
    {
        $payout = StorePayout::find($payoutId);

        if (!$payout) {
            return response()->json([
                'success' => false,
                'message' => 'Payout not found'
            ], 404);
        }

        $validated = $request->validate([
            'payment_receipt' => 'nullable|string',
            'notes' => 'nullable|string'
        ]);

        $payout->update([
            'status' => 'completed',
            'processed_at' => now(),
            'payment_receipt' => $validated['payment_receipt'] ?? null,
            'notes' => $validated['notes'] ?? null
        ]);

        // Send approval email to store owner
        if ($payout->store && $payout->store->owner) {
            try {
                Mail::to($payout->store->owner->email)->send(
                    new PayoutApprovedEmail($payout, 'store_owner', $payout->store->owner->name)
                );
            } catch (\Exception $e) {
                \Log::error('Failed to send payout approval email: ' . $e->getMessage());
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Payout processed successfully',
            'data' => $payout->fresh()
        ]);
    }

    /**
     * Reject payout
     */
    public function rejectPayout(Request $request, $payoutId): JsonResponse
    {
        $payout = StorePayout::find($payoutId);

        if (!$payout) {
            return response()->json([
                'success' => false,
                'message' => 'Payout not found'
            ], 404);
        }

        $validated = $request->validate([
            'rejection_reason' => 'required|string'
        ]);

        $payout->update([
            'status' => 'failed',
            'rejection_reason' => $validated['rejection_reason']
        ]);

        // Restore balance to store (return the requested amount back to available balance)
        if ($payout->store) {
            // The amount was deducted when payout was requested, so we add it back
            $payout->store->increment('available_balance', $payout->amount);
            
            // Send rejection email to store owner
            if ($payout->store->owner) {
                try {
                    Mail::to($payout->store->owner->email)->send(
                        new PayoutRejectedEmail(
                            $payout, 
                            'store_owner', 
                            $payout->store->owner->name, 
                            $validated['rejection_reason']
                        )
                    );
                } catch (\Exception $e) {
                    \Log::error('Failed to send payout rejection email: ' . $e->getMessage());
                }
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Payout rejected successfully',
            'data' => $payout->fresh()
        ]);
    }
}
