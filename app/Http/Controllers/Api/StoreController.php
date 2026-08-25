<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use App\Mail\PayoutRequestedEmail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class StoreController extends Controller
{
    /**
     * Get all active stores (public)
     */
    public function index(Request $request): JsonResponse
    {
        // Only shops a customer can actually buy from. An unverified pharmacy
        // listed here would open onto an empty shelf, since its stock is not on
        // sale until we approve its licence.
        $query = Store::sellable();

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Pagination
        $perPage = $request->get('per_page', 12);
        $stores = $query->with('owner')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $stores
        ]);
    }

    /**
     * Get store details (public)
     */
    public function show($slug): JsonResponse
    {
        // One answer for a shop that does not exist and one that is not selling.
        // The old version returned a different message for each, and named the
        // store's internal status back to the public.
        $store = Store::where('slug', $slug)
            ->sellable()
            ->with('owner')
            ->first();

        if (! $store) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $store
        ]);
    }

    /**
     * Get store products (public)
     */
    public function products($slug, Request $request): JsonResponse
    {
        $store = Store::where('slug', $slug)->sellable()->first();

        if (!$store) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found'
            ], 404);
        }

        $query = $store->products()->active();

        // Filters — category accepts an id or a slug.
        if ($request->filled('category')) {
            $query->byCategory($request->category);
        }

        if ($request->filled('manufacturer')) {
            $query->where('manufacturer', $request->manufacturer);
        }

        if ($request->has('min_price')) {
            $query->where('price', '>=', $request->min_price);
        }

        if ($request->has('max_price')) {
            $query->where('price', '<=', $request->max_price);
        }

        // Sorting. Whitelisted for the same reason as ProductController@index:
        // an unrecognised sort_by went straight to orderBy() and 500'd.
        $allowedColumns = ['created_at', 'updated_at', 'price', 'name', 'stock_quantity'];
        $sortBy = $request->get('sort_by', 'created_at');
        $sortBy = in_array($sortBy, $allowedColumns, true) ? $sortBy : 'created_at';
        $sortOrder = strtolower($request->get('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortBy, $sortOrder);

        $perPage = min(max((int) $request->get('per_page', 20), 1), 100);
        $products = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $products
        ]);
    }

    /**
     * Get my store (store owner)
     */
    public function myStore(): JsonResponse
    {
        $user = Auth::user();

        if ($user->role !== 'store_owner') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $store = Store::where('owner_id', $user->id)->first();

        if (!$store) {
            return response()->json([
                'success' => false,
                'message' => 'No store found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $store
        ]);
    }

    /*
     * Self-serve store creation used to live here. It made an ACTIVE store and
     * promoted the caller to store_owner on the spot, which meant anyone with a
     * customer login could walk into the dashboard unverified. A pharmacy now
     * applies from the storefront and waits: see StoreApplicationController,
     * where approval is what opens the shop and grants the role.
     */

    /**
     * Update store (store owner)
     */
    public function update(Request $request, $id): JsonResponse
    {
        $user = Auth::user();
        $store = Store::find($id);

        if (!$store || $store->owner_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'state' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'address' => 'nullable|string',
            'business_hours' => 'nullable|array',
            'logo' => 'nullable|image|max:2048',
            'banner' => 'nullable|image|max:5120'
        ]);

        // Handle logo upload
        if ($request->hasFile('logo')) {
            if ($store->logo) {
                Storage::disk('public')->delete($store->logo);
            }
            $validated['logo'] = $request->file('logo')->store('stores/logos', 'public');
        }

        // Handle banner upload
        if ($request->hasFile('banner')) {
            if ($store->banner) {
                Storage::disk('public')->delete($store->banner);
            }
            $validated['banner'] = $request->file('banner')->store('stores/banners', 'public');
        }

        $store->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Store updated successfully',
            'data' => $store
        ]);
    }

    /**
     * Get store analytics (store owner)
     */
    public function analytics($id): JsonResponse
    {
        $user = Auth::user();
        $store = Store::find($id);

        if (!$store || $store->owner_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $analytics = [
            'total_products' => $store->total_products,
            'total_orders' => $store->total_orders,
            'total_revenue' => $store->total_revenue,
            'pending_payout' => $store->pending_payout,
            'recent_orders' => $store->orders()->recent()->limit(10)->get(),
            'top_products' => $store->products()
                ->withCount('orderItems')
                ->orderBy('order_items_count', 'desc')
                ->limit(5)
                ->get()
        ];

        return response()->json([
            'success' => true,
            'data' => $analytics
        ]);
    }

    /**
     * Update current user's store
     */
    public function updateMyStore(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== 'store_owner') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Store owners only.'
            ], 403);
        }

        $store = $user->store;

        if (!$store) {
            return response()->json([
                'success' => false,
                'message' => 'No store found for this user'
            ], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'state' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'logo' => 'nullable|string',  // Can be URL or base64
            'banner' => 'nullable|string',  // Can be URL or base64
        ]);

        // Handle logo (base64 or URL)
        if (isset($validated['logo'])) {
            $validated['logo'] = $this->handleImageUpload($validated['logo'], 'stores/logos');
        }

        // Handle banner (base64 or URL)
        if (isset($validated['banner'])) {
            $validated['banner'] = $this->handleImageUpload($validated['banner'], 'stores/banners');
        }

        $store->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Store updated successfully',
            'data' => $store->fresh()
        ]);
    }

    /**
     * Handle image upload - supports both base64 and URLs
     */
    private function handleImageUpload($imageData, $directory): ?string
    {
        // If it's already a URL, return it as-is
        if (filter_var($imageData, FILTER_VALIDATE_URL)) {
            return $imageData;
        }

        // If it's base64 data, save it to public directory
        if (preg_match('/^data:image\/(\w+);base64,/', $imageData, $type)) {
            // Extract base64 content
            $imageData = substr($imageData, strpos($imageData, ',') + 1);
            $imageData = base64_decode($imageData);

            if ($imageData === false) {
                return null;
            }

            // Generate unique filename
            $extension = strtolower($type[1]);
            $filename = \Illuminate\Support\Str::random(40) . '.' . $extension;
            
            // Create directory if it doesn't exist
            $fullDirectory = public_path($directory);
            if (!file_exists($fullDirectory)) {
                mkdir($fullDirectory, 0775, true);
            }
            
            // Save directly to public directory
            $filepath = $fullDirectory . '/' . $filename;
            file_put_contents($filepath, $imageData);

            // Return the full URL with domain
            return url($directory . '/' . $filename);
        }

        return null;
    }

    /**
     * Get current store owner's store
     */
    public function getMyStore(Request $request): JsonResponse
    {
        $user = $request->user();

        \Log::info('getMyStore called', [
            'user_id' => $user->id,
            'user_role' => $user->role,
            'user_email' => $user->email
        ]);

        if ($user->role !== 'store_owner') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Store owners only. Your role: ' . $user->role
            ], 403);
        }

        // Try to find store by owner_id directly
        $store = Store::where('owner_id', $user->id)->first();

        \Log::info('Store lookup result', [
            'has_store' => $store ? 'yes' : 'no',
            'store_id' => $store ? $store->id : null,
            'store_name' => $store ? $store->name : null,
            'store_status' => $store ? $store->status : null,
        ]);

        if (!$store) {
            // Check if any store exists at all
            $anyStore = Store::first();
            \Log::info('Any store exists?', [
                'exists' => $anyStore ? 'yes' : 'no',
                'total_stores' => Store::count()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'No store found for this user. User ID: ' . $user->id
            ], 404);
        }

        // Get store stats
        // Calculate revenue from delivered orders containing this store's products
        // Revenue = sum of order items for this store's products (excluding shipping, platform markup, etc.)
        $orderItems = \App\Models\OrderItem::with('product')
            ->whereHas('product', function ($q) use ($store) {
                $q->where('store_id', $store->id);
            })
            ->whereHas('order', function ($q) {
                $q->where('status', 'delivered')
                  ->where('payment_status', 'paid');
            })
            ->get();
        
        // Store revenue excludes the platform's markup and is fixed at the price
        // recorded when the order was placed — see OrderItem::store_revenue.
        $totalRevenue = $orderItems->sum(fn ($item) => $item->store_revenue);
        
        // Get unique order count
        $orderIds = \App\Models\OrderItem::whereHas('product', function ($q) use ($store) {
            $q->where('store_id', $store->id);
        })->distinct()->pluck('order_id');
        
        // Calculate paid out amount from store_payouts table
        $paidOutAmount = \App\Models\StorePayout::where('store_id', $store->id)
            ->where('status', 'completed')
            ->sum('amount');
        
        // Calculate pending payout requests (pending and processing)
        $pendingPayouts = \App\Models\StorePayout::where('store_id', $store->id)
            ->whereIn('status', ['pending', 'processing'])
            ->sum('amount');
        
        $stats = [
            'total_products' => \App\Models\Product::where('store_id', $store->id)->count(),
            'total_orders' => $orderIds->count(),
            'total_revenue' => $totalRevenue,
            'pending_balance' => $pendingPayouts, // Amount waiting for admin approval
            'available_balance' => $totalRevenue - $paidOutAmount - $pendingPayouts, // Total revenue minus paid out and pending
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'store' => $store,
                'stats' => $stats
            ]
        ]);
    }

    /**
     * Request payout for store owner
     */
    public function requestPayout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== 'store_owner') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Store owners only.'
            ], 403);
        }

        $store = $user->store;

        if (!$store) {
            return response()->json([
                'success' => false,
                'message' => 'No store found'
            ], 404);
        }

        // Check if bank details are provided
        if (!$store->account_name || !$store->account_number || !$store->bank_name) {
            return response()->json([
                'success' => false,
                'message' => 'Please add your bank account details before requesting a payout'
            ], 422);
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0'
        ]);

        // Check minimum payout amount
        $minPayoutAmount = \App\Models\SystemSetting::getValue(
            \App\Models\SystemSetting::CATEGORY_GENERAL,
            'min_payout_amount',
            10000
        );

        if ($validated['amount'] < $minPayoutAmount) {
            return response()->json([
                'success' => false,
                'message' => "Minimum payout amount is ₦{$minPayoutAmount}"
            ], 422);
        }

        // Calculate available balance
        $orderItems = \App\Models\OrderItem::with('product')
            ->whereHas('product', function ($q) use ($store) {
                $q->where('store_id', $store->id);
            })
            ->whereHas('order', function ($q) {
                $q->where('status', 'delivered')
                  ->where('payment_status', 'paid');
            })
            ->get();
        
        $totalRevenue = $orderItems->sum(fn ($item) => $item->store_revenue);

        // 'paid' is not one of this column's values — the enum is
        // pending/processing/completed/failed — so a completed payout was the
        // only kind ever subtracted, which happened to be right by accident.
        $paidOutAmount = \App\Models\StorePayout::where('store_id', $store->id)
            ->where('status', 'completed')
            ->sum('amount');

        $pendingPayouts = \App\Models\StorePayout::where('store_id', $store->id)
            ->whereIn('status', ['pending', 'processing'])
            ->sum('amount');
        
        $availableBalance = $totalRevenue - $paidOutAmount - $pendingPayouts;

        // Check if requested amount exceeds available balance
        if ($validated['amount'] > $availableBalance) {
            return response()->json([
                'success' => false,
                'message' => "Insufficient balance. Available: ₦" . number_format($availableBalance, 2)
            ], 422);
        }

        // Calculate commission and net amount
        $commission = $store->calculateCommission($validated['amount']);
        $netAmount = $validated['amount'] - $commission;

        // Create payout request
        $payout = \App\Models\StorePayout::create([
            'store_id' => $store->id,
            'amount' => $validated['amount'],
            'commission_deducted' => $commission,
            'net_amount' => $netAmount,
            'status' => 'pending',
            'requested_at' => now(),
        ]);

        // Through AdminAlerts rather than a local lookup: this sent one message
        // addressed to every administrator at once, which put all their
        // addresses in a To header each of them could read, and meant a single
        // bad address took the whole notification down. It now sends one
        // message each and absorbs a failure per recipient.
        \App\Services\AdminAlerts::payoutRequested($payout, 'store_owner', $store->name, $user->email);

        return response()->json([
            'success' => true,
            'message' => 'Payout request submitted successfully',
            'data' => $payout
        ], 201);
    }

    /**
     * Get store owner's payouts
     */
    public function getMyPayouts(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== 'store_owner') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Store owners only.'
            ], 403);
        }

        $store = $user->store;

        if (!$store) {
            return response()->json([
                'success' => false,
                'message' => 'No store found'
            ], 404);
        }

        $payouts = \App\Models\StorePayout::where('store_id', $store->id)
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $payouts
        ]);
    }

    /**
     * Update store bank account details
     */
    public function updateBankDetails(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== 'store_owner') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Store owners only.'
            ], 403);
        }

        $store = $user->store;

        if (!$store) {
            return response()->json([
                'success' => false,
                'message' => 'No store found'
            ], 404);
        }

        $validated = $request->validate([
            'account_name' => 'required|string|max:255',
            'account_number' => 'required|string|max:20',
            'bank_name' => 'required|string|max:255',
            'bank_code' => 'nullable|string|max:10',
        ]);

        $store->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Bank details updated successfully',
            'data' => $store
        ]);
    }
}
