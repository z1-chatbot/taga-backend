<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConsultationMessage;
use App\Models\ConsultationRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * Consultation requests: the storefront widget, and the admin queue that
 * services them.
 *
 * The customer side is deliberately usable while signed out — the widget floats
 * on every page, and asking someone to make an account before they can ask a
 * question defeats the point. A guest's requests are tied to the storefront
 * guest id, and are claimed onto their account the first time they read the
 * list while signed in.
 *
 * A request is addressed by its `reference` on the customer side and by its id
 * on the admin side, so the customer-facing routes never expose row counts.
 */
class ConsultationController extends Controller
{
    /**
     * The practitioner kinds the widget offers.
     */
    public function practitionerTypes(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ConsultationRequest::practitionerTypeOptions(),
        ]);
    }

    /**
     * Customer: raise a request.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'practitioner_type' => ['required', 'string', Rule::in(array_keys(ConsultationRequest::PRACTITIONER_TYPES))],
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:40',
            'preferred_contact' => ['nullable', Rule::in(['email', 'phone', 'whatsapp'])],
            'preferred_time' => 'nullable|string|max:255',
            'subject' => 'nullable|string|max:255',
            'message' => 'required|string|max:5000',
            'session_id' => 'nullable|string|max:255',
        ]);

        $preferred = $validated['preferred_contact'] ?? 'email';

        // Asking to be called back with no number to call is a dead ticket.
        if (in_array($preferred, ['phone', 'whatsapp'], true) && empty($validated['phone'])) {
            return response()->json([
                'success' => false,
                'message' => 'A phone number is required to be contacted by phone or WhatsApp.',
                'errors' => ['phone' => ['A phone number is required for that contact method.']],
            ], 422);
        }

        $sessionId = $user ? null : $this->sessionId($request);

        if (! $user && ! $sessionId) {
            return response()->json([
                'success' => false,
                'message' => 'A session id is required to raise a request while signed out.',
            ], 422);
        }

        $consultation = ConsultationRequest::create([
            'reference' => ConsultationRequest::generateReference(),
            'user_id' => $user?->id,
            'session_id' => $sessionId,
            'practitioner_type' => $validated['practitioner_type'],
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'preferred_contact' => $preferred,
            'preferred_time' => $validated['preferred_time'] ?? null,
            'subject' => $validated['subject'] ?? null,
            'message' => $validated['message'],
            'status' => ConsultationRequest::STATUS_OPEN,
            'last_reply_at' => now(),
            'last_reply_by' => ConsultationMessage::AUTHOR_CUSTOMER,
        ]);

        // The opening message is part of the thread as well as a column, so the
        // conversation reads in order without the UI special-casing the first
        // entry.
        $consultation->messages()->create([
            'author_type' => ConsultationMessage::AUTHOR_CUSTOMER,
            'user_id' => $user?->id,
            'author_name' => $consultation->name,
            'body' => $consultation->message,
            'is_internal' => false,
        ]);

        $this->sendAcknowledgement($consultation);

        return response()->json([
            'success' => true,
            'message' => "Request received. Quote {$consultation->reference} if you get in touch.",
            'data' => $this->present($consultation->fresh(['publicMessages'])),
        ], 201);
    }

    /**
     * Customer: their own requests, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $sessionId = $this->sessionId($request);

        if ($user) {
            $this->claimGuestRequests($user, $sessionId);
        }

        $query = ConsultationRequest::query()->latest();

        if ($user) {
            $query->where('user_id', $user->id);
        } elseif ($sessionId) {
            $query->whereNull('user_id')->where('session_id', $sessionId);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Authentication or a session id is required.',
            ], 401);
        }

        $requests = $query->paginate($request->integer('per_page', 20))
            ->through(fn (ConsultationRequest $c) => $this->present($c));

        return response()->json(['success' => true, 'data' => $requests]);
    }

    /**
     * Customer: one request and its thread, addressed by reference.
     */
    public function show(Request $request, string $reference): JsonResponse
    {
        $consultation = $this->resolveOwn($request, $reference);

        if (! $consultation) {
            // Same answer for missing and for someone else's, so references
            // cannot be probed.
            return response()->json([
                'success' => false,
                'message' => 'Consultation request not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->present($consultation->load(['publicMessages', 'assignee'])),
        ]);
    }

    /**
     * Customer: add to their own thread.
     */
    public function reply(Request $request, string $reference): JsonResponse
    {
        $consultation = $this->resolveOwn($request, $reference);

        if (! $consultation) {
            return response()->json([
                'success' => false,
                'message' => 'Consultation request not found',
            ], 404);
        }

        if ($consultation->isSettled()) {
            return response()->json([
                'success' => false,
                'message' => 'This request is closed. Please raise a new one.',
            ], 422);
        }

        $validated = $request->validate([
            'body' => 'required|string|max:5000',
        ]);

        $consultation->messages()->create([
            'author_type' => ConsultationMessage::AUTHOR_CUSTOMER,
            'user_id' => $request->user()?->id,
            'author_name' => $consultation->name,
            'body' => $validated['body'],
            'is_internal' => false,
        ]);

        $consultation->update([
            'last_reply_at' => now(),
            'last_reply_by' => ConsultationMessage::AUTHOR_CUSTOMER,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Reply sent.',
            'data' => $this->present($consultation->fresh(['publicMessages', 'assignee'])),
        ]);
    }

    // ---------------------------------------------------------------- admin

    /**
     * Admin: the queue.
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $query = ConsultationRequest::query()->with(['assignee:id,name', 'user:id,name,email']);

        $status = $request->input('status');

        if ($status) {
            if ($status === 'active') {
                $query->openTickets();
            } elseif (in_array($status, ConsultationRequest::STATUSES, true)) {
                $query->where('status', $status);
            }
        }

        if ($type = $request->input('practitioner_type')) {
            $query->where('practitioner_type', $type);
        }

        if ($priority = $request->input('priority')) {
            $query->where('priority', $priority);
        }

        if ($assigned = $request->input('assigned_to')) {
            $query->where('assigned_to', $assigned);
        }

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%")
                    ->orWhere('message', 'like', "%{$search}%");
            });
        }

        // Oldest first while looking at live tickets — that is the person who
        // has been waiting longest. Settled lists read newest first.
        $live = in_array($status ?: 'active', ['active', 'open', 'in_progress', 'scheduled'], true);

        $query->orderBy('created_at', $live ? 'asc' : 'desc');

        $requests = $query->paginate($request->integer('per_page', 20))
            ->through(fn (ConsultationRequest $c) => $this->present($c, true));

        return response()->json(['success' => true, 'data' => $requests]);
    }

    /**
     * Admin: counts for the queue tabs and the sidebar badge.
     */
    public function adminStats(): JsonResponse
    {
        $counts = ConsultationRequest::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $byStatus = collect(ConsultationRequest::STATUSES)
            ->mapWithKeys(fn (string $status) => [$status => (int) ($counts[$status] ?? 0)]);

        return response()->json([
            'success' => true,
            'data' => [
                'by_status' => $byStatus,
                'active' => $byStatus['open'] + $byStatus['in_progress'] + $byStatus['scheduled'],
                // What the badge counts: nobody has answered these yet.
                'awaiting_reply' => ConsultationRequest::query()
                    ->openTickets()
                    ->where('last_reply_by', ConsultationMessage::AUTHOR_CUSTOMER)
                    ->count(),
                'by_practitioner' => ConsultationRequest::query()
                    ->openTickets()
                    ->selectRaw('practitioner_type, count(*) as total')
                    ->groupBy('practitioner_type')
                    ->pluck('total', 'practitioner_type'),
            ],
        ]);
    }

    /**
     * Admin: one request with the full thread, internal notes included.
     */
    public function adminShow($id): JsonResponse
    {
        $consultation = ConsultationRequest::with(['messages.user:id,name', 'assignee:id,name', 'user:id,name,email'])
            ->find($id);

        if (! $consultation) {
            return response()->json([
                'success' => false,
                'message' => 'Consultation request not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->present($consultation, true),
        ]);
    }

    /**
     * Admin: move the ticket on — status, priority, owner, agreed slot.
     */
    public function adminUpdate(Request $request, $id): JsonResponse
    {
        $consultation = ConsultationRequest::find($id);

        if (! $consultation) {
            return response()->json([
                'success' => false,
                'message' => 'Consultation request not found',
            ], 404);
        }

        $validated = $request->validate([
            'status' => ['nullable', Rule::in(ConsultationRequest::STATUSES)],
            'priority' => ['nullable', Rule::in(ConsultationRequest::PRIORITIES)],
            'assigned_to' => 'nullable|exists:users,id',
            'scheduled_at' => 'nullable|date',
        ]);

        // Only what was actually sent: a partial update must not blank the
        // fields it said nothing about.
        $updates = [];

        foreach (['status', 'priority', 'assigned_to', 'scheduled_at'] as $field) {
            if ($request->has($field)) {
                $updates[$field] = $validated[$field] ?? null;
            }
        }

        // A scheduled ticket with no time on it tells nobody anything, and the
        // customer's screen would show the status against a blank slot.
        $becomingScheduled = ($updates['status'] ?? $consultation->status) === ConsultationRequest::STATUS_SCHEDULED;
        $slot = array_key_exists('scheduled_at', $updates) ? $updates['scheduled_at'] : $consultation->scheduled_at;

        if ($becomingScheduled && ! $slot) {
            return response()->json([
                'success' => false,
                'message' => 'Set the appointment time before marking this scheduled.',
                'errors' => ['scheduled_at' => ['An appointment time is required to mark a request scheduled.']],
            ], 422);
        }

        if (array_key_exists('status', $updates)) {
            $settling = in_array(
                $updates['status'],
                [ConsultationRequest::STATUS_RESOLVED, ConsultationRequest::STATUS_CLOSED],
                true
            );

            // Reopening clears the settlement stamp, so "resolved on" never
            // describes a ticket that is open again.
            $updates['resolved_at'] = $settling ? ($consultation->resolved_at ?? now()) : null;
        }

        $consultation->update($updates);

        return response()->json([
            'success' => true,
            'message' => 'Request updated.',
            'data' => $this->present(
                $consultation->fresh(['messages.user:id,name', 'assignee:id,name', 'user:id,name,email']),
                true
            ),
        ]);
    }

    /**
     * Admin: reply to the requester, or leave an internal note.
     *
     * A reply is emailed; a note never is. Answering an untouched ticket moves
     * it to in progress and puts the replier's name on it — the status is there
     * to tell the next person what is happening, and relying on staff to also
     * remember to set it is how queues go stale.
     */
    public function adminReply(Request $request, $id): JsonResponse
    {
        $consultation = ConsultationRequest::find($id);

        if (! $consultation) {
            return response()->json([
                'success' => false,
                'message' => 'Consultation request not found',
            ], 404);
        }

        $validated = $request->validate([
            'body' => 'required|string|max:5000',
            'is_internal' => 'nullable|boolean',
        ]);

        $isInternal = (bool) ($validated['is_internal'] ?? false);
        $staff = $request->user();

        $message = $consultation->messages()->create([
            'author_type' => ConsultationMessage::AUTHOR_ADMIN,
            'user_id' => $staff?->id,
            'author_name' => $staff?->name ?? 'Taga support',
            'body' => $validated['body'],
            'is_internal' => $isInternal,
        ]);

        if (! $isInternal) {
            $updates = [
                'last_reply_at' => now(),
                'last_reply_by' => ConsultationMessage::AUTHOR_ADMIN,
            ];

            if ($consultation->status === ConsultationRequest::STATUS_OPEN) {
                $updates['status'] = ConsultationRequest::STATUS_IN_PROGRESS;
            }

            if (! $consultation->assigned_to && $staff) {
                $updates['assigned_to'] = $staff->id;
            }

            $consultation->update($updates);

            $this->sendReply($consultation, $message);
        }

        return response()->json([
            'success' => true,
            'message' => $isInternal ? 'Note added.' : 'Reply sent.',
            'data' => $this->present(
                $consultation->fresh(['messages.user:id,name', 'assignee:id,name', 'user:id,name,email']),
                true
            ),
        ]);
    }

    // -------------------------------------------------------------- helpers

    /**
     * The storefront's guest id. Sent as a header on every request by the API
     * client; accepted in the body too so a plain form post can carry it.
     */
    private function sessionId(Request $request): ?string
    {
        $sessionId = $request->header('X-Guest-ID') ?: $request->input('session_id');

        return $sessionId ? (string) $sessionId : null;
    }

    /**
     * Resolve a request the caller owns, by reference.
     */
    private function resolveOwn(Request $request, string $reference): ?ConsultationRequest
    {
        $consultation = ConsultationRequest::where('reference', $reference)->first();

        if (! $consultation) {
            return null;
        }

        return $consultation->isOwnedBy($request->user(), $this->sessionId($request))
            ? $consultation
            : null;
    }

    /**
     * Move a guest's requests onto their account once they sign in.
     *
     * The guest id lives in that browser's storage and nowhere else, so this is
     * the same trust the guest cart merge already runs on.
     */
    private function claimGuestRequests(User $user, ?string $sessionId): void
    {
        if (! $sessionId) {
            return;
        }

        ConsultationRequest::whereNull('user_id')
            ->where('session_id', $sessionId)
            ->update(['user_id' => $user->id, 'session_id' => null]);
    }

    /**
     * Shape a request for the API.
     *
     * `$forStaff` is what decides whether internal notes travel. It defaults to
     * false so a customer-facing call site cannot leak them by omission.
     */
    private function present(ConsultationRequest $consultation, bool $forStaff = false): array
    {
        $messages = null;

        if ($consultation->relationLoaded('messages')) {
            $messages = $consultation->messages;
        } elseif ($consultation->relationLoaded('publicMessages')) {
            $messages = $consultation->publicMessages;
        }

        if ($messages !== null && ! $forStaff) {
            $messages = $messages->where('is_internal', false)->values();
        }

        $payload = [
            'id' => $consultation->id,
            'reference' => $consultation->reference,
            'practitioner_type' => $consultation->practitioner_type,
            'practitioner_label' => $consultation->practitionerLabel(),
            'name' => $consultation->name,
            'email' => $consultation->email,
            'phone' => $consultation->phone,
            'preferred_contact' => $consultation->preferred_contact,
            'preferred_time' => $consultation->preferred_time,
            'subject' => $consultation->subject,
            'message' => $consultation->message,
            'status' => $consultation->status,
            'priority' => $consultation->priority,
            'scheduled_at' => $consultation->scheduled_at?->toISOString(),
            'resolved_at' => $consultation->resolved_at?->toISOString(),
            'last_reply_at' => $consultation->last_reply_at?->toISOString(),
            'last_reply_by' => $consultation->last_reply_by,
            'created_at' => $consultation->created_at?->toISOString(),
            'assignee' => $consultation->assignee
                ? ['id' => $consultation->assignee->id, 'name' => $consultation->assignee->name]
                : null,
        ];

        if ($messages !== null) {
            $payload['messages'] = $messages->map(fn (ConsultationMessage $m) => [
                'id' => $m->id,
                'author_type' => $m->author_type,
                'author_name' => $m->author_name,
                'body' => $m->body,
                'is_internal' => $m->is_internal,
                'created_at' => $m->created_at?->toISOString(),
            ])->values();
        }

        if ($forStaff) {
            $payload['customer'] = $consultation->user
                ? [
                    'id' => $consultation->user->id,
                    'name' => $consultation->user->name,
                    'email' => $consultation->user->email,
                ]
                : null;
            $payload['is_guest'] = $consultation->user_id === null;
        }

        return $payload;
    }

    /**
     * Confirm receipt, with the reference to quote.
     *
     * A mail failure must not undo the request itself — the ticket is already
     * in the queue and an admin can still work it.
     */
    private function sendAcknowledgement(ConsultationRequest $consultation): void
    {
        try {
            Mail::to($consultation->email)->send(
                new \App\Mail\ConsultationReceivedEmail($consultation)
            );
        } catch (\Throwable $e) {
            Log::error('Failed to send consultation acknowledgement: '.$e->getMessage(), [
                'consultation_id' => $consultation->id,
            ]);
        }
    }

    private function sendReply(ConsultationRequest $consultation, ConsultationMessage $message): void
    {
        try {
            Mail::to($consultation->email)->send(
                new \App\Mail\ConsultationReplyEmail($consultation, $message)
            );
        } catch (\Throwable $e) {
            Log::error('Failed to send consultation reply email: '.$e->getMessage(), [
                'consultation_id' => $consultation->id,
                'message_id' => $message->id,
            ]);
        }
    }
}
