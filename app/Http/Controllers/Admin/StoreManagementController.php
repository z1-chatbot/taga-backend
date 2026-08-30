<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StorePayout;
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

        // Filter by status. 'inactive' was the only alternative to 'active'
        // here, which quietly hid every suspended shop from the filter.
        if ($request->has('is_active')) {
            $request->boolean('is_active')
                ? $query->where('status', 'active')
                : $query->whereIn('status', ['inactive', 'suspended']);
        }

        // Archived pharmacies are out of the way but not gone, so there has to
        // be a way to look at them — otherwise an archive is unreviewable and
        // the restore endpoint is unreachable from the UI.
        if ($request->boolean('archived')) {
            $query->onlyTrashed();
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
                'status' => $store->status,
                'is_archived' => $store->trashed(),
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
     * Take a pharmacy off sale.
     *
     * This took the {id} from `/admin/stores/{id}/suspend` — a STORE id — and
     * looked up a USER with it. Different table: suspending store 3 targeted
     * user 3, so it either hit an unrelated pharmacy's owner or 404'd. It also
     * wrote `users.is_active` while the list rendered `stores.status`, so even
     * the accidental hits showed no change and the feature looked absent.
     *
     * Suspending is a property of the shop, so it is `stores.status` that moves.
     * That is the column `Store::canSell()` and `Store::scopeSellable()` read,
     * which is what actually pulls the listings out of the catalogue and stops
     * checkout accepting them.
     *
     * The owner keeps their login on purpose. They cannot sell, but they can see
     * the state of their shop and get in touch — a silent lockout only produces
     * a support ticket asking why nothing works.
     */
    public function suspend($id): JsonResponse
    {
        $store = Store::find($id);

        if (! $store) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found',
            ], 404);
        }

        $store->suspend();

        return response()->json([
            'success' => true,
            'message' => "{$store->name} is suspended and its listings are off sale.",
            'data' => $this->summarise($store->fresh()),
        ]);
    }

    /**
     * Put a suspended pharmacy back on sale.
     *
     * Approval is not re-granted here — an expired or rejected licence still
     * blocks the sale through `canSell()`, which is the point of keeping the
     * two states separate.
     */
    public function activate($id): JsonResponse
    {
        $store = Store::find($id);

        if (! $store) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found',
            ], 404);
        }

        $store->activate();

        return response()->json([
            'success' => true,
            'message' => "{$store->name} is active again.",
            'data' => $this->summarise($store->fresh()),
        ]);
    }

    /**
     * Archive a pharmacy.
     *
     * Deliberately NOT a hard delete. Orders, payouts, commission records and
     * invoices all point at this row, and a pharmacy platform has to be able to
     * produce the history of a dispensed medicine long after the shop has gone.
     * Removing the row would orphan every one of those and take the audit trail
     * with it.
     *
     * `Store` already uses SoftDeletes and the table already carries
     * `deleted_at`, so archiving is what the schema was built for; nothing had
     * ever called it.
     *
     * Suspended first, then archived. The two are independent — `deleted_at`
     * hides the row, `status` decides sellability — and setting both means a
     * restore cannot quietly put an archived shop straight back on sale.
     */
    public function destroy($id): JsonResponse
    {
        $store = Store::find($id);

        if (! $store) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found',
            ], 404);
        }

        $store->suspend();
        $store->delete();

        return response()->json([
            'success' => true,
            'message' => "{$store->name} is archived. Its orders and payout history are kept.",
        ]);
    }

    /**
     * Bring an archived pharmacy back, still suspended.
     *
     * Restoring returns the record, not the shop: an admin has to activate it
     * deliberately, so nothing goes back on sale as a side effect of undoing a
     * mistaken archive.
     */
    public function restore($id): JsonResponse
    {
        $store = Store::withTrashed()->find($id);

        if (! $store) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found',
            ], 404);
        }

        $store->restore();

        return response()->json([
            'success' => true,
            'message' => "{$store->name} is restored, and stays suspended until you activate it.",
            'data' => $this->summarise($store->fresh()),
        ]);
    }

    /**
     * The few fields the list re-reads after an action, so a row can update
     * without refetching the whole page.
     */
    private function summarise(Store $store): array
    {
        return [
            'id' => $store->id,
            'name' => $store->name,
            'status' => $store->status,
            'is_active' => $store->status === 'active',
            'is_archived' => $store->trashed(),
        ];
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
