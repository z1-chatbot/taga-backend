<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Prescription;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Customer prescription uploads and vendor-side review.
 *
 * Review is performed by the selling store for now. Every reviewer action records
 * `reviewed_by_type`, so a platform-pharmacist queue can be added later without
 * changing the schema or reinterpreting existing rows.
 */
class PrescriptionController extends Controller
{
    /**
     * Customer: upload a prescription.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Images or PDFs only, max 10MB — a phone photo of a script is the norm.
            'file' => 'required|file|mimes:jpg,jpeg,png,webp,pdf|max:10240',
            'store_id' => 'nullable|exists:stores,id',
            'order_id' => 'nullable|exists:orders,id',
            'patient_name' => 'nullable|string|max:255',
            'doctor_name' => 'nullable|string|max:255',
            'doctor_license' => 'nullable|string|max:100',
            'hospital_name' => 'nullable|string|max:255',
            'issued_date' => 'nullable|date|before_or_equal:today',
            'expires_at' => 'nullable|date|after:today',
            'notes' => 'nullable|string|max:2000',
        ]);

        $file = $request->file('file');

        // Private disk: prescriptions are medical records and must never be
        // served from a public URL.
        $path = $file->store('prescriptions', 'local');

        $attributes = collect($validated)->except('file')->toArray();

        // Fall back to the configured validity window when the customer did not
        // state an expiry, so a script cannot be reused indefinitely.
        if (empty($attributes['expires_at'])) {
            $validity = \App\Support\PharmacyPolicy::prescriptionValidityDays();

            if ($validity > 0) {
                $issued = ! empty($attributes['issued_date'])
                    ? \Illuminate\Support\Carbon::parse($attributes['issued_date'])
                    : now();

                $attributes['expires_at'] = $issued->copy()->addDays($validity);
            }
        }

        $prescription = Prescription::create(array_merge($attributes, [
            'user_id' => $request->user()?->id,
            'session_id' => $request->user() ? null : $request->input('session_id'),
            'file_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
            'status' => Prescription::STATUS_PENDING,
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Prescription uploaded. The pharmacy will review it shortly.',
            'data' => $this->present($prescription),
        ], 201);
    }

    /**
     * Customer: their own prescriptions.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Prescription::query()->with('store')->latest();

        if ($user) {
            $query->where('user_id', $user->id);
        } elseif ($sessionId = $request->input('session_id')) {
            $query->where('session_id', $sessionId);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Authentication or a session id is required.',
            ], 401);
        }

        return response()->json([
            'success' => true,
            'data' => $query->paginate($request->integer('per_page', 20))
                ->through(fn (Prescription $p) => $this->present($p)),
        ]);
    }

    /**
     * Customer or reviewing store: a single prescription.
     */
    public function show(Request $request, $id): JsonResponse
    {
        $prescription = Prescription::with(['store', 'reviewer'])->find($id);

        if (! $prescription || ! $this->canView($request, $prescription)) {
            // Same response for missing and forbidden, so ids cannot be probed.
            return response()->json([
                'success' => false,
                'message' => 'Prescription not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->present($prescription),
        ]);
    }

    /**
     * Streams the uploaded file to someone entitled to see it.
     *
     * The file lives on the private disk, so this is the only way to read it.
     */
    public function download(Request $request, $id)
    {
        $prescription = Prescription::find($id);

        if (! $prescription || ! $this->canView($request, $prescription)) {
            return response()->json([
                'success' => false,
                'message' => 'Prescription not found',
            ], 404);
        }

        if (! Storage::disk('local')->exists($prescription->file_path)) {
            return response()->json([
                'success' => false,
                'message' => 'Prescription file is missing',
            ], 404);
        }

        return Storage::disk('local')->download(
            $prescription->file_path,
            $prescription->original_filename ?: 'prescription'
        );
    }

    /**
     * Store: the review queue for this vendor.
     */
    public function storeQueue(Request $request): JsonResponse
    {
        $store = $this->resolveStore($request);

        if (! $store) {
            return response()->json([
                'success' => false,
                'message' => 'No store associated with this account.',
            ], 403);
        }

        $query = Prescription::query()
            ->where('store_id', $store->id)
            ->with(['user', 'order'])
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return response()->json([
            'success' => true,
            'data' => $query->paginate($request->integer('per_page', 20))
                ->through(fn (Prescription $p) => $this->present($p, includeCustomer: true)),
        ]);
    }

    /**
     * Platform admin: prescriptions across every store.
     *
     * This deliberately does not reuse storeQueue(), which scopes to the caller's
     * own store — an admin has no store, so that path could only ever 403 here.
     */
    public function adminQueue(Request $request): JsonResponse
    {
        $query = Prescription::query()
            ->with(['user', 'order', 'store'])
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('store_id')) {
            $query->where('store_id', $request->integer('store_id'));
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('patient_name', 'like', "%{$search}%")
                    ->orWhere('doctor_name', 'like', "%{$search}%")
                    ->orWhere('original_filename', 'like', "%{$search}%");
            });
        }

        return response()->json([
            'success' => true,
            'data' => $query->paginate($request->integer('per_page', 20))
                ->through(fn (Prescription $p) => $this->present($p, includeCustomer: true)),
            'counts' => [
                'pending' => Prescription::where('status', Prescription::STATUS_PENDING)->count(),
                'approved' => Prescription::where('status', Prescription::STATUS_APPROVED)->count(),
                'rejected' => Prescription::where('status', Prescription::STATUS_REJECTED)->count(),
            ],
        ]);
    }

    /**
     * Store: approve or reject a prescription.
     */
    public function review(Request $request, $id): JsonResponse
    {
        $validated = $request->validate([
            'action' => 'required|in:approve,reject',
            'reason' => 'required_if:action,reject|nullable|string|max:1000',
            'notes' => 'nullable|string|max:2000',
        ]);

        $store = $this->resolveStore($request);
        $prescription = Prescription::find($id);

        if (! $prescription) {
            return response()->json([
                'success' => false,
                'message' => 'Prescription not found',
            ], 404);
        }

        $isAdmin = $request->user()?->role === 'admin';

        if (! $isAdmin && (! $store || $prescription->store_id !== $store->id)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorised to review this prescription.',
            ], 403);
        }

        // Re-reviewing an already-decided script would silently overwrite an audit
        // trail, so a store reviewer can never flip a decision. A platform admin may
        // overturn one (if policy allows) because genuine mistakes need an escalation
        // path — and that override is recorded rather than applied silently.
        if ($prescription->status !== Prescription::STATUS_PENDING) {
            $canOverride = $isAdmin && \App\Support\PharmacyPolicy::allowAdminPrescriptionOverride();

            if (! $canOverride) {
                return response()->json([
                    'success' => false,
                    'message' => "This prescription has already been {$prescription->status}."
                        . ($isAdmin ? ' Admin override is disabled in settings.' : ''),
                    'code' => 'already_reviewed',
                ], 422);
            }

            if (empty($validated['notes']) && empty($validated['reason'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'An override of an existing decision requires a written reason.',
                    'code' => 'override_reason_required',
                ], 422);
            }
        }

        $reviewer = $request->user();
        $previousStatus = $prescription->status;
        $isOverride = $previousStatus !== Prescription::STATUS_PENDING;

        if ($validated['action'] === 'approve') {
            $prescription->approve($reviewer, $validated['notes'] ?? null);
        } else {
            $prescription->reject($reviewer, $validated['reason']);
        }

        if ($isAdmin) {
            $prescription->update(['reviewed_by_type' => Prescription::REVIEWER_PLATFORM]);
        }

        // Keep the override visible in the record itself, not just in server logs.
        if ($isOverride) {
            $note = sprintf(
                '[%s] Admin override: %s -> %s by %s. Reason: %s',
                now()->toDateTimeString(),
                $previousStatus,
                $prescription->fresh()->status,
                $reviewer?->name ?? 'unknown',
                $validated['notes'] ?? $validated['reason'] ?? 'not stated'
            );

            $prescription->update([
                'notes' => trim(($prescription->notes ? $prescription->notes . "\n" : '') . $note),
            ]);

            \Log::warning('Prescription decision overridden', [
                'prescription_id' => $prescription->id,
                'from' => $previousStatus,
                'to' => $prescription->fresh()->status,
                'admin_id' => $reviewer?->id,
            ]);
        }

        // Roll the decision up to any order this prescription gates.
        $refundDue = false;

        if ($prescription->order_id) {
            $order = $prescription->order()->first();

            $this->refreshOrderPrescriptionStatus($order);

            if ($prescription->fresh()->status === Prescription::STATUS_REJECTED) {
                $refundDue = $this->cancelOrderAfterRejection($order?->fresh(), $validated['reason'] ?? null);
            }
        }

        $decision = $prescription->fresh()->status;

        return response()->json([
            'success' => true,
            'message' => 'Prescription '.$decision.'.'
                .($refundDue ? ' The order has been cancelled and is awaiting refund.' : ''),
            'data' => $this->present($prescription->fresh()),
            'refund_due' => $refundDue,
        ]);
    }

    /**
     * Cancels an order whose prescription was refused, and releases its stock.
     *
     * Under the pay-now flow the customer has already been charged by the time a
     * pharmacist looks at the script, so a rejection has to unwind the order.
     *
     * The money is deliberately not moved here. Returning funds is an
     * irreversible action against the payment gateway, and a store owner
     * rejecting a script should not be able to trigger it. The order is flagged
     * instead, and an admin completes it through
     * POST /payments/{order}/refund.
     *
     * @return bool whether a paid order is now waiting on a refund
     */
    private function cancelOrderAfterRejection(?Order $order, ?string $reason): bool
    {
        if (! $order) {
            return false;
        }

        // Anything already delivered or cancelled is out of scope — a late
        // rejection cannot unpick a completed delivery, and that needs a human.
        if (in_array($order->status, [Order::STATUS_CANCELLED, Order::STATUS_REFUNDED, Order::STATUS_DELIVERED], true)) {
            \Log::warning('Prescription rejected on an order that cannot be auto-cancelled', [
                'order_id' => $order->id,
                'order_status' => $order->status,
            ]);

            return false;
        }

        $note = sprintf(
            '[%s] Cancelled: prescription rejected.%s',
            now()->toDateTimeString(),
            $reason ? ' Reason: '.$reason : ''
        );

        // Stock restoration and releasing the rider happen in Order's updated
        // hook, which covers every cancellation path rather than just this one.
        DB::transaction(function () use ($order, $note) {
            $order->update([
                'status' => Order::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'notes' => trim(($order->notes ? $order->notes."\n" : '').$note),
            ]);
        });

        return $order->fresh()->payment_status === Order::PAYMENT_PAID;
    }

    /**
     * Recomputes an order's prescription_status from its line items.
     *
     * Rejected beats pending beats approved: one rejected script is enough to hold
     * the whole order.
     */
    public function refreshOrderPrescriptionStatus(?Order $order): void
    {
        if (! $order) {
            return;
        }

        $rxItems = $order->items()->where('required_prescription', true)->get();

        if ($rxItems->isEmpty()) {
            $order->update([
                'requires_prescription' => false,
                'prescription_status' => 'not_required',
            ]);

            return;
        }

        $prescriptionIds = $rxItems->pluck('prescription_id')->filter()->unique();
        $prescriptions = Prescription::whereIn('id', $prescriptionIds)->get();

        $status = 'pending';

        if ($prescriptions->contains(fn ($p) => $p->status === Prescription::STATUS_REJECTED)) {
            $status = 'rejected';
        } elseif (
            $rxItems->every(fn ($item) => $item->prescription_id !== null)
            && $prescriptions->every(fn ($p) => $p->isUsable())
        ) {
            $status = 'approved';
        }

        $order->update([
            'requires_prescription' => true,
            'prescription_status' => $status,
        ]);
    }

    /**
     * Owner, reviewing store staff, or an admin.
     */
    private function canView(Request $request, Prescription $prescription): bool
    {
        $user = $request->user();

        if ($user) {
            if ($user->role === 'admin') {
                return true;
            }

            if ($prescription->user_id && $prescription->user_id === $user->id) {
                return true;
            }

            $store = $this->resolveStore($request);

            if ($store && $prescription->store_id === $store->id) {
                return true;
            }
        }

        // Guest uploads are tied to a cart session rather than an account.
        $sessionId = $request->input('session_id');

        return $sessionId && $prescription->session_id === $sessionId;
    }

    private function resolveStore(Request $request): ?Store
    {
        $user = $request->user();

        if (! $user) {
            return null;
        }

        if ($user->store_id) {
            return Store::find($user->store_id);
        }

        return Store::where('owner_id', $user->id)->first();
    }

    /**
     * Never exposes file_path — the file is only reachable via download().
     */
    private function present(Prescription $prescription, bool $includeCustomer = false): array
    {
        $data = [
            'id' => $prescription->id,
            'status' => $prescription->status,
            'original_filename' => $prescription->original_filename,
            'mime_type' => $prescription->mime_type,
            'file_size' => $prescription->file_size,
            'download_url' => "/api/v1/prescriptions/{$prescription->id}/download",
            'patient_name' => $prescription->patient_name,
            'doctor_name' => $prescription->doctor_name,
            'doctor_license' => $prescription->doctor_license,
            'hospital_name' => $prescription->hospital_name,
            'issued_date' => $prescription->issued_date?->toDateString(),
            'expires_at' => $prescription->expires_at?->toDateString(),
            'is_usable' => $prescription->isUsable(),
            'order_id' => $prescription->order_id,
            'store' => $prescription->relationLoaded('store') && $prescription->store
                ? ['id' => $prescription->store->id, 'name' => $prescription->store->name]
                : null,
            'reviewed_at' => $prescription->reviewed_at?->toIso8601String(),
            'reviewed_by_type' => $prescription->reviewed_by_type,
            'rejection_reason' => $prescription->rejection_reason,
            'notes' => $prescription->notes,
            'created_at' => $prescription->created_at?->toIso8601String(),
        ];

        if ($includeCustomer) {
            $data['customer'] = $prescription->relationLoaded('user') && $prescription->user
                ? [
                    'id' => $prescription->user->id,
                    'name' => $prescription->user->name,
                    'email' => $prescription->user->email,
                ]
                : null;
        }

        return $data;
    }
}
